<?php

declare(strict_types=1);

namespace App\Modules\Admin\Jobs;

use App\Core\Tenancy\BelongsToAgencyScope;
use App\Modules\Admin\Enums\AutomationAction;
use App\Modules\Admin\Models\AdminAutomationRule;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Campaigns\Services\CampaignApplicationNotifier;
use App\Modules\Creators\Enums\DirectConnectOutcome;
use App\Modules\Creators\Features\PlatformAutomationsEnabled;
use App\Modules\Creators\Http\Controllers\Admin\AdminCreatorController;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Services\CreatorAgencyDirectConnectService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Laravel\Pennant\Feature;

/**
 * One rule, one subject, one fire (AH-090, D5).
 *
 * Dispatched by {@see AdminCreatorController::approve()}
 * — one job per enabled rule watching `creator.approved` — AFTER that method's
 * transaction has committed. Every queue connection is configured
 * `after_commit => false`, so an in-transaction dispatch would let a worker read
 * a creator who is not approved yet and skip for a reason that isn't true. The
 * dispatch site sits with the existing mail/in-app emissions, per
 * `CampaignController::update()`'s comment on the same trap.
 *
 * ── Nothing is trusted from dispatch time ───────────────────────────────────
 *
 * IDs travel, never models (a serialized model re-resolves through
 * {@see BelongsToAgencyScope} in a tenant-less worker and comes back null — the
 * documented trap). And every condition is re-read HERE, because minutes can
 * pass between an approval and this handler:
 *
 *   1. the flag — a kill switch flipped during those minutes must stop a job
 *      that is already queued;
 *   2. the rule still exists and is still enabled;
 *   3. the creator still exists and is still approved (a reopen between
 *      approval and fire means the reason to connect is gone);
 *   4. the target agency still exists, is not soft-deleted, and is not
 *      suspended;
 *   5. the pair's own state — blacklist, already-roster, deliberately-terminal
 *      — which the connect service owns and re-reads inside the agency's
 *      tenancy.
 *
 * Deleting any one of those checks is a break-revert seam with a test that
 * reddens (§5.35).
 *
 * ── Silence is never an outcome ─────────────────────────────────────────────
 *
 * Every invocation writes EXACTLY ONE structured line naming what happened —
 * `connected` or `skipped:<reason>` — mirroring
 * {@see CampaignApplicationNotifier}'s
 * logEmission. An operator asking "did my rule do anything?" must never have to
 * infer the answer from the absence of a log line.
 *
 * ── Provenance ─────────────────────────────────────────────────────────────
 *
 * No human fired this, and the rows say so: `actor: null` reaches the connect
 * service, which writes the audit row as `system` and leaves
 * `invited_by_user_id` null. The rule is named in the audit reason and its id in
 * the metadata, so "what has rule #3 ever done" is answerable from the audit log
 * alone.
 */
final class RunAutomationRuleJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly int $creatorId,
        private readonly int $ruleId,
    ) {}

    public function handle(CreatorAgencyDirectConnectService $directConnect): void
    {
        if (! Feature::active(PlatformAutomationsEnabled::NAME)) {
            $this->log('skipped:feature_disabled');

            return;
        }

        $rule = AdminAutomationRule::query()->find($this->ruleId);

        if (! $rule instanceof AdminAutomationRule) {
            $this->log('skipped:rule_missing');

            return;
        }

        if (! $rule->enabled) {
            $this->log('skipped:rule_disabled', $rule);

            return;
        }

        $creator = Creator::query()->find($this->creatorId);

        if (! $creator instanceof Creator) {
            $this->log('skipped:creator_missing', $rule);

            return;
        }

        match ($rule->action) {
            AutomationAction::ConnectToAgency => $this->connectToAgency($rule, $creator, $directConnect),
        };
    }

    /**
     * The `connect_to_agency` action. The creator-approved and pair-state checks
     * belong to the connect service — it is the one place that knows them, and
     * duplicating them here is how the two copies drift.
     */
    private function connectToAgency(
        AdminAutomationRule $rule,
        Creator $creator,
        CreatorAgencyDirectConnectService $directConnect,
    ): void {
        $agencyUlid = $rule->targetAgencyUlid();

        if ($agencyUlid === null) {
            // A rule whose config names no agency cannot act. Not an exception:
            // the operator's next step is to fix the rule, and a failed job with
            // retries would say the same thing three times.
            $this->log('skipped:rule_misconfigured', $rule, $creator);

            return;
        }

        $agency = Agency::query()->where('ulid', $agencyUlid)->first();

        if (! $agency instanceof Agency) {
            // Broken-but-present: the agency was deleted after the rule was
            // written. The JSON config carries no FK, so this check is the
            // integrity (AH-090, C10).
            $this->log('skipped:agency_missing', $rule, $creator);

            return;
        }

        $result = $directConnect->connect(
            creator: $creator,
            agency: $agency,
            actor: null,
            reason: $rule->firedReason(),
            // Q3 — a rule must never reverse a deliberate admin termination or a
            // creator's own refusal of this agency. The human path still
            // elevates those, because a human choosing IS the consent the
            // elevation encodes; the asymmetry is the point.
            skipTerminalRelations: true,
            // Q4 — provenance. `actor_type: system` says no human did this; the
            // rule ULID is what makes "what did THIS rule ever do" a query
            // rather than a prose search through reason strings.
            firedByRuleUlid: $rule->ulid,
        );

        if ($result->outcome === DirectConnectOutcome::Connected) {
            $rule->recordFire();
        }

        $this->log($result->outcome->logOutcome(), $rule, $creator, $agency, $result->outcome);
    }

    /**
     * The one line per fire. Success and skip use the same call so a new outcome
     * cannot ship with an HTTP path but no operator-visible trace.
     */
    private function log(
        string $outcome,
        ?AdminAutomationRule $rule = null,
        ?Creator $creator = null,
        ?Agency $agency = null,
        ?DirectConnectOutcome $connectOutcome = null,
    ): void {
        Log::info('platform-automations: rule fired', [
            'outcome' => $outcome,
            'rule_id' => $this->ruleId,
            'rule_name' => $rule?->name,
            'event' => $rule?->event->value,
            'action' => $rule?->action->value,
            'creator_id' => $this->creatorId,
            'creator_ulid' => $creator?->ulid,
            'agency_id' => $agency?->ulid,
            // Present only when the connect service was actually reached, so
            // "the job refused" and "the connect refused" stay distinguishable.
            'connect_outcome' => $connectOutcome?->value,
            'fire_count' => $rule?->fire_count,
        ]);
    }
}
