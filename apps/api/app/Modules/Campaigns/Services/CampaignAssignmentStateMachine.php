<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Services;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Events\AssignmentTransitioned;
use App\Modules\Campaigns\Exceptions\AssignmentTransitionException;
use App\Modules\Campaigns\Exceptions\AssignmentTransitionGatedException;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Creators\Features\ContractSigningEnabled;
use App\Modules\Creators\Models\Contract;
use App\Modules\Identity\Models\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

/**
 * The single authority over `campaign_assignments.status` (Sprint 8 Chunk 1,
 * D-5/D-6). No controller flips the status column directly — every change
 * flows through one of the guarded transition methods here.
 *
 * Every legal transition (fail-closed — only legal source states pass):
 *   - stamps the relevant timestamp column (where one exists);
 *   - writes an `audit_logs` row with the board event-key verb + {from,to}
 *     metadata (the board's future event vocabulary, D-9);
 *   - dispatches an {@see AssignmentTransitioned} event (NO listener this
 *     chunk — the board sprint adds it; the event is fired so the board sprint
 *     is purely additive).
 *
 * The graph (docs/03-DATA-MODEL.md §7):
 *   invited → {declined, countered, accepted}
 *   countered → invited (re-invite, D-7 — the agency re-offers)
 *   accepted → contracted (flag-gated) → producing → draft_submitted
 *   draft_submitted → {revision_requested → producing(loop), approved}
 *   approved → posted → live_verified → payment_held → payment_released
 *   any non-terminal → cancelled
 *
 * VENDOR-GATED (D-6) — the methods + their source guards exist + are tested,
 * but `verifyLive` / `holdPayment` / `releasePayment` refuse because the
 * social adapter (parked) and Stripe escrow (Sprint 10) do not exist yet.
 * There is NO manual path to those states (the footgun guard). `contract` is
 * gated on the `contract_signing_enabled` flag (the e-sign mock exists).
 *
 * Note: the `invited` ENTRY state is set by the invite flow (Chunk 2), not by
 * a transition here. The agency re-offer after a counter IS a machine edge now
 * ({@see reinvite()}, `countered → invited`, D-7) — single-shot, not an
 * unbounded negotiation loop.
 */
final class CampaignAssignmentStateMachine
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly Dispatcher $events,
    ) {}

    public function decline(CampaignAssignment $assignment, ?User $actor = null): CampaignAssignment
    {
        $this->assertSource($assignment, [AssignmentStatus::Invited], AssignmentStatus::Declined);

        return $this->commit(
            $assignment,
            AssignmentStatus::Declined,
            AuditAction::AssignmentDeclined,
            $actor,
            mutate: function (CampaignAssignment $a): void {
                $a->responded_at = now();
            },
        );
    }

    public function counter(
        CampaignAssignment $assignment,
        int $counteredFeeMinorUnits,
        string $counteredFeeCurrency,
        ?User $actor = null,
    ): CampaignAssignment {
        $this->assertSource($assignment, [AssignmentStatus::Invited], AssignmentStatus::Countered);

        return $this->commit(
            $assignment,
            AssignmentStatus::Countered,
            AuditAction::AssignmentCountered,
            $actor,
            mutate: function (CampaignAssignment $a) use ($counteredFeeMinorUnits, $counteredFeeCurrency): void {
                // D-7: record the counter DISTINCTLY — never overwrite the
                // agency's original agreed_fee.
                $a->responded_at = now();
                $a->countered_fee_minor_units = $counteredFeeMinorUnits;
                $a->countered_fee_currency = $counteredFeeCurrency;
            },
            context: [
                'countered_fee_minor_units' => $counteredFeeMinorUnits,
                'countered_fee_currency' => $counteredFeeCurrency,
            ],
        );
    }

    /**
     * countered → invited (D-7). The agency's response to a creator counter:
     * re-open the invitation with a fresh agreed fee. A GUARDED machine edge —
     * the machine stays the sole status authority (no raw back-write). The new
     * offer OVERWRITES `agreed_fee_*`; the prior `countered_fee_*` and the
     * `responded_at` stamp are cleared so the re-opened invite reads cleanly
     * (single-shot: counter → re-invite → accept/decline, no unbounded loop).
     */
    public function reinvite(
        CampaignAssignment $assignment,
        int $agreedFeeMinorUnits,
        string $agreedFeeCurrency,
        ?User $actor = null,
    ): CampaignAssignment {
        $this->assertSource($assignment, [AssignmentStatus::Countered], AssignmentStatus::Invited);

        return $this->commit(
            $assignment,
            AssignmentStatus::Invited,
            AuditAction::AssignmentReInvited,
            $actor,
            mutate: function (CampaignAssignment $a) use ($agreedFeeMinorUnits, $agreedFeeCurrency): void {
                $a->agreed_fee_minor_units = $agreedFeeMinorUnits;
                $a->agreed_fee_currency = $agreedFeeCurrency;
                $a->countered_fee_minor_units = null;
                $a->countered_fee_currency = null;
                $a->responded_at = null;
            },
            context: [
                'agreed_fee_minor_units' => $agreedFeeMinorUnits,
                'agreed_fee_currency' => $agreedFeeCurrency,
            ],
        );
    }

    public function accept(CampaignAssignment $assignment, ?User $actor = null): CampaignAssignment
    {
        $this->assertSource($assignment, [AssignmentStatus::Invited], AssignmentStatus::Accepted);

        return $this->commit(
            $assignment,
            AssignmentStatus::Accepted,
            AuditAction::AssignmentAccepted,
            $actor,
            mutate: function (CampaignAssignment $a): void {
                $now = now();
                $a->responded_at = $now;
                $a->accepted_at = $now;
            },
        );
    }

    /**
     * accepted → contracted. Flag-gated on `contract_signing_enabled` (the
     * e-sign mock exists). The optional signed addendum is recorded on
     * `contract_id`.
     */
    public function contract(
        CampaignAssignment $assignment,
        ?Contract $contract = null,
        ?User $actor = null,
    ): CampaignAssignment {
        $this->assertSource($assignment, [AssignmentStatus::Accepted], AssignmentStatus::Contracted);

        if (! Feature::active(ContractSigningEnabled::NAME)) {
            throw AssignmentTransitionGatedException::contractSigningDisabled();
        }

        return $this->commit(
            $assignment,
            AssignmentStatus::Contracted,
            AuditAction::AssignmentContracted,
            $actor,
            mutate: function (CampaignAssignment $a) use ($contract): void {
                if ($contract !== null) {
                    $a->contract_id = $contract->id;
                }
            },
        );
    }

    /**
     * → producing. Two legal sources: contracted (first pass) and
     * revision_requested (the review loop, D-5).
     */
    public function startProducing(CampaignAssignment $assignment, ?User $actor = null): CampaignAssignment
    {
        $this->assertSource(
            $assignment,
            [AssignmentStatus::Contracted, AssignmentStatus::RevisionRequested],
            AssignmentStatus::Producing,
        );

        return $this->commit(
            $assignment,
            AssignmentStatus::Producing,
            AuditAction::AssignmentProducing,
            $actor,
        );
    }

    public function submitDraft(CampaignAssignment $assignment, ?User $actor = null): CampaignAssignment
    {
        $this->assertSource($assignment, [AssignmentStatus::Producing], AssignmentStatus::DraftSubmitted);

        return $this->commit(
            $assignment,
            AssignmentStatus::DraftSubmitted,
            AuditAction::AssignmentDraftSubmitted,
            $actor,
            mutate: function (CampaignAssignment $a): void {
                $a->submitted_draft_at = now();
            },
        );
    }

    public function requestRevision(CampaignAssignment $assignment, ?User $actor = null): CampaignAssignment
    {
        $this->assertSource($assignment, [AssignmentStatus::DraftSubmitted], AssignmentStatus::RevisionRequested);

        return $this->commit(
            $assignment,
            AssignmentStatus::RevisionRequested,
            AuditAction::AssignmentRevisionRequested,
            $actor,
        );
    }

    /**
     * draft_submitted → approved. Fires the board verb `assignment.draft_approved`.
     */
    public function approve(CampaignAssignment $assignment, ?User $actor = null): CampaignAssignment
    {
        $this->assertSource($assignment, [AssignmentStatus::DraftSubmitted], AssignmentStatus::Approved);

        return $this->commit(
            $assignment,
            AssignmentStatus::Approved,
            AuditAction::AssignmentDraftApproved,
            $actor,
            mutate: function (CampaignAssignment $a): void {
                $a->approved_at = now();
            },
        );
    }

    /**
     * approved → posted. The creator self-reports the post (no vendor) —
     * fires the board verb `assignment.posted_by_creator`. The creator-side
     * endpoint lands in a later sprint; the transition exists here now.
     */
    public function markPosted(CampaignAssignment $assignment, ?User $actor = null): CampaignAssignment
    {
        $this->assertSource($assignment, [AssignmentStatus::Approved], AssignmentStatus::Posted);

        return $this->commit(
            $assignment,
            AssignmentStatus::Posted,
            AuditAction::AssignmentPostedByCreator,
            $actor,
            mutate: function (CampaignAssignment $a): void {
                $a->posted_at = now();
            },
        );
    }

    /**
     * posted → live_verified. VENDOR-GATED (D-6) — the social-verification
     * adapter is parked. The source guard passes; the vendor gate refuses.
     * No manual path is permitted.
     */
    public function verifyLive(CampaignAssignment $assignment, ?User $actor = null): CampaignAssignment
    {
        $this->assertSource($assignment, [AssignmentStatus::Posted], AssignmentStatus::LiveVerified);

        throw AssignmentTransitionGatedException::socialAdapterUnavailable();
    }

    /**
     * live_verified → payment_held. VENDOR-GATED (D-6) — Stripe escrow lands
     * in Sprint 10. No manual path (no "mark funded" with no money moved).
     */
    public function holdPayment(CampaignAssignment $assignment, ?User $actor = null): CampaignAssignment
    {
        $this->assertSource($assignment, [AssignmentStatus::LiveVerified], AssignmentStatus::PaymentHeld);

        throw AssignmentTransitionGatedException::escrowUnavailable();
    }

    /**
     * payment_held → payment_released (terminal success). VENDOR-GATED (D-6) —
     * Stripe escrow lands in Sprint 10. No manual path (no "mark released" with
     * no money moved — the footgun).
     */
    public function releasePayment(CampaignAssignment $assignment, ?User $actor = null): CampaignAssignment
    {
        $this->assertSource($assignment, [AssignmentStatus::PaymentHeld], AssignmentStatus::PaymentReleased);

        throw AssignmentTransitionGatedException::escrowUnavailable();
    }

    /**
     * Cancel from any non-terminal state → cancelled (terminal). Requires a
     * non-empty reason (D-9). Cancelling a terminal assignment is rejected.
     */
    public function cancel(CampaignAssignment $assignment, string $reason, ?User $actor = null): CampaignAssignment
    {
        if ($assignment->status->isTerminal()) {
            throw AssignmentTransitionException::terminal($assignment->status);
        }

        $trimmed = trim($reason);
        if ($trimmed === '') {
            throw AssignmentTransitionException::reasonRequired();
        }

        return $this->commit(
            $assignment,
            AssignmentStatus::Cancelled,
            AuditAction::AssignmentCancelled,
            $actor,
            reason: $trimmed,
            mutate: function (CampaignAssignment $a) use ($trimmed, $actor): void {
                $a->cancelled_at = now();
                $a->cancelled_reason = $trimmed;
                $a->cancelled_by_user_id = $actor?->id;
            },
        );
    }

    /**
     * @param  list<AssignmentStatus>  $allowedFrom
     */
    private function assertSource(CampaignAssignment $assignment, array $allowedFrom, AssignmentStatus $to): void
    {
        if (! in_array($assignment->status, $allowedFrom, true)) {
            throw AssignmentTransitionException::illegal($assignment->status, $to);
        }
    }

    /**
     * @param  (callable(CampaignAssignment): void)|null  $mutate
     * @param  array<string, mixed>  $context
     */
    private function commit(
        CampaignAssignment $assignment,
        AssignmentStatus $to,
        AuditAction $action,
        ?User $actor,
        ?string $reason = null,
        ?callable $mutate = null,
        array $context = [],
    ): CampaignAssignment {
        $from = $assignment->status;

        return DB::transaction(function () use ($assignment, $from, $to, $action, $actor, $reason, $mutate, $context): CampaignAssignment {
            $assignment->status = $to;
            if ($mutate !== null) {
                $mutate($assignment);
            }
            $assignment->save();

            $this->audit->log(
                action: $action,
                actor: $actor,
                subject: $assignment,
                reason: $reason,
                metadata: array_merge(['from' => $from->value, 'to' => $to->value], $context),
            );

            $this->events->dispatch(new AssignmentTransitioned(
                assignment: $assignment,
                from: $from,
                to: $to,
                action: $action,
                triggeredByUserId: $actor?->id,
                context: $context,
            ));

            return $assignment;
        });
    }
}
