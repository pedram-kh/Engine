<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Enums;

use App\Modules\Audit\Enums\AuditAction;

/**
 * The catalogue of in-app notification types (S11.0 Chunk 1, D-5).
 *
 * One-vocabulary discipline: every case's STRING VALUE is an exact
 * {@see AuditAction} value. NotificationType is the user-facing SUBSET of the
 * audit verbs — the lifecycle events a recipient (creator or agency user)
 * should see in their feed. The {@see self::auditAction()} helper proves the
 * tie at runtime, and NotificationTypeEnumTest is the catalogue tripwire
 * (the AuditActionEnumTest / CampaignEnumsTest precedent): adding or removing
 * a case is a deliberate edit, never an accident.
 *
 * Curated membership (the assignment lifecycle verbs + the two forward payment
 * verbs). The forward payment verbs (`payment_funded` / `payment_released`) are
 * included now so the deferred-S10 escrow alerts are drop-in (D-5).
 *
 * Deliberately EXCLUDED as internal / non-notification transitions:
 * `assignment.re_invited`, `assignment.producing`, `assignment.posted_by_creator`,
 * `assignment.live_verified`, `assignment.resubmit_requested(_in_place)`,
 * `assignment.posted_content_updated`.
 *
 * Ch2 (S11.0) un-curates the two creator-lifecycle verbs whose AuditAction
 * value already exists (`creator.approved` / `creator.rejected`) so the admin
 * approve/reject sites emit in-app. The remaining lifecycle / connection verbs
 * (`creator.invited`, `creator.blacklisted`, connection accept/decline) stay
 * deferred: each needs a NET-NEW AuditAction verb (or, for blacklist, is
 * deliberately email-only — an unsolicited in-app notice of one's own
 * blacklisting is counterproductive), tracked in docs/tech-debt.md.
 *
 * The body text is NEVER stored here or on the row — it renders client-side
 * (Ch3) from `type` + the notification's `data` payload.
 */
enum NotificationType: string
{
    // Assignment lifecycle (creator- and agency-facing). The proof consumer
    // this chunk emits the draft-review trio (D-10).
    case AssignmentInvited = 'assignment.invited';
    case AssignmentDeclined = 'assignment.declined';
    case AssignmentCountered = 'assignment.countered';
    case AssignmentAccepted = 'assignment.accepted';
    case AssignmentContracted = 'assignment.contracted';
    case AssignmentDraftSubmitted = 'assignment.draft_submitted';
    case AssignmentRevisionRequested = 'assignment.revision_requested';
    case AssignmentDraftApproved = 'assignment.draft_approved';
    case AssignmentDraftRejected = 'assignment.draft_rejected';
    // AH-069 (D5) — the approval ENDED the assignment, on a campaign whose
    // creators do not post the deliverable. Creator-facing and single-direction:
    // the agency performed the approval, so the completion is not news to them.
    // It arrives ALONGSIDE the draft-approved in-app row rather than instead of
    // it — two rows tell a coherent story ("approved", then "and that's it") —
    // but only ONE of the two sends an email (Q3): this one.
    case AssignmentCompletedOnApproval = 'assignment.completed_on_approval';
    case AssignmentManuallyVerified = 'assignment.manually_verified';
    case AssignmentCancelled = 'assignment.cancelled';

    // Forward payment verbs (deferred-S10 escrow alerts — drop-in, D-5).
    case AssignmentPaymentFunded = 'assignment.payment_funded';
    case AssignmentPaymentReleased = 'assignment.payment_released';

    // Creator lifecycle (S11.0 Chunk 2, D-4). The admin approve/reject sites
    // emit in-app alongside their untouched mailables. Both values already exist
    // in AuditAction — clean enum-adds, no new vocabulary.
    case CreatorApproved = 'creator.approved';
    case CreatorRejected = 'creator.rejected';

    // Messaging (Sprint 11, D-7). Dual-recipient: a new message notifies the
    // COUNTERPARTY. Two types (not one) so each direction has its own recipient
    // in the FE LIVE_TYPES registry and its own prefs toggle — a single
    // message.received type would force one static recipient, leaving the other
    // party with a row but no toggle (the dead-control trap Ch3b prevents).
    case MessageReceivedByCreator = 'message.received_by_creator';
    case MessageReceivedByAgency = 'message.received_by_agency';

    // Relationship messaging (AH-010). The 1:1 connected agency↔creator DM
    // surface, distinct from the campaign-assignment thread above. Dual-recipient
    // for the same reason: a new relationship message notifies the COUNTERPARTY,
    // and each direction needs its own type + prefs toggle. Digest is deferred
    // (AH-010 D5 — in-app unread covers it); these flow through the in-app path.
    case MessageRelationshipReceivedByCreator = 'message.relationship_received_by_creator';
    case MessageRelationshipReceivedByAgency = 'message.relationship_received_by_agency';

    // AH-051 (D-7) — admin-initiated relation events the recipient must see.
    //   - RelationAdminConnected: admin Door 2 directly connected an agency to
    //     this creator (records an offline agreement) — the creator is notified
    //     immediately (dual-emit in-app + mail), naming the agency.
    //   - RelationDisconnected: an admin severed a roster relationship. ONE type
    //     for BOTH parties (creator + agency members) — directional splits earn
    //     their keep at messaging frequency, not rare-admin-action frequency
    //     (D-7 ruling). Dual-emit in-app + mail.
    // admin_requested is deliberately NOT here: it is an ordinary connection
    // request the creator sees in their connection-requests inbox (Door 1 rides
    // the existing ConnectionRequestMail, exactly like an agency-sent request).
    case RelationAdminConnected = 'agency_creator_relation.admin_connected';
    case RelationDisconnected = 'agency_creator_relation.disconnected';

    // AH-056 (Jobs Board chunk 3, D8) — a rostered agency listed a new job.
    // SINGLE-DIRECTION (creator), so one verb rather than the dual pair the
    // messaging types need: the agency performed the listing, so it is not news
    // to them, and a type nobody receives is a toggle nobody should be offered.
    //
    // ⚠ MOVED, AH-058 (chunk 4, Q5): grouped under `jobs_board`, not
    // `assignment`. Chunk 3 grouped it under `assignment` because one type does
    // not earn a group, and wrote the condition for revisiting down — "the group
    // splits when a SECOND jobs-board type exists to split with". Chunk 4 adds
    // three, so the condition is met and the accepted label tension
    // ("Assignments" heading over a job-posted toggle) is resolved rather than
    // multiplied by four.
    //
    // Toggleable in_app (NOT always-on): a job alert is promotional in
    // character, so a creator who does not want it must be able to stop it.
    // ⚠ The MAIL half is not covered by that toggle — the email channel has
    // never been wired through preference reads platform-wide. See the review's
    // production posture and the tech-debt entry.
    case CampaignJobPosted = 'campaign.job_posted';

    // AH-058 (Jobs Board chunk 4, D6) — the three application verbs, the loop's
    // other half. `submitted` is the one chunk 3 deliberately deferred: its
    // AuditAction has been live since chunk 3 and only now gains a type, because
    // the agency surface that acts on it (the Applications tab) ships here.
    //
    // Directions are NOT symmetric, and that is why there are three types and
    // not one:
    //   - `submitted` reaches the AGENCY (`Agency::notifiableMembers()` —
    //     admins + managers). A creator applying is not news to the creator.
    //     ⚠ Staff are excluded, per that method's load-bearing exclusion, so an
    //     agency staff member who may invite is not told when someone applies.
    //     Pre-existing asymmetry, recorded rather than fixed here.
    //   - `accepted` / `rejected` reach the CREATOR. The agency performed the
    //     action, so it is not news to them.
    // Each verb therefore has exactly one recipient role and one honest toggle;
    // a single `campaign_application.answered` type would force one static
    // recipient and leave the other side with a row it cannot silence.
    //
    // `rejected` is emitted by TWO sites (the agency's reject, and the
    // campaign-terminal auto-reject) with a `cause` in the data bag choosing the
    // mail body variant. One type, two causes — see AuditAction.
    //
    // Grouped under `jobs_board` in the prefs UI, which is the group this
    // chunk creates: `campaign.job_posted`'s docblock above wrote the trigger
    // ("the group splits when a SECOND jobs-board type exists to split with")
    // and three of them is that second. It moves with them.
    //
    // ⚠ All three ALSO send mail, and the mail half is gated by the
    // `application_notifications_enabled` Pennant flag while the in-app half is
    // not: the flag gates mail; in-app honours the recipient's own preference
    // (a disabled `in_app` toggle still writes nothing). The mail channel is
    // still not preference-gated platform-wide — see docs/tech-debt.md.
    case CampaignApplicationSubmitted = 'campaign_application.submitted';
    case CampaignApplicationAccepted = 'campaign_application.accepted';
    case CampaignApplicationRejected = 'campaign_application.rejected';

    /**
     * The AuditAction this notification type mirrors. Proves the one-vocabulary
     * tie — every NotificationType value MUST be a live AuditAction value.
     */
    public function auditAction(): AuditAction
    {
        return AuditAction::from($this->value);
    }

    /**
     * The payment-event alert types — the deferred-S10 escrow alerts. These
     * are held back from the admin operational-alerts surface this sprint
     * (Sprint 13, D-12/D-13): the types exist (so the consumer is drop-in)
     * but their emit sites + the payment admin UI are S10. The admin alerts
     * shell reads this partition from one source so the held-back set and
     * its test never drift.
     *
     * @return array<int, self>
     */
    public static function paymentAlerts(): array
    {
        return [self::AssignmentPaymentFunded, self::AssignmentPaymentReleased];
    }

    /**
     * Whether this type is a deferred payment-event alert (coming-soon).
     */
    public function isPaymentAlert(): bool
    {
        return in_array($this, self::paymentAlerts(), true);
    }
}
