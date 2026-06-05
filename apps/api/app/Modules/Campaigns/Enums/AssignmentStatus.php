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
 *   draft_submitted → {revision_requested → producing(loop), approved}
 *   approved → posted → live_verified → payment_held → payment_released(terminal)
 *   any non-terminal → cancelled(terminal)
 *
 * Reachable under own power this chunk: through draft_submitted, plus
 * cancellation. VENDOR-GATED (D-6) — defined + guard-tested but unreachable
 * until their sprint: `posted`/`live_verified` (social adapter — parked),
 * `payment_held`/`payment_released` (Stripe escrow — Sprint 10).
 *
 * Terminal states: declined, payment_released, cancelled.
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
    case Posted = 'posted';
    case LiveVerified = 'live_verified';
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
            self::PaymentReleased,
            self::Cancelled => true,
            default => false,
        };
    }
}
