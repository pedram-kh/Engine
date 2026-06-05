<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Enums;

use App\Modules\Campaigns\Services\CampaignAssignmentStateMachine;

/**
 * The status of a CampaignAssignment (Sprint 8 Chunk 1, D-5).
 *
 * Per docs/03-DATA-MODEL.md §7 state machine. Stored as varchar(32) on
 * `campaign_assignments.status`. Transitions are driven exclusively by
 * {@see CampaignAssignmentStateMachine} —
 * no controller flips this column directly.
 *
 * The graph:
 *   invited → {declined(terminal), countered, accepted}
 *   accepted → contracted → producing → draft_submitted
 *   draft_submitted → {revision_requested → producing(loop), approved, rejected(terminal)}
 *   approved → posted → live_verified → payment_held → payment_released(terminal)
 *   any non-terminal → cancelled(terminal)
 *
 * Reachable under own power this chunk: through draft_submitted, plus
 * cancellation. VENDOR-GATED (D-6) — defined + guard-tested but unreachable
 * until their sprint: `posted`/`live_verified` (social adapter — parked),
 * `payment_held`/`payment_released` (Stripe escrow — Sprint 10).
 *
 * Sprint 9 Chunk 2 (D-1): `rejected` is a NEW dedicated terminal — the agency
 * rejects a submitted draft (`draft_submitted → rejected`, mandatory reason).
 * Distinct from `cancelled` (which can fire from any non-terminal): rejection
 * is specifically the review-time "this draft is not acceptable, end the
 * assignment" outcome, and the board catalogue routes its
 * `assignment.draft_rejected` verb to a distinct "stalled" column.
 *
 * Verification-resolution chunk (D-1): `manually_verified` is a NEW
 * NON-terminal state — the agency manually overrides a FAILED auto-verification
 * (`posted → manually_verified`, mandatory reason). It is a DISTINCT status (the
 * audit trail shows a human override, NOT a real `live_verified` pass) that is
 * nonetheless PAYMENT-ELIGIBLE alongside `live_verified` — see
 * {@see isPaymentEligible()} (the dead-end-preventer: a manual override that
 * could never be paid would just relocate the failure). 17 chars, fits the
 * `campaign_assignments.status` varchar(32) — no migration (a sub-status marker
 * on `campaign_posted_content.verification_status` varchar(16) WOULD overflow).
 *
 * Terminal states: declined, rejected, payment_released, cancelled.
 * Payment-eligible states: live_verified, manually_verified ({@see isPaymentEligible()}).
 */
enum AssignmentStatus: string
{
    case Invited = 'invited';
    case Declined = 'declined';
    case Countered = 'countered';
    case Accepted = 'accepted';
    case Contracted = 'contracted';
    case Producing = 'producing';
    case DraftSubmitted = 'draft_submitted';
    case RevisionRequested = 'revision_requested';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Posted = 'posted';
    case LiveVerified = 'live_verified';
    case ManuallyVerified = 'manually_verified';
    case PaymentHeld = 'payment_held';
    case PaymentReleased = 'payment_released';
    case Cancelled = 'cancelled';

    /**
     * Terminal states have no outgoing transition (cancel included — you
     * cannot cancel an already-terminal assignment).
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Declined,
            self::Rejected,
            self::PaymentReleased,
            self::Cancelled => true,
            default => false,
        };
    }

    /**
     * True when a payment may be released for an assignment in this state
     * (verification-resolution chunk, D-3). The S10 release-gate
     * (docs/20-PHASE-1-SPEC.md §6.8 "Release payment" + the auto-release
     * listener) MUST consume THIS predicate, never the literal `live_verified`
     * string — so a manual override (`manually_verified`, D-1) is payment-eligible
     * alongside a real auto-verification (`live_verified`) WITHOUT collapsing the
     * two (the audit distinction survives). Proven now (a test asserts both
     * states satisfy it) even though no payment is built this chunk.
     */
    public function isPaymentEligible(): bool
    {
        return match ($this) {
            self::LiveVerified,
            self::ManuallyVerified => true,
            default => false,
        };
    }
}
