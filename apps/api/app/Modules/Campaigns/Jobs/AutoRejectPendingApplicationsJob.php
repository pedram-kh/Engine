<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Jobs;

use App\Core\Tenancy\BelongsToAgencyScope;
use App\Modules\Campaigns\Enums\ApplicationRejectionCause;
use App\Modules\Campaigns\Enums\CampaignApplicationStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignApplication;
use App\Modules\Campaigns\Services\CampaignApplicationDecisionService;
use App\Modules\Campaigns\Services\CampaignApplicationNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The campaign-terminal posture for pending applications (AH-058, D5).
 *
 * When a campaign becomes `completed` or `cancelled`, every application still
 * waiting on it is answered — rejected with notice — rather than left pending
 * against a job that can never be filled. Dispatched by
 * `CampaignController::update()` on the not-terminal → terminal edge, in the
 * AH-056 flip-detector's own shape: no domain event, no scheduler.
 *
 * ── Idempotency, which is the whole design ──────────────────────────────────
 *
 * `active → cancelled → active → cancelled` is a sequence an operator can
 * produce by hand in a minute, and the second cancel must send nothing twice.
 * Two guards, both load-bearing:
 *
 *   1. The `status = pending` filter lives INSIDE this job's own read, executed
 *      in the worker — never in the dispatcher. Rows answered by the first run
 *      are simply not in the second run's result set.
 *   2. Terminality is re-checked here against the CURRENT campaign. A campaign
 *      re-opened between the flip and the worker picking this up keeps its
 *      pending applications: the reason to reject them no longer exists.
 *
 * ── ⚠ No ambient tenancy (C5) ───────────────────────────────────────────────
 *
 * A worker has no tenant, so: the campaign's INTEGER KEY travels, not the model
 * (a serialized model re-resolves through {@see BelongsToAgencyScope} and comes
 * back null — `SendJobPostedNotificationsJob`'s trap, verbatim); the scope is
 * dropped explicitly on both re-reads and re-imposed by matching the
 * application's own denormalized `agency_id`; and `Audit::log()` receives an
 * explicit `agencyId` from the row rather than inferring one
 * ({@see CampaignApplicationDecisionService}). The audit rows land with
 * `actor_type = 'system'`, which is the truth: no human pressed reject.
 *
 * ── The flag ────────────────────────────────────────────────────────────────
 *
 * `application_notifications_enabled` is read in the WORKER, at emission time,
 * inside {@see CampaignApplicationNotifier::queue()} — so a job enqueued while
 * the flag was ON and picked up after it was flipped OFF sends no mail (the
 * `VerifyPostedContentJob` defence-in-depth posture). It is deliberately NOT an
 * early return from this job: the rejections and their in-app notices are
 * database TRUTH about a closed campaign, and a mail flag must not decide
 * whether the truth gets written.
 *
 * ── Volume ──────────────────────────────────────────────────────────────────
 *
 * Bounded by the roster (~279 creators at the largest agency today) and read
 * with `cursor()`, one small transaction per row: a mid-loop failure leaves the
 * rows already answered answered, and the retry picks up only what is left.
 */
final class AutoRejectPendingApplicationsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(private readonly int $campaignId) {}

    public function handle(
        CampaignApplicationDecisionService $decisions,
        CampaignApplicationNotifier $notifier,
    ): void {
        $campaign = Campaign::query()
            ->withoutGlobalScope(BelongsToAgencyScope::class)
            ->find($this->campaignId);

        if (! $campaign instanceof Campaign) {
            // Deleted between the flip and the worker. Nothing to answer, and
            // nothing worth failing over.
            return;
        }

        if (! $campaign->status->isTerminal()) {
            return;
        }

        $rejected = 0;

        $applications = CampaignApplication::query()
            ->withoutGlobalScope(BelongsToAgencyScope::class)
            ->where('campaign_applications.agency_id', $campaign->agency_id)
            ->where('campaign_applications.campaign_id', $campaign->id)
            ->where('campaign_applications.status', CampaignApplicationStatus::Pending->value)
            ->with('creator.user')
            ->orderBy('campaign_applications.id')
            ->cursor();

        foreach ($applications as $application) {
            DB::transaction(static function () use ($decisions, $application): void {
                $decisions->reject($application, ApplicationRejectionCause::CampaignClosed);
            });

            // After the row's own commit (C1), and per row rather than per run:
            // the emission for an answered application must not be held hostage
            // by the next row's write failing.
            $notifier->rejected(
                $application->setRelation('campaign', $campaign),
                ApplicationRejectionCause::CampaignClosed,
            );

            $rejected++;
        }

        if ($rejected === 0) {
            return;
        }

        Log::info('jobs-board: pending applications auto-rejected on campaign close', [
            'campaign_id' => $campaign->id,
            'status' => $campaign->status->value,
            'rejected' => $rejected,
        ]);
    }
}
