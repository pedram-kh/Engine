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
