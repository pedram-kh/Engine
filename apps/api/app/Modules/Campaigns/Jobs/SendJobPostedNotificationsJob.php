<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Jobs;

use App\Core\Tenancy\BelongsToAgencyScope;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Services\JobPostedFanOutService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Fans the job-posted notification out to a listed campaign's reachable roster
 * (AH-056, D6). Dispatched by `CampaignController::update()` on the
 * `false → true` listing flip — never on a schedule, because the production
 * scheduler's existence is unverified.
 *
 * ── Queue posture (Q3), stated rather than configured ───────────────────────
 *
 * Default connection, default queue, framework-default retries, no bespoke
 * backoff. Each of those is a decision:
 *
 *   - **Default queue.** This shares a queue with every other job on the
 *     platform, so a 50-recipient fan-out can delay a verification job behind
 *     it. At current volume that is seconds. A dedicated queue is the right fix
 *     at the volume where it stops being seconds, and it is logged in
 *     `docs/tech-debt.md` as volume-triggered rather than pre-built.
 *   - **Framework-default retries.** The fan-out is idempotent at recipient
 *     granularity — a retry re-runs the loop and the stamp skips everyone
 *     already handled — so a retry is safe and cannot double-send. Tuning
 *     `$tries` / `$backoff` without evidence would be guessing.
 *
 * The service is passed the DEFAULT cap. The remainder of a capped roster is
 * drained deliberately by an operator with
 * `campaigns:preview-job-notifications`, not automatically by a re-dispatch:
 * an automatic drain would turn a mis-set cap into a loop that keeps emailing.
 *
 * ⚠ The job carries the campaign's integer key, not the model. A serialized
 * model would be re-resolved through the {@see BelongsToAgencyScope} global
 * scope inside a worker that has no tenant context, and the campaign would come
 * back null. The scope is dropped explicitly on re-read here, which is also why
 * this is one of the few places the bypass appears outside a controller.
 */
final class SendJobPostedNotificationsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(private readonly int $campaignId) {}

    public function handle(JobPostedFanOutService $fanOut): void
    {
        $campaign = Campaign::query()
            ->withoutGlobalScope(BelongsToAgencyScope::class)
            ->find($this->campaignId);

        if (! $campaign instanceof Campaign) {
            // The campaign was deleted between the flip and the worker picking
            // this up. Nothing to do, and nothing worth failing over.
            return;
        }

        $report = $fanOut->send($campaign);

        // Logged rather than audited: the fan-out writes no audit row (the
        // stamp IS the send record — the AH-048 posture), but an operator
        // reading worker logs after a first enable needs to see what a capped
        // run left behind.
        Log::info('jobs-board: job-posted fan-out completed', [
            'campaign_id' => $campaign->id,
            'enabled' => $report->enabled,
            'notified' => $report->notified,
            'remaining' => $report->remaining,
        ]);
    }
}
