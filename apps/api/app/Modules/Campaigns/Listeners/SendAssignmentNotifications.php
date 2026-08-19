<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Listeners;

use App\Modules\Agencies\Mail\ConnectionRequestMail;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Campaigns\Events\AssignmentTransitioned;
use App\Modules\Campaigns\Jobs\VerifyPostedContentJob;
use App\Modules\Campaigns\Mail\AssignmentCompletedOnApprovalMail;
use App\Modules\Campaigns\Mail\ContractAcceptedMail;
use App\Modules\Campaigns\Mail\DraftReviewedMail;
use App\Modules\Campaigns\Mail\DraftSubmittedForReviewMail;
use App\Modules\Campaigns\Mail\InviteReceivedMail;
use App\Modules\Campaigns\Mail\PostManuallyVerifiedMail;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Campaigns\Models\CampaignDraft;
use App\Modules\Campaigns\Services\CampaignApplicationNotifier;
use App\Modules\Campaigns\Services\CampaignAssignmentStateMachine;
use App\Modules\Campaigns\Services\CampaignInvitationService;
use App\Modules\Creators\Features\MissingCreatorMailsEnabled;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Services\NotificationService;
use Illuminate\Support\Facades\Mail;
use Laravel\Pennant\Feature;

/**
 * The 3rd consumer of {@see AssignmentTransitioned} (Sprint 9 Chunk 2, D-14) —
 * the review notification set. Acts only on the review-lifecycle verbs:
 *
 *   - `assignment.draft_submitted`     → notify the AGENCY (the inviting member)
 *   - `assignment.draft_approved`      → notify the CREATOR (approved)
 *   - `assignment.revision_requested`  → notify the CREATOR (changes requested)
 *   - `assignment.draft_rejected`      → notify the CREATOR (rejected)
 *   - `assignment.completed_on_approval` → notify the CREATOR (finished, AH-069)
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
 *
 * AH-069 (D5/Q3) adds the ONE exception to that last sentence, and confines it to
 * a single flag: when an approval also COMPLETES the assignment (a campaign whose
 * creators do not post the deliverable), the controller threads
 * `completes_on_approval` into the approval's context and the draft-approved
 * EMAIL is skipped — its in-app row still rides. The completion transition that
 * follows sends the one email. Two in-app rows, one email, for one click. On
 * every other campaign the flag is absent and nothing here behaves differently.
 *
 * §5.32 (AH-083, kickoff Q1) — the missing invite email (①). Rather than
 * touching {@see CampaignInvitationService} or
 * {@see CampaignAssignmentStateMachine} (both
 * already dispatch {@see AssignmentTransitioned} on every path that lands an
 * assignment on `invited`), this is the FOURTH consumer verb pair added here:
 * `assignment.invited` (the fresh invite) and `assignment.re_invited` (BOTH the
 * AH-035 re-offer after a decline AND the agency's re-offer answering the
 * creator's own counter — kickoff Q4, all three invite-shaped paths emit
 * identically). One match arm, one private method
 * ({@see self::notifyCreatorOfInvite()}), discriminating only on copy
 * (kickoff Q5). The MAIL leg is gated by
 * {@see MissingCreatorMailsEnabled} (default OFF); the in-app row is NOT — it
 * is the dual-emit ruling (kickoff Q2), so `assignment.invited` moved to the
 * frontend's LIVE_TYPES registry alongside this chunk.
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

        $round = $this->roundFromContext($event);

        match ($event->action) {
            AuditAction::AssignmentDraftSubmitted => $this->notifyAgencyOfSubmission($assignment, $actor, $round),
            AuditAction::AssignmentDraftApproved => $this->notifyCreatorOfReview(
                $assignment,
                'approved',
                $round,
                suppressEmail: $event->context['completes_on_approval'] ?? false,
            ),
            AuditAction::AssignmentRevisionRequested => $this->notifyCreatorOfReview($assignment, 'revision_requested', $round),
            AuditAction::AssignmentDraftRejected => $this->notifyCreatorOfReview($assignment, 'rejected', $round),
            AuditAction::AssignmentCompletedOnApproval => $this->notifyCreatorOfCompletionOnApproval($assignment, $actor, $round),
            AuditAction::AssignmentContracted => $this->notifyAgencyOfContractAcceptance($assignment, $actor),
            AuditAction::AssignmentManuallyVerified => $this->notifyCreatorOfManualVerification($assignment, $actor),
            AuditAction::AssignmentInvited, AuditAction::AssignmentReInvited => $this->notifyCreatorOfInvite($assignment, $event->action, $actor),
            default => null,
        };
    }

    /**
     * AH-069 (D5) — the creator learns their assignment is finished.
     *
     * Emitted on the CHAINED completion transition, a moment after the approval's
     * own notification. Two in-app rows is the deliberate choice (Q3): they read
     * as one story, and collapsing them would mean either losing the approval
     * from the creator's history or writing a row whose type lies about which
     * transition produced it. The EMAIL is the other half of that ruling —
     * exactly one is sent, and it is this one, because it carries the news.
     *
     * Fail-quiet on a missing creator/campaign/user, matching every sibling here:
     * a notification is not worth an exception inside a transition listener.
     */
    private function notifyCreatorOfCompletionOnApproval(CampaignAssignment $assignment, ?User $actor, ?int $round): void
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
            ->queue(new AssignmentCompletedOnApprovalMail(
                creatorName: $creator->display_name ?? $recipient->name,
                campaignName: $campaign->name,
                assignmentUlid: $assignment->ulid,
            ));

        $this->notifications->notify(
            recipient: $recipient,
            type: NotificationType::AssignmentCompletedOnApproval,
            subject: $assignment,
            actor: $actor,
            data: $this->withRound([
                'campaign_name' => $campaign->name,
                'creator_name' => $creator->display_name ?? $recipient->name,
                'assignment_ulid' => $assignment->ulid,
            ], $round),
        );
    }

    /**
     * The round number this transition is about (AH-068, D4), read off the
     * context the domain already emits: the submit path sends `version` with the
     * row it just wrote, and all three review verbs send the reviewed draft's.
     * So no query is added here for either leg.
     *
     * Null when the machine was driven without a context — its own tests do
     * exactly that. A direct call cannot invent a round number, so the
     * notification carries none and every surface reads as it did before this
     * chunk.
     */
    private function roundFromContext(AssignmentTransitioned $event): ?int
    {
        $version = $event->context['version'] ?? null;

        return is_int($version) && $version > 0 ? $version : null;
    }

    /**
     * Add the round to a notification payload, or leave the payload untouched
     * when the round is unknown. The key is ABSENT rather than null so a
     * consumer can test presence — the in-app renderer shows the round line only
     * for rows that carry one, which is what keeps every notification row
     * written before this chunk rendering exactly as it always did.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withRound(array $data, ?int $round): array
    {
        if ($round === null) {
            return $data;
        }

        $data['version'] = $round;

        return $data;
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

    /**
     * AH-083 (①) — a campaign offer is waiting for the creator. Fires on
     * BOTH verbs that land an assignment on `invited` (kickoff Q4: all three
     * call sites behind them emit identically — the fresh invite from
     * `CampaignInvitationService::invite()`, the AH-035 re-offer after a
     * decline, and the agency's re-offer answering the creator's own counter).
     *
     * `$action` is the ONLY discriminator (kickoff Q5): `AssignmentInvited` →
     * `fresh`; `AssignmentReInvited` → `re_offer` (shared copy for both
     * re-invite paths — the creator's experience is identical either way). No
     * NotificationType exists for `re_invited` (deliberately excluded, since
     * un-curating it would need a net-new in-app vocabulary entry the product
     * has not asked for) — the in-app row always writes as
     * {@see NotificationType::AssignmentInvited} regardless of outcome, which
     * is honest: from the creator's feed, "you have an offer" is the same fact
     * whether it is the first one or an update.
     *
     * The email leg routes through {@see self::queueInviteMail()}, the ONE
     * flag checkpoint (the {@see CampaignApplicationNotifier::queue()}
     * precedent) — so a future call site can never bypass the flag by mistake.
     * Fail-quiet on a missing creator/campaign/user, matching every sibling here.
     */
    private function notifyCreatorOfInvite(CampaignAssignment $assignment, AuditAction $action, ?User $actor): void
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

        $outcome = $action === AuditAction::AssignmentInvited ? 'fresh' : 're_offer';
        $creatorName = $creator->display_name ?? $recipient->name;

        $this->queueInviteMail($recipient, new InviteReceivedMail(
            creatorName: $creatorName,
            campaignName: $campaign->name,
            outcome: $outcome,
            assignmentUlid: $assignment->ulid,
        ));

        // Dual-emit (kickoff Q2) — the in-app row is UNGATED by the mail flag;
        // NotificationService still honours the recipient's own in_app
        // preference. Actor is whoever drove the transition (the inviting
        // agency member, or the system on an auto-path).
        $this->notifications->notify(
            recipient: $recipient,
            type: NotificationType::AssignmentInvited,
            subject: $assignment,
            actor: $actor,
            data: [
                'campaign_name' => $campaign->name,
                'creator_name' => $creatorName,
                'assignment_ulid' => $assignment->ulid,
            ],
        );
    }

    /**
     * The ONE flag checkpoint for the invite mail (AH-083 D1) — mirrors
     * {@see CampaignApplicationNotifier::queue()}.
     * A second `Feature::active()` call anywhere in this class would quietly
     * halve that guarantee, so every invite-shaped path funnels through here.
     */
    private function queueInviteMail(User $recipient, InviteReceivedMail $mailable): void
    {
        if (! Feature::active(MissingCreatorMailsEnabled::NAME)) {
            return;
        }

        Mail::to($recipient->email)
            ->locale($recipient->preferred_language ?: 'en')
            ->queue($mailable);
    }

    private function notifyAgencyOfSubmission(CampaignAssignment $assignment, ?User $actor, ?int $round = null): void
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
                    round: $round,
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
            $this->withRound([
                'creator_name' => $creator->display_name ?? '',
                'campaign_name' => $campaign->name,
                'campaign_ulid' => $campaign->ulid,
            ], $round),
        );
    }

    /**
     * @param  'approved'|'revision_requested'|'rejected'  $outcome
     * @param  bool  $suppressEmail  AH-069 Q3 — set only on an approval that
     *                               COMPLETES the assignment. The in-app row is
     *                               still written; the email is not, because the
     *                               completion mail arriving a moment later
     *                               carries the same news and two mails seconds
     *                               apart for one click is noise. The flag comes
     *                               from the transition context the controller
     *                               threaded, so this listener still makes no
     *                               query of its own to decide.
     */
    private function notifyCreatorOfReview(CampaignAssignment $assignment, string $outcome, ?int $round = null, bool $suppressEmail = false): void
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

        if (! $suppressEmail) {
            Mail::to($recipient->email)
                ->locale($recipient->preferred_language ?: 'en')
                ->queue(new DraftReviewedMail(
                    creatorName: $creator->display_name ?? $recipient->name,
                    campaignName: $campaign->name,
                    outcome: $outcome,
                    feedback: $feedback,
                    assignmentUlid: $assignment->ulid,
                    round: $round,
                ));
        }

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
            data: $this->withRound([
                'campaign_name' => $campaign->name,
                'creator_name' => $creator->display_name ?? $recipient->name,
                'outcome' => $outcome,
                'feedback' => $feedback,
                'assignment_ulid' => $assignment->ulid,
            ], $round),
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
