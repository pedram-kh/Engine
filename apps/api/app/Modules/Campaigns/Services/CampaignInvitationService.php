<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Services;

use App\Modules\Agencies\Models\Agency;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Boards\Listeners\BoardAutomationListener;
use App\Modules\Boards\Listeners\CreateBoardCard;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Events\AssignmentTransitioned;
use App\Modules\Campaigns\Http\Controllers\CampaignAssignmentController;
use App\Modules\Campaigns\Listeners\CreateMessageThread;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Campaigns\ValueObjects\AssignmentOffer;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;

/**
 * THE one place an invitation is born (AH-058 sub-step 1).
 *
 * Extracted verbatim from `CampaignAssignmentController::store()`, whose
 * correction-#1 comment moved here with the code because it is the reason the
 * three `invited` listeners fire at all:
 *
 * > Invite is a CREATE, not a machine transition, so the CALLER hand-writes the
 * > `assignment.invited` audit row + dispatches the event (the machine never
 * > sees the create). The event carries from=to=invited (no prior state) so the
 * > board listener can create the card off `eventKey()`.
 *
 * That is the whole reason this is a service and not three inline statements: as
 * of chunk 4 there are TWO ways an assignment is created — a direct invite and
 * accepting a job application — and an invitation created without the audit row
 * or without the event would be an assignment with no board card, no message
 * thread, and no history. Silently. The listeners are
 * {@see CreateMessageThread},
 * {@see CreateBoardCard} and
 * {@see BoardAutomationListener}, in a locked
 * registration order (CampaignsServiceProvider).
 *
 * ⚠ What this service deliberately does NOT do:
 *   - **No authorization, no gates.** The caller runs `Gate::authorize()`, the
 *     blacklist gate and (where it applies) the availability gate BEFORE calling
 *     in. Two callers with different gate sets is the whole reason the gates stay
 *     out here — accept drops the `is_discoverable` leg the direct invite keeps
 *     (AH-051's ruling: a browsing preference is not an eligibility gate).
 *   - **No transaction.** The caller owns the transaction boundary, because the
 *     interesting boundary is wider than the create: accept also flips a
 *     `campaign_applications` row, and `store()` may also settle a pending
 *     application (D3b). A transaction started here would be the wrong size.
 *   - **No notification.** Emissions happen AFTER the caller's transaction
 *     commits — `config/queue.php` sets `after_commit => false`, so mail queued
 *     inside a transaction is visible to a worker before the commit and would
 *     survive a rollback. See the accept controller for the ordering.
 *   - **No idempotency check.** The unique `(campaign_id, creator_id)` pair is
 *     the guard; the callers each decide what an existing row MEANS (the direct
 *     invite returns it as-is or re-offers a declined one; accept refuses with
 *     `application.already_engaged`).
 *
 * @see CampaignAssignmentController::store() the direct-invite caller
 */
final class CampaignInvitationService
{
    /**
     * Create an `invited` assignment, write its audit row, dispatch its event.
     *
     * `invited_by_user_id` is the acting user — the inviter on the direct path,
     * and the agency user who accepted the application on the accept path, which
     * is the honest attribution in both cases.
     */
    public function invite(
        Agency $agency,
        Campaign $campaign,
        Creator $creator,
        AssignmentOffer $offer,
        User $actor,
    ): CampaignAssignment {
        $assignment = CampaignAssignment::query()->create([
            'agency_id' => $agency->id,
            'campaign_id' => $campaign->id,
            'brand_id' => $campaign->brand_id,
            'creator_id' => $creator->id,
            'status' => AssignmentStatus::Invited,
            'invited_at' => now(),
            'invited_by_user_id' => $actor->id,
            'agreed_fee_minor_units' => $offer->agreedFeeMinorUnits,
            'agreed_fee_currency' => $offer->agreedFeeCurrency,
            // Invite-offer-details batch — free-text offer context + the one
            // optional attachment (verified against the campaign prefix by the
            // caller, before we get here).
            'fee_per' => $offer->feePer,
            'offer_description' => $offer->offerDescription,
            'offer_attachment_path' => $offer->attachmentPath,
            'offer_attachment_name' => $offer->attachmentName,
            'offer_attachment_mime' => $offer->attachmentMime,
            'offer_attachment_size_bytes' => $offer->attachmentSizeBytes,
            'deliverables' => $offer->deliverables,
            // AH-069 (D8/Q5b) — a campaign that hands off at approval has no
            // posting step, so it gets no posting deadline. Dropping it at the
            // WRITER is the durable half of the fix: the overdue sweep's own
            // exclusion (OverdueScanService) covers assignments invited before
            // the toggle was turned off, but a deadline that was never stamped
            // cannot be missed by any future reader either.
            'posting_due_at' => $campaign->creator_posts_content ? $offer->postingDueAt : null,
            // Sprint 12 Chunk 3 (D-2) — mirror of posting_due_at; nullable.
            'draft_due_at' => $offer->draftDueAt,
        ]);

        Audit::log(
            action: AuditAction::AssignmentInvited,
            subject: $assignment,
            metadata: [
                'from' => null,
                'to' => AssignmentStatus::Invited->value,
                'agreed_fee_minor_units' => $assignment->agreed_fee_minor_units,
                'agreed_fee_currency' => $assignment->agreed_fee_currency,
            ],
        );

        AssignmentTransitioned::dispatch(
            $assignment,
            AssignmentStatus::Invited,
            AssignmentStatus::Invited,
            AuditAction::AssignmentInvited,
            $actor->id,
        );

        return $assignment;
    }
}
