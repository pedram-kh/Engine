<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Listeners;

use App\Modules\Agencies\Mail\ConnectionRequestMail;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Campaigns\Events\AssignmentTransitioned;
use App\Modules\Campaigns\Jobs\VerifyPostedContentJob;
use App\Modules\Campaigns\Mail\ContractAcceptedMail;
use App\Modules\Campaigns\Mail\DraftReviewedMail;
use App\Modules\Campaigns\Mail\DraftSubmittedForReviewMail;
use App\Modules\Campaigns\Mail\PostManuallyVerifiedMail;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Campaigns\Models\CampaignDraft;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Services\NotificationService;
use Illuminate\Support\Facades\Mail;

/**
 * The 3rd consumer of {@see AssignmentTransitioned} (Sprint 9 Chunk 2, D-14) —
 * the review notification set. Acts only on the review-lifecycle verbs:
 *
 *   - `assignment.draft_submitted`     → notify the AGENCY (the inviting member)
 *   - `assignment.draft_approved`      → notify the CREATOR (approved)
 *   - `assignment.revision_requested`  → notify the CREATOR (changes requested)
 *   - `assignment.draft_rejected`      → notify the CREATOR (rejected)
 *   - `assignment.manually_verified`   → notify the CREATOR (post accepted, D-8)
 *
 * Queued mailables, localized at queue time to the recipient's preferred
 * language (the {@see ConnectionRequestMail} pattern).
 * The verification-failed agency notification (D-13/D-14) is NOT a transition —
 * it is sent directly by {@see VerifyPostedContentJob}. The resubmit-requested
 * creator notifications (ACT2/ACT3) are likewise sent directly by the
 * resolution endpoint (the free-text feedback must not ride the audit
 * snapshot), so only the manual-verify acceptance is wired here.
 *
 * Draft-submitted notification lives here (not in Chunk 1's creator submit
 * endpoint) so Chunk 1 stays untouched — it is review-adjacent and belongs to
 * the review chunk (D-14).
 *
 * S11.0 Chunk 1 (D-10): the draft-reviewed → creator path emits an IN-APP
 * notification via {@see NotificationService::notify()} — ALONGSIDE the
 * untouched email, never instead of it.
 *
 * S11.0 Chunk 2 (D-2/D-5/D-6): the retrofit + agency fan-out. The manual-verify
 * → creator path (#2) now also emits in-app; the two agency-facing paths —
 * draft-submitted (#3) and contracted (#4) — FAN OUT in-app to the agency's
 * admins+managers via {@see self::notifyAgencyMembers()} (staff excluded),
 * while their emails stay single-inviter (the intentional D-6 asymmetry). Every
 * emit rides ALONGSIDE its untouched Mail::queue, never instead of it.
 */
final class SendAssignmentNotifications
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(AssignmentTransitioned $event): void
    {
        $assignment = $event->assignment;

        // The user who drove the transition (D-3) — the in-app notification's
        // actor. Resolved once from the event: the submitting creator for
        // draft-submitted, the accepting party for contracted, the acting
        // agency user for manual-verify. Null when system-triggered.
        $actor = $event->triggeredByUserId !== null
            ? User::find($event->triggeredByUserId)
            : null;

        match ($event->action) {
            AuditAction::AssignmentDraftSubmitted => $this->notifyAgencyOfSubmission($assignment, $actor),
            AuditAction::AssignmentDraftApproved => $this->notifyCreatorOfReview($assignment, 'approved'),
            AuditAction::AssignmentRevisionRequested => $this->notifyCreatorOfReview($assignment, 'revision_requested'),
            AuditAction::AssignmentDraftRejected => $this->notifyCreatorOfReview($assignment, 'rejected'),
            AuditAction::AssignmentContracted => $this->notifyAgencyOfContractAcceptance($assignment, $actor),
            AuditAction::AssignmentManuallyVerified => $this->notifyCreatorOfManualVerification($assignment, $actor),
            default => null,
        };
    }

    private function notifyCreatorOfManualVerification(CampaignAssignment $assignment, ?User $actor): void
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

        Mail::to($recipient->email)
            ->locale($recipient->preferred_language ?: 'en')
            ->queue(new PostManuallyVerifiedMail(
                creatorName: $creator->display_name ?? $recipient->name,
                campaignName: $campaign->name,
                assignmentUlid: $assignment->ulid,
            ));

        // S11.0 Chunk 2 (D-2 #2) — in-app rides alongside the untouched email.
        // Actor is the agency user who manually verified (D-3).
        $this->notifications->notify(
            recipient: $recipient,
            type: NotificationType::AssignmentManuallyVerified,
            subject: $assignment,
            actor: $actor,
            data: [
                'campaign_name' => $campaign->name,
                'creator_name' => $creator->display_name ?? $recipient->name,
                'assignment_ulid' => $assignment->ulid,
            ],
        );
    }

    private function notifyAgencyOfSubmission(CampaignAssignment $assignment, ?User $actor): void
    {
        $campaign = $assignment->campaign;
        $creator = $assignment->creator;

        if ($campaign === null || $creator === null) {
            return;
        }

        // Email — UNCHANGED, single-inviter (D-6). Guarded independently so a
        // missing/empty inviter never blocks the in-app fan-out below.
        $inviter = $assignment->invitedBy;
        if ($inviter instanceof User && $inviter->email !== '') {
            Mail::to($inviter->email)
                ->locale($inviter->preferred_language ?: 'en')
                ->queue(new DraftSubmittedForReviewMail(
                    recipientName: $inviter->name,
                    creatorName: $creator->display_name ?? '',
                    campaignName: $campaign->name,
                    campaignUlid: $campaign->ulid,
                ));
        }

        // S11.0 Chunk 2 (D-2 #3, D-5/D-6) — in-app FANS OUT to admins+managers
        // (staff excluded; the inviter is one recipient among them), so the
        // agency gets N in-app rows beside the 1 inviter email above. Actor is
        // the submitting creator (D-3). The asymmetry is intentional.
        $this->notifyAgencyMembers(
            $assignment,
            NotificationType::AssignmentDraftSubmitted,
            $actor,
            [
                'creator_name' => $creator->display_name ?? '',
                'campaign_name' => $campaign->name,
                'campaign_ulid' => $campaign->ulid,
            ],
        );
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
        // here. Approvals carry none. The same row records the reviewer
        // (reviewed_by_user_id) — the in-app notification's actor.
        $latestDraft = CampaignDraft::query()
            ->where('assignment_id', $assignment->id)
            ->orderByDesc('version')
            ->first(['review_feedback', 'reviewed_by_user_id']);

        $rawFeedback = $outcome === 'approved' ? null : $latestDraft?->review_feedback;
        $feedback = is_string($rawFeedback) && $rawFeedback !== '' ? $rawFeedback : null;

        Mail::to($recipient->email)
            ->locale($recipient->preferred_language ?: 'en')
            ->queue(new DraftReviewedMail(
                creatorName: $creator->display_name ?? $recipient->name,
                campaignName: $campaign->name,
                outcome: $outcome,
                feedback: $feedback,
                assignmentUlid: $assignment->ulid,
            ));

        // S11.0 Chunk 1 (D-10) — the proof consumer. In-app emission rides
        // alongside the email above; NotificationService reads the recipient's
        // in_app preference and writes a row only when enabled. `data` carries
        // render params only (the body renders client-side in Ch3).
        $reviewer = $latestDraft?->reviewed_by_user_id !== null
            ? User::find($latestDraft->reviewed_by_user_id)
            : null;

        $this->notifications->notify(
            recipient: $recipient,
            type: $this->reviewNotificationType($outcome),
            subject: $assignment,
            actor: $reviewer,
            data: [
                'campaign_name' => $campaign->name,
                'creator_name' => $creator->display_name ?? $recipient->name,
                'outcome' => $outcome,
                'feedback' => $feedback,
                'assignment_ulid' => $assignment->ulid,
            ],
        );
    }

    /**
     * @param  'approved'|'revision_requested'|'rejected'  $outcome
     */
    private function reviewNotificationType(string $outcome): NotificationType
    {
        return match ($outcome) {
            'approved' => NotificationType::AssignmentDraftApproved,
            'revision_requested' => NotificationType::AssignmentRevisionRequested,
            'rejected' => NotificationType::AssignmentDraftRejected,
        };
    }

    private function notifyAgencyOfContractAcceptance(CampaignAssignment $assignment, ?User $actor): void
    {
        // Q1 invariant (toggle-off-flow chunk): a CONTRACT-LESS advance must
        // NEVER announce a contract acceptance — no contract was signed. This
        // covers BOTH the requires=false auto-advance (D2) and the agency's
        // manual "proceed without contract" — and it CORRECTS a pre-existing
        // false-fire: since the decouple chunk shipped, the agency proceed-
        // without-contract path (contract($assignment, null)) has been sending
        // "the creator accepted the contract" for contracts that never existed.
        // The agency still learns of the accept itself via the accepted
        // notification, so no information is lost — only the false claim.
        if ($assignment->contract_id === null) {
            return;
        }

        $campaign = $assignment->campaign;
        $creator = $assignment->creator;

        if ($campaign === null || $creator === null) {
            return;
        }

        // Email — UNCHANGED, single-inviter (D-6). Guarded independently from
        // the fan-out so a missing inviter never blocks the in-app rows.
        $inviter = $assignment->invitedBy;
        if ($inviter instanceof User && $inviter->email !== '') {
            Mail::to($inviter->email)
                ->locale($inviter->preferred_language ?: 'en')
                ->queue(new ContractAcceptedMail(
                    recipientName: $inviter->name,
                    creatorName: $creator->display_name ?? '',
                    campaignName: $campaign->name,
                    campaignUlid: $campaign->ulid,
                ));
        }

        // S11.0 Chunk 2 (D-2 #4, D-5/D-6) — in-app fans out to admins+managers;
        // 1 inviter email vs N in-app rows (intentional asymmetry). Actor is the
        // party who drove the contracted transition (D-3).
        $this->notifyAgencyMembers(
            $assignment,
            NotificationType::AssignmentContracted,
            $actor,
            [
                'creator_name' => $creator->display_name ?? '',
                'campaign_name' => $campaign->name,
                'campaign_ulid' => $campaign->ulid,
            ],
        );
    }

    /**
     * The agency fan-out seam (S11.0 Chunk 2, D-5/D-6/D-9). Emits ONE in-app
     * notification per agency admin/manager (staff excluded by
     * {@see Agency::notifiableMembers()}). The membership query hits the
     * non-BelongsToAgency `agency_users` Pivot, so it is safe to run here in the
     * (potentially queued) listener with no `runAs` (D-9). NotificationService
     * still honours each recipient's per-type `in_app` preference.
     *
     * @param  array<string, mixed>  $data  Render params only (Ch3 renders the body).
     */
    private function notifyAgencyMembers(
        CampaignAssignment $assignment,
        NotificationType $type,
        ?User $actor,
        array $data,
    ): void {
        $agency = $assignment->agency;
        if ($agency === null) {
            return;
        }

        foreach ($agency->notifiableMembers() as $member) {
            $this->notifications->notify(
                recipient: $member,
                type: $type,
                subject: $assignment,
                actor: $actor,
                data: $data,
            );
        }
    }
}
