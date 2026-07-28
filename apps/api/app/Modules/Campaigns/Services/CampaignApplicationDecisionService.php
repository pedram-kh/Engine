<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Services;

use App\Core\Tenancy\BelongsToAgencyScope;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Campaigns\Enums\ApplicationRejectionCause;
use App\Modules\Campaigns\Enums\CampaignApplicationStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignApplication;
use App\Modules\Creators\Models\Creator;

/**
 * The two answers to an application — accepted and rejected — written in ONE
 * place (AH-058, D2/D4/D5 + the D3b convergence hook).
 *
 * ── Why a service and not two private controller methods ────────────────────
 *
 * There are FOUR write sites for these two verbs: the agency's accept, the
 * agency's reject, the campaign-terminal auto-reject job, and the direct-invite
 * convergence hook (D3b) in `CampaignAssignmentController::store()`. A flip
 * duplicated across four sites is a flip that will disagree with itself — one
 * site stamps `responded_at`, another forgets the audit row, a third infers the
 * agency from ambient tenancy and writes `null` from a queued worker.
 *
 * ── ⚠ EVERY METHOD HERE MUST RUN INSIDE THE CALLER'S TRANSACTION ────────────
 *
 * These are half-facts on their own. An application marked `accepted` whose
 * assignment write then fails is the torn state review priority 1 exists to
 * prove impossible, so the caller owns the transaction and this service never
 * opens its own — nesting would silently downgrade the outer rollback to a
 * savepoint on MySQL.
 *
 * And nothing here notifies. The dual-emit belongs AFTER the caller's commit
 * ({@see CampaignApplicationNotifier}'s docblock has the `after_commit => false`
 * reasoning); a service that both wrote and mailed would put the mail inside the
 * transaction by construction.
 */
final class CampaignApplicationDecisionService
{
    /**
     * Mark an application `accepted`. The caller is responsible for the
     * assignment that makes the acceptance true.
     */
    public function accept(CampaignApplication $application): void
    {
        $application->status = CampaignApplicationStatus::Accepted;
        $application->responded_at = now();
        $application->save();

        Audit::log(
            action: AuditAction::CampaignApplicationAccepted,
            subject: $application,
            metadata: [
                'campaign_id' => $application->campaign_id,
                'creator_id' => $application->creator_id,
            ],
            // Explicit, never inferred from ambient tenancy: the auto-reject
            // sibling below runs in a worker with no tenant, and an audit row
            // with a null agency_id is a row no agency admin will ever see.
            agencyId: $application->agency_id,
        );
    }

    /**
     * Mark an application `rejected`. The cause rides in the audit metadata
     * because the row is otherwise identical for both, and "who or what closed
     * this" is the only question a reader of the log will have.
     */
    public function reject(CampaignApplication $application, ApplicationRejectionCause $cause): void
    {
        $application->status = CampaignApplicationStatus::Rejected;
        $application->responded_at = now();
        $application->save();

        Audit::log(
            action: AuditAction::CampaignApplicationRejected,
            subject: $application,
            metadata: [
                'campaign_id' => $application->campaign_id,
                'creator_id' => $application->creator_id,
                'cause' => $cause->value,
            ],
            agencyId: $application->agency_id,
        );
    }

    /**
     * D3b — the invite-path convergence hook.
     *
     * When an agency invites a creator DIRECTLY and that creator already has a
     * pending application on the campaign, the application is answered by the
     * invitation: the agency did the thing the application was asking for. Left
     * alone, the row would sit `pending` forever — visible in the tab, counted in
     * the badge, un-actionable (accept would 422 `already_engaged`), and telling
     * the creator nothing.
     *
     * Called from BOTH branches of `store()` that put an offer in front of a
     * creator — the fresh create and the AH-035 declined re-offer. The re-offer
     * branch is the one that would have been missed by a hook wired only into the
     * create: a creator who declined, then applied later, then got re-offered is
     * exactly the pair whose application must settle.
     *
     * Returns the settled application so the caller can emit AFTER its commit,
     * or null when there was nothing to settle — which is the overwhelmingly
     * common case, and the case §5.34 pins as byte-identical to before this hook
     * existed.
     */
    public function settlePendingApplication(Campaign $campaign, Creator $creator): ?CampaignApplication
    {
        $application = CampaignApplication::query()
            // Explicit rather than ambient: the agency comes from the campaign
            // being invited on, matched against the application's own
            // denormalized agency_id, so dropping the scope cannot reach across
            // tenants and the hook behaves the same from any context.
            ->withoutGlobalScope(BelongsToAgencyScope::class)
            ->where('campaign_applications.agency_id', $campaign->agency_id)
            ->where('campaign_applications.campaign_id', $campaign->id)
            ->where('campaign_applications.creator_id', $creator->id)
            ->where('campaign_applications.status', CampaignApplicationStatus::Pending->value)
            ->first();

        if (! $application instanceof CampaignApplication) {
            return null;
        }

        $this->accept($application);

        return $application;
    }
}
