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
use App\Modules\Creators\Features\PerCampaignContractEnabled;
use App\Modules\Creators\Features\SocialVerificationEnabled;
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
 *   declined → invited (re-offer-after-decline — the agency re-opens a
 *     declined invite with a fresh offer; the one edge out of `declined`)
 *   accepted → contracted (flag-gated) → producing → draft_submitted
 *   draft_submitted → {revision_requested → producing(loop), approved, rejected}
 *   approved → posted → live_verified → payment_held → payment_released
 *   any non-terminal → cancelled
 *
 * Sprint 9 Chunk 2: `rejectDraft` (`draft_submitted → rejected`, the new
 * dedicated terminal, D-1/D-3) + `verifyLive` un-gated behind the
 * `social_verification_enabled` flag (D-11).
 *
 * Verification-resolution chunk: the agency's resolution of a FAILED
 * auto-verification (`posted` + `not_found`/`mismatch`) — `manuallyVerify`
 * (`posted → manually_verified`, ACT1/D-4, mandatory reason, the human override)
 * + `returnForResubmit` (`posted → approved`, ACT2/D-5, the fresh-resubmit edge).
 * The in-place nudge (ACT3) is NOT a machine edge (no transition — the agency
 * endpoint only notifies/audits; the creator's in-place URL PATCH re-arms
 * verification).
 *
 * VENDOR/FLAG-GATED — the methods + their source guards exist + are tested.
 * `holdPayment` / `releasePayment` refuse because Stripe escrow (Sprint 10)
 * does not exist yet; there is NO manual path to those states (the footgun
 * guard). `contract` is gated on `per_campaign_contract_enabled` ONLY when a
 * real contract is involved (`$contract !== null`) — a contract-less advance
 * (`$contract === null`, the requires=false toggle-off flow) is permitted
 * regardless of the flag (toggle-off-flow chunk, D1); `verifyLive` is gated on `social_verification_enabled` (the social
 * mock exists — flag-ON + the verification job is the path; flag-OFF stays
 * gated, production-without-adapter safe).
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

    /**
     * declined → invited (re-offer-after-decline chunk). The agency re-opens a
     * DECLINED invitation with a fresh offer. A GUARDED machine edge that lifts
     * `declined` out of its terminal dead-end — the SAME assignment row is
     * re-used (so the existing chat thread + history are preserved), the whole
     * offer is OVERWRITTEN (fee, currency, per-unit, description, attachment),
     * `responded_at` is cleared so the re-opened invite reads clean, and the
     * durable `previously_declined` marker is raised so the agency surface can
     * show a "declined then re-invited" history tag even after the status flips
     * back to `invited`.
     *
     * Distinct from {@see reinvite()} (`countered → invited`, fee-only): this
     * carries the full offer and comes from the invite front-door, not a
     * counter response. Reuses the `assignment.re_invited` audit verb.
     *
     * @param  array{path: string, name: ?string, mime: ?string, size: ?int}|null  $attachment
     */
    public function reofferAfterDecline(
        CampaignAssignment $assignment,
        int $agreedFeeMinorUnits,
        string $agreedFeeCurrency,
        ?string $feePer,
        ?string $offerDescription,
        ?array $attachment,
        ?User $actor = null,
    ): CampaignAssignment {
        $this->assertSource($assignment, [AssignmentStatus::Declined], AssignmentStatus::Invited);

        return $this->commit(
            $assignment,
            AssignmentStatus::Invited,
            AuditAction::AssignmentReInvited,
            $actor,
            mutate: function (CampaignAssignment $a) use ($agreedFeeMinorUnits, $agreedFeeCurrency, $feePer, $offerDescription, $attachment): void {
                $a->agreed_fee_minor_units = $agreedFeeMinorUnits;
                $a->agreed_fee_currency = $agreedFeeCurrency;
                $a->fee_per = $feePer;
                $a->offer_description = $offerDescription;
                $a->offer_attachment_path = $attachment['path'] ?? null;
                $a->offer_attachment_name = $attachment['name'] ?? null;
                $a->offer_attachment_mime = $attachment['mime'] ?? null;
                $a->offer_attachment_size_bytes = $attachment['size'] ?? null;
                $a->responded_at = null;
                $a->previously_declined = true;
            },
            context: [
                'agreed_fee_minor_units' => $agreedFeeMinorUnits,
                'agreed_fee_currency' => $agreedFeeCurrency,
                're_offered_after_decline' => true,
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
     * accepted → contracted. The optional signed addendum is recorded on
     * `contract_id`. A `null` $contract is legal (D-7): a contract-less advance
     * for a campaign that needs no contract — `contract_id` simply stays null,
     * keeping the graph single-edged (`accepted → contracted` always).
     *
     * FLAG POSTURE (toggle-off-flow chunk, D1): `per_campaign_contract_enabled`
     * governs the contract FEATURE (attaching / signing a real per-campaign
     * contract), so it is load-bearing ONLY when a contract is actually involved
     * (`$contract !== null`). A contract-less advance (`$contract === null`) is
     * the ABSENCE of the feature and is permitted REGARDLESS of the flag —
     * "does this campaign need a contract" is the campaign toggle's question,
     * "is the contract feature operational" is the flag's. The two are distinct.
     *
     * `$context` is merged into the transition audit metadata + the dispatched
     * event (D6): a contract-less auto-advance carries `auto_advanced: true` so
     * the audit trail distinguishes it from the agency's manual proceed-without
     * (no such key) and the D4 backfill (`auto_advanced: true, source: backfill`).
     *
     * @param  array<string, mixed>  $context
     */
    public function contract(
        CampaignAssignment $assignment,
        ?Contract $contract = null,
        ?User $actor = null,
        array $context = [],
    ): CampaignAssignment {
        $this->assertSource($assignment, [AssignmentStatus::Accepted], AssignmentStatus::Contracted);

        if ($contract !== null && ! Feature::active(PerCampaignContractEnabled::NAME)) {
            throw AssignmentTransitionGatedException::perCampaignContractDisabled();
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
            context: $context,
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

    /**
     * producing → draft_submitted. The `$context` is merged into the
     * `assignment.draft_submitted` audit metadata so the single
     * machine-written transition row also records the just-created draft's
     * identity (Sprint 9 Chunk 1, D-5): `{draft_id, version, media_count}`.
     * Free text (`caption`) is deliberately NOT threaded — the
     * hand-written-audit discipline (D-3).
     *
     * @param  array<string, mixed>  $context
     */
    public function submitDraft(CampaignAssignment $assignment, ?User $actor = null, array $context = []): CampaignAssignment
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
            context: $context,
        );
    }

    /**
     * draft_submitted → revision_requested (the review loop, D-5). The
     * `$context` carries the reviewed draft's `{draft_id, version}` so the
     * single transition audit row LINKS back to the draft (the Chunk 1
     * context-thread mechanism). The free-text reviewer feedback itself is
     * persisted on the draft's `review_feedback` column by the controller (in
     * the same transaction), NOT snapshotted into the audit metadata (the
     * hand-written-audit / free-text-redaction discipline, D-3).
     *
     * @param  array<string, mixed>  $context
     */
    public function requestRevision(CampaignAssignment $assignment, ?User $actor = null, array $context = []): CampaignAssignment
    {
        $this->assertSource($assignment, [AssignmentStatus::DraftSubmitted], AssignmentStatus::RevisionRequested);

        return $this->commit(
            $assignment,
            AssignmentStatus::RevisionRequested,
            AuditAction::AssignmentRevisionRequested,
            $actor,
            context: $context,
        );
    }

    /**
     * draft_submitted → approved. Fires the board verb `assignment.draft_approved`.
     * `$context` links the approved draft (`{draft_id, version}`).
     *
     * @param  array<string, mixed>  $context
     */
    public function approve(CampaignAssignment $assignment, ?User $actor = null, array $context = []): CampaignAssignment
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
            context: $context,
        );
    }

    /**
     * draft_submitted → rejected (terminal, Sprint 9 Chunk 2, D-1/D-3). The
     * agency's review-time "this draft is not acceptable, end the assignment"
     * outcome — a DEDICATED terminal, distinct from `cancel()` (which fires
     * from any non-terminal). Fail-closed: only `draft_submitted` is a legal
     * source. Fires the net-new board verb `assignment.draft_rejected`.
     *
     * The `$reason` is MANDATORY (the review feedback IS the rejection
     * rationale) — carried in the dedicated audit `reason` field (the cancel
     * precedent), NOT the before/after metadata snapshot. The `$context` links
     * the rejected draft (`{draft_id, version}`); the controller stamps the
     * draft's `review_status = Rejected` + `reviewed_at` + `review_feedback` in
     * the same transaction (no `rejected_at` column on the assignment — the
     * draft trail + the audit row carry the timing).
     *
     * @param  array<string, mixed>  $context
     */
    public function rejectDraft(CampaignAssignment $assignment, string $reason, ?User $actor = null, array $context = []): CampaignAssignment
    {
        $this->assertSource($assignment, [AssignmentStatus::DraftSubmitted], AssignmentStatus::Rejected);

        $trimmed = trim($reason);
        if ($trimmed === '') {
            throw AssignmentTransitionException::reasonRequired();
        }

        return $this->commit(
            $assignment,
            AssignmentStatus::Rejected,
            AuditAction::AssignmentDraftRejected,
            $actor,
            reason: $trimmed,
            context: $context,
        );
    }

    /**
     * approved → posted. The creator self-reports the post (no vendor) —
     * fires the board verb `assignment.posted_by_creator`. The `$context` is
     * merged into the audit metadata so the transition row records the
     * just-created posted-content row: `{posted_content_id, platform}`
     * (Sprint 9 Chunk 1, D-7). The free-text `post_url` is NOT threaded (D-3).
     *
     * @param  array<string, mixed>  $context
     */
    public function markPosted(CampaignAssignment $assignment, ?User $actor = null, array $context = []): CampaignAssignment
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
            context: $context,
        );
    }

    /**
     * posted → live_verified (Sprint 9 Chunk 2, D-11). FLAG-GATED on
     * `social_verification_enabled` (mirrors `contract()` on
     * `contract_signing_enabled`): when the flag is OFF the transition throws
     * the vendor-gated exception — production without a real social adapter
     * stays gated, and there is NO manual path (the footgun guard, the
     * break-revert anchor). When the flag is ON the transition commits; the
     * caller is the `VerifyPostedContentJob` (D-10), which only reaches here
     * after the verification provider confirms the post (mock today, a real
     * Meta/TikTok/YouTube adapter later). Stamps `verified_live_at` + fires the
     * board verb `assignment.live_verified` (Sprint 10's payment trigger).
     *
     * The `$context` links the verified posted-content row
     * (`{posted_content_id, platform_post_id}`).
     *
     * @param  array<string, mixed>  $context
     */
    public function verifyLive(CampaignAssignment $assignment, ?User $actor = null, array $context = []): CampaignAssignment
    {
        $this->assertSource($assignment, [AssignmentStatus::Posted], AssignmentStatus::LiveVerified);

        if (! Feature::active(SocialVerificationEnabled::NAME)) {
            throw AssignmentTransitionGatedException::socialAdapterUnavailable();
        }

        return $this->commit(
            $assignment,
            AssignmentStatus::LiveVerified,
            AuditAction::AssignmentLiveVerified,
            $actor,
            mutate: function (CampaignAssignment $a): void {
                $a->verified_live_at = now();
            },
            context: $context,
        );
    }

    /**
     * posted → manually_verified (verification-resolution chunk, ACT1, D-4). The
     * agency's MANUAL OVERRIDE of a FAILED auto-verification: the assignment
     * stayed `posted` after the job set `not_found`/`mismatch`, and the agency
     * judges the post acceptable anyway. Fail-closed: only `posted` is a legal
     * source. Fires the net-new board verb `assignment.manually_verified` —
     * EXPLICITLY distinct from `assignment.live_verified` ("a real pass"): the
     * audit row is the human-override trail (who/when/why).
     *
     * The `$reason` is MANDATORY (D-4 — the override must answer "why was this
     * paid despite failing verification") — carried in the dedicated audit
     * `reason` field (the cancel/reject precedent), NOT the metadata snapshot.
     *
     * `manually_verified` is NON-terminal and PAYMENT-ELIGIBLE alongside
     * `live_verified` ({@see AssignmentStatus::isPaymentEligible()}): S10's
     * release-gate (docs/20-PHASE-1-SPEC.md §6.8) + auto-release listener MUST
     * consume that predicate, NOT the literal `live_verified` string, so this
     * override does not dead-end at payment. Stamps `verified_live_at` (the
     * verification-complete timestamp; the override distinction lives in the
     * status + the distinct verb, not a separate column — no migration, D-1).
     *
     * @param  array<string, mixed>  $context
     */
    public function manuallyVerify(CampaignAssignment $assignment, string $reason, ?User $actor = null, array $context = []): CampaignAssignment
    {
        $this->assertSource($assignment, [AssignmentStatus::Posted], AssignmentStatus::ManuallyVerified);

        $trimmed = trim($reason);
        if ($trimmed === '') {
            throw AssignmentTransitionException::reasonRequired();
        }

        return $this->commit(
            $assignment,
            AssignmentStatus::ManuallyVerified,
            AuditAction::AssignmentManuallyVerified,
            $actor,
            reason: $trimmed,
            mutate: function (CampaignAssignment $a): void {
                $a->verified_live_at = now();
            },
            context: $context,
        );
    }

    /**
     * posted → approved (verification-resolution chunk, ACT2, D-5). The agency
     * sends a FAILED-verification assignment BACK so the creator submits a FRESH
     * post (re-using the existing `approved → submit-post-URL` creator surface
     * verbatim). The existing `campaign_posted_content` row is KEPT as history —
     * the failed post is part of the story; the verification job orders by id so
     * the new row becomes active. Fail-closed: only `posted` is a legal source.
     * Fires the net-new board verb `assignment.resubmit_requested` (a card moving
     * back to "approved" — distinct from the in-place nudge, ACT3, which has no
     * edge). Optional `$feedback` rides the notification (not the audit snapshot,
     * the free-text discipline, D-3).
     *
     * @param  array<string, mixed>  $context
     */
    public function returnForResubmit(CampaignAssignment $assignment, ?User $actor = null, array $context = []): CampaignAssignment
    {
        $this->assertSource($assignment, [AssignmentStatus::Posted], AssignmentStatus::Approved);

        return $this->commit(
            $assignment,
            AssignmentStatus::Approved,
            AuditAction::AssignmentResubmitRequested,
            $actor,
            context: $context,
        );
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
