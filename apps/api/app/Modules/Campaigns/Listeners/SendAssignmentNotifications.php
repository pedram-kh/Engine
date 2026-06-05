<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Listeners;

use App\Modules\Agencies\Mail\ConnectionRequestMail;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Campaigns\Events\AssignmentTransitioned;
use App\Modules\Campaigns\Jobs\VerifyPostedContentJob;
use App\Modules\Campaigns\Mail\ContractAcceptedMail;
use App\Modules\Campaigns\Mail\DraftReviewedMail;
use App\Modules\Campaigns\Mail\DraftSubmittedForReviewMail;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Campaigns\Models\CampaignDraft;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Mail;

/**
 * The 3rd consumer of {@see AssignmentTransitioned} (Sprint 9 Chunk 2, D-14) —
 * the review notification set. Acts only on the review-lifecycle verbs:
 *
 *   - `assignment.draft_submitted`     → notify the AGENCY (the inviting member)
 *   - `assignment.draft_approved`      → notify the CREATOR (approved)
 *   - `assignment.revision_requested`  → notify the CREATOR (changes requested)
 *   - `assignment.draft_rejected`      → notify the CREATOR (rejected)
 *
 * Queued mailables, localized at queue time to the recipient's preferred
 * language (the {@see ConnectionRequestMail} pattern).
 * The verification-failed agency notification (D-13/D-14) is NOT a transition —
 * it is sent directly by {@see VerifyPostedContentJob}.
 *
 * Draft-submitted notification lives here (not in Chunk 1's creator submit
 * endpoint) so Chunk 1 stays untouched — it is review-adjacent and belongs to
 * the review chunk (D-14).
 */
final class SendAssignmentNotifications
{
    public function handle(AssignmentTransitioned $event): void
    {
        $assignment = $event->assignment;

        match ($event->action) {
            AuditAction::AssignmentDraftSubmitted => $this->notifyAgencyOfSubmission($assignment),
            AuditAction::AssignmentDraftApproved => $this->notifyCreatorOfReview($assignment, 'approved'),
            AuditAction::AssignmentRevisionRequested => $this->notifyCreatorOfReview($assignment, 'revision_requested'),
            AuditAction::AssignmentDraftRejected => $this->notifyCreatorOfReview($assignment, 'rejected'),
            AuditAction::AssignmentContracted => $this->notifyAgencyOfContractAcceptance($assignment),
            default => null,
        };
    }

    private function notifyAgencyOfSubmission(CampaignAssignment $assignment): void
    {
        $recipient = $assignment->invitedBy;
        $campaign = $assignment->campaign;
        $creator = $assignment->creator;

        if (! $recipient instanceof User || $recipient->email === '' || $campaign === null || $creator === null) {
            return;
        }

        Mail::to($recipient->email)
            ->locale($recipient->preferred_language ?: 'en')
            ->queue(new DraftSubmittedForReviewMail(
                recipientName: $recipient->name,
                creatorName: $creator->display_name ?? '',
                campaignName: $campaign->name,
                campaignUlid: $campaign->ulid,
            ));
    }

    /**
     * @param  'approved'|'revision_requested'|'rejected'  $outcome
     */
    private function notifyCreatorOfReview(CampaignAssignment $assignment, string $outcome): void
    {
        $creator = $assignment->creator;
        $campaign = $assignment->campaign;

        if ($creator === null || $campaign === null) {
            return;
        }

        $recipient = $creator->user;
        if (! $recipient instanceof User || $recipient->email === '') {
            return;
        }

        // The reviewer feedback (revision / reject) is persisted on the draft's
        // review trail by the controller in the same transaction (before the
        // machine drove the transition), so the latest draft already carries it
        // here. Approvals carry none.
        $feedback = null;
        if ($outcome !== 'approved') {
            $feedback = CampaignDraft::query()
                ->where('assignment_id', $assignment->id)
                ->orderByDesc('version')
                ->value('review_feedback');
        }

        Mail::to($recipient->email)
            ->locale($recipient->preferred_language ?: 'en')
            ->queue(new DraftReviewedMail(
                creatorName: $creator->display_name ?? $recipient->name,
                campaignName: $campaign->name,
                outcome: $outcome,
                feedback: is_string($feedback) && $feedback !== '' ? $feedback : null,
                assignmentUlid: $assignment->ulid,
            ));
    }

    private function notifyAgencyOfContractAcceptance(CampaignAssignment $assignment): void
    {
        $recipient = $assignment->invitedBy;
        $campaign = $assignment->campaign;
        $creator = $assignment->creator;

        if (! $recipient instanceof User || $recipient->email === '' || $campaign === null || $creator === null) {
            return;
        }

        Mail::to($recipient->email)
            ->locale($recipient->preferred_language ?: 'en')
            ->queue(new ContractAcceptedMail(
                recipientName: $recipient->name,
                creatorName: $creator->display_name ?? '',
                campaignName: $campaign->name,
                campaignUlid: $campaign->ulid,
            ));
    }
}
