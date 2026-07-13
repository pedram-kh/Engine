<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Events\AssignmentTransitioned;
use App\Modules\Campaigns\Exceptions\AssignmentTransitionException;
use App\Modules\Campaigns\Exceptions\AssignmentTransitionGatedException;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Campaigns\Services\CampaignAssignmentStateMachine;
use App\Modules\Creators\Features\ContractSigningEnabled;
use App\Modules\Creators\Features\PerCampaignContractEnabled;
use App\Modules\Creators\Features\SocialVerificationEnabled;
use App\Modules\Creators\Models\Contract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Pennant\Feature;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * The heaviest surface (D-5/D-6). Weighted: every legal transition fires +
 * logs the board-key verb + stamps the right timestamp + dispatches its
 * event; every illegal transition is rejected fail-closed; cancel from every
 * non-terminal works (and is rejected from terminal / without a reason);
 * vendor-gated transitions are unreachable under own power.
 */
function sm(): CampaignAssignmentStateMachine
{
    return app(CampaignAssignmentStateMachine::class);
}

function assignmentInStatus(AssignmentStatus $status): CampaignAssignment
{
    return CampaignAssignment::factory()->status($status)->create();
}

function lastAuditFor(CampaignAssignment $assignment, AuditAction $action): ?AuditLog
{
    return AuditLog::query()
        ->where('action', $action->value)
        ->where('subject_type', $assignment->getMorphClass())
        ->where('subject_id', $assignment->id)
        ->latest('id')
        ->first();
}

/** Reload from the DB, narrowed non-null (the row always exists in these tests). */
function reload(CampaignAssignment $assignment): CampaignAssignment
{
    return $assignment->fresh() ?? $assignment;
}

// ---------------------------------------------------------------------------
// LEGAL transitions — each fires + logs the right verb + stamps the timestamp.
// ---------------------------------------------------------------------------

it('decline: invited → declined logs assignment.declined + stamps responded_at + dispatches the event', function (): void {
    Event::fake([AssignmentTransitioned::class]);
    $assignment = assignmentInStatus(AssignmentStatus::Invited);

    sm()->decline($assignment);

    expect(reload($assignment)->status)->toBe(AssignmentStatus::Declined)
        ->and(reload($assignment)->responded_at)->not->toBeNull();

    expect(lastAuditFor($assignment, AuditAction::AssignmentDeclined))->not->toBeNull();

    Event::assertDispatched(
        AssignmentTransitioned::class,
        fn (AssignmentTransitioned $e): bool => $e->eventKey() === 'assignment.declined'
            && $e->metadata()['from'] === 'invited'
            && $e->metadata()['to'] === 'declined',
    );
});

it('accept: invited → accepted logs assignment.accepted + stamps responded_at + accepted_at', function (): void {
    $assignment = assignmentInStatus(AssignmentStatus::Invited);

    sm()->accept($assignment);

    $fresh = reload($assignment);
    expect($fresh->status)->toBe(AssignmentStatus::Accepted)
        ->and($fresh->responded_at)->not->toBeNull()
        ->and($fresh->accepted_at)->not->toBeNull();
    expect(lastAuditFor($assignment, AuditAction::AssignmentAccepted))->not->toBeNull();
});

it('counter: invited → countered records countered_fee and NOT agreed_fee (D-7)', function (): void {
    $assignment = CampaignAssignment::factory()->status(AssignmentStatus::Invited)->create([
        'agreed_fee_minor_units' => 500_000,
        'agreed_fee_currency' => 'EUR',
    ]);

    sm()->counter($assignment, 750_000, 'EUR');

    $fresh = reload($assignment);
    expect($fresh->status)->toBe(AssignmentStatus::Countered)
        ->and($fresh->countered_fee_minor_units)->toBe(750_000)
        ->and($fresh->countered_fee_currency)->toBe('EUR')
        // The agency's original offer is PRESERVED — never overwritten.
        ->and($fresh->agreed_fee_minor_units)->toBe(500_000)
        ->and($fresh->responded_at)->not->toBeNull();

    $audit = lastAuditFor($assignment, AuditAction::AssignmentCountered);
    expect($audit)->not->toBeNull();
    expect(($audit->metadata ?? [])['countered_fee_minor_units'] ?? null)->toBe(750_000);
});

it('reofferAfterDecline: declined → invited overwrites the whole offer, clears responded_at, sets previously_declined', function (): void {
    Event::fake([AssignmentTransitioned::class]);
    $assignment = CampaignAssignment::factory()->status(AssignmentStatus::Declined)->create([
        'agreed_fee_minor_units' => 200_00,
        'agreed_fee_currency' => 'EUR',
        'fee_per' => 'per post',
        'offer_description' => 'Old brief.',
        'offer_attachment_path' => 'agencies/OLD/campaigns/OLD/offer-attachments/OLD.pdf',
        'offer_attachment_name' => 'old.pdf',
        'responded_at' => now()->subDay(),
    ]);

    sm()->reofferAfterDecline(
        $assignment,
        350_00,
        'EUR',
        'per script',
        'Revised brief.',
        ['path' => 'agencies/NEW/campaigns/NEW/offer-attachments/NEW.pdf', 'name' => 'new.pdf', 'mime' => 'application/pdf', 'size' => 2048],
    );

    $fresh = reload($assignment);
    expect($fresh->status)->toBe(AssignmentStatus::Invited)
        ->and($fresh->agreed_fee_minor_units)->toBe(350_00)
        ->and($fresh->fee_per)->toBe('per script')
        ->and($fresh->offer_description)->toBe('Revised brief.')
        ->and($fresh->offer_attachment_name)->toBe('new.pdf')
        ->and($fresh->previously_declined)->toBeTrue()
        ->and($fresh->responded_at)->toBeNull();

    expect(lastAuditFor($assignment, AuditAction::AssignmentReInvited))->not->toBeNull();

    Event::assertDispatched(
        AssignmentTransitioned::class,
        fn (AssignmentTransitioned $e): bool => $e->eventKey() === 'assignment.re_invited'
            && $e->metadata()['from'] === 'declined'
            && $e->metadata()['to'] === 'invited',
    );
});

it('reofferAfterDecline: a non-declined source throws invalid_transition (fail-closed)', function (): void {
    $assignment = assignmentInStatus(AssignmentStatus::Accepted);

    expect(fn () => sm()->reofferAfterDecline($assignment, 350_00, 'EUR', null, null, null))
        ->toThrow(AssignmentTransitionException::class);

    expect(reload($assignment)->status)->toBe(AssignmentStatus::Accepted);
});

it('contract: accepted → contracted is reachable when per_campaign_contract_enabled is ON', function (): void {
    Feature::define(PerCampaignContractEnabled::NAME, true);
    $assignment = assignmentInStatus(AssignmentStatus::Accepted);

    sm()->contract($assignment);

    expect(reload($assignment)->status)->toBe(AssignmentStatus::Contracted);
    expect(lastAuditFor($assignment, AuditAction::AssignmentContracted))->not->toBeNull();
});

it('contract: accepted → contracted with a null contract leaves contract_id null (the proceed-without path, D-7)', function (): void {
    Feature::define(PerCampaignContractEnabled::NAME, true);
    $assignment = assignmentInStatus(AssignmentStatus::Accepted);

    sm()->contract($assignment, null);

    $fresh = reload($assignment);
    expect($fresh->status)->toBe(AssignmentStatus::Contracted)
        ->and($fresh->contract_id)->toBeNull();
    expect(lastAuditFor($assignment, AuditAction::AssignmentContracted))->not->toBeNull();
});

it('startProducing: contracted → producing logs assignment.producing', function (): void {
    $assignment = assignmentInStatus(AssignmentStatus::Contracted);

    sm()->startProducing($assignment);

    expect(reload($assignment)->status)->toBe(AssignmentStatus::Producing);
    expect(lastAuditFor($assignment, AuditAction::AssignmentProducing))->not->toBeNull();
});

it('submitDraft: producing → draft_submitted stamps submitted_draft_at', function (): void {
    $assignment = assignmentInStatus(AssignmentStatus::Producing);

    sm()->submitDraft($assignment);

    $fresh = reload($assignment);
    expect($fresh->status)->toBe(AssignmentStatus::DraftSubmitted)
        ->and($fresh->submitted_draft_at)->not->toBeNull();
    expect(lastAuditFor($assignment, AuditAction::AssignmentDraftSubmitted))->not->toBeNull();
});

it('requestRevision: draft_submitted → revision_requested, then the loop back to producing', function (): void {
    $assignment = assignmentInStatus(AssignmentStatus::DraftSubmitted);

    sm()->requestRevision($assignment);
    expect(reload($assignment)->status)->toBe(AssignmentStatus::RevisionRequested);
    expect(lastAuditFor($assignment, AuditAction::AssignmentRevisionRequested))->not->toBeNull();

    // The review loop: revision_requested → producing.
    sm()->startProducing($assignment);
    expect(reload($assignment)->status)->toBe(AssignmentStatus::Producing);
});

it('approve: draft_submitted → approved fires the board verb assignment.draft_approved + stamps approved_at', function (): void {
    $assignment = assignmentInStatus(AssignmentStatus::DraftSubmitted);

    sm()->approve($assignment);

    $fresh = reload($assignment);
    expect($fresh->status)->toBe(AssignmentStatus::Approved)
        ->and($fresh->approved_at)->not->toBeNull();
    // Verb != landing-state name — it is the board event-key.
    expect(lastAuditFor($assignment, AuditAction::AssignmentDraftApproved))->not->toBeNull();
});

it('markPosted: approved → posted fires the board verb assignment.posted_by_creator + stamps posted_at', function (): void {
    $assignment = assignmentInStatus(AssignmentStatus::Approved);

    sm()->markPosted($assignment);

    $fresh = reload($assignment);
    expect($fresh->status)->toBe(AssignmentStatus::Posted)
        ->and($fresh->posted_at)->not->toBeNull();
    expect(lastAuditFor($assignment, AuditAction::AssignmentPostedByCreator))->not->toBeNull();
});

// ---------------------------------------------------------------------------
// ILLEGAL transitions — the fail-closed matrix (the load-bearing pin).
// ---------------------------------------------------------------------------

it('rejects invited → contracted (skipping accept) with a typed invalid_transition', function (): void {
    $assignment = assignmentInStatus(AssignmentStatus::Invited);
    Feature::define(ContractSigningEnabled::NAME, true);

    expect(fn () => sm()->contract($assignment))
        ->toThrow(AssignmentTransitionException::class);

    try {
        sm()->contract(assignmentInStatus(AssignmentStatus::Invited));
    } catch (AssignmentTransitionException $e) {
        expect($e->errorCode)->toBe('assignment.invalid_transition');
    }

    // Fail-closed: status unchanged, no audit, no event.
    expect(reload($assignment)->status)->toBe(AssignmentStatus::Invited);
});

it('rejects every illegal source → target pair fail-closed', function (string $method, string $from): void {
    $assignment = assignmentInStatus(AssignmentStatus::from($from));
    Feature::define(ContractSigningEnabled::NAME, true);

    expect(fn () => sm()->{$method}($assignment))
        ->toThrow(AssignmentTransitionException::class);

    // No write happened — the source state is preserved.
    expect(reload($assignment)->status->value)->toBe($from);
})->with([
    // accept only legal from invited
    'accept from accepted' => ['accept', 'accepted'],
    'accept from declined' => ['accept', 'declined'],
    'accept from contracted' => ['accept', 'contracted'],
    // decline only legal from invited
    'decline from accepted' => ['decline', 'accepted'],
    'decline from declined (terminal)' => ['decline', 'declined'],
    // contract only legal from accepted
    'contract from invited' => ['contract', 'invited'],
    'contract from producing' => ['contract', 'producing'],
    // startProducing only legal from contracted/revision_requested
    'startProducing from invited' => ['startProducing', 'invited'],
    'startProducing from accepted' => ['startProducing', 'accepted'],
    // submitDraft only legal from producing
    'submitDraft from contracted' => ['submitDraft', 'contracted'],
    'submitDraft from approved' => ['submitDraft', 'approved'],
    // approve only legal from draft_submitted
    'approve from producing' => ['approve', 'producing'],
    'approve from accepted' => ['approve', 'accepted'],
    // requestRevision only legal from draft_submitted
    'requestRevision from producing' => ['requestRevision', 'producing'],
    // markPosted only legal from approved
    'markPosted from producing' => ['markPosted', 'producing'],
    'markPosted from draft_submitted' => ['markPosted', 'draft_submitted'],
]);

it('rejects any transition out of a terminal state (declined / rejected / payment_released)', function (string $from): void {
    Feature::define(ContractSigningEnabled::NAME, true);

    expect(fn () => sm()->accept(assignmentInStatus(AssignmentStatus::from($from))))
        ->toThrow(AssignmentTransitionException::class);
    expect(fn () => sm()->approve(assignmentInStatus(AssignmentStatus::from($from))))
        ->toThrow(AssignmentTransitionException::class);
})->with([
    'declined' => ['declined'],
    // Sprint 9 Chunk 2 (D-1) — the new dedicated terminal has no edge out.
    'rejected' => ['rejected'],
    'payment_released' => ['payment_released'],
]);

// ---------------------------------------------------------------------------
// CANCEL — from every non-terminal; rejected from terminal / without reason.
// ---------------------------------------------------------------------------

it('cancels from every non-terminal state → cancelled (stamps reason + actor + logs the reason)', function (string $from): void {
    $assignment = assignmentInStatus(AssignmentStatus::from($from));

    sm()->cancel($assignment, 'Brand pulled the budget');

    $fresh = reload($assignment);
    expect($fresh->status)->toBe(AssignmentStatus::Cancelled)
        ->and($fresh->cancelled_at)->not->toBeNull()
        ->and($fresh->cancelled_reason)->toBe('Brand pulled the budget');

    $audit = lastAuditFor($assignment, AuditAction::AssignmentCancelled);
    expect($audit)->not->toBeNull()
        ->and($audit?->reason)->toBe('Brand pulled the budget');
})->with([
    'invited' => ['invited'],
    'countered' => ['countered'],
    'accepted' => ['accepted'],
    'contracted' => ['contracted'],
    'producing' => ['producing'],
    'draft_submitted' => ['draft_submitted'],
    'revision_requested' => ['revision_requested'],
    'approved' => ['approved'],
    'posted' => ['posted'],
    'live_verified' => ['live_verified'],
    'payment_held' => ['payment_held'],
]);

it('rejects cancelling a terminal assignment', function (string $from): void {
    $assignment = assignmentInStatus(AssignmentStatus::from($from));

    try {
        sm()->cancel($assignment, 'too late');
        $this->fail('Expected a terminal-cancel rejection.');
    } catch (AssignmentTransitionException $e) {
        expect($e->errorCode)->toBe('assignment.terminal');
    }

    expect(reload($assignment)->status->value)->toBe($from);
})->with([
    'declined' => ['declined'],
    'rejected' => ['rejected'],
    'payment_released' => ['payment_released'],
    'cancelled' => ['cancelled'],
]);

it('rejects cancel without a reason', function (): void {
    $assignment = assignmentInStatus(AssignmentStatus::Invited);

    try {
        sm()->cancel($assignment, '   ');
        $this->fail('Expected a reason-required rejection.');
    } catch (AssignmentTransitionException $e) {
        expect($e->errorCode)->toBe('assignment.reason_required');
    }

    expect(reload($assignment)->status)->toBe(AssignmentStatus::Invited);
});

// ---------------------------------------------------------------------------
// REJECT (Sprint 9 Chunk 2, D-1/D-3) — the new dedicated terminal edge.
// ---------------------------------------------------------------------------

it('rejectDraft: draft_submitted → rejected (terminal) fires assignment.draft_rejected with the reason', function (): void {
    Event::fake([AssignmentTransitioned::class]);
    $assignment = assignmentInStatus(AssignmentStatus::DraftSubmitted);

    sm()->rejectDraft($assignment, 'Off-brief and low quality', context: ['draft_id' => 'd_123', 'version' => 1]);

    $fresh = reload($assignment);
    expect($fresh->status)->toBe(AssignmentStatus::Rejected)
        ->and($fresh->status->isTerminal())->toBeTrue();

    $audit = lastAuditFor($assignment, AuditAction::AssignmentDraftRejected);
    expect($audit)->not->toBeNull()
        ->and($audit?->reason)->toBe('Off-brief and low quality')
        // The reason rides the dedicated reason field, NOT the metadata snapshot.
        ->and($audit?->metadata['draft_id'] ?? null)->toBe('d_123')
        ->and($audit?->metadata)->not->toHaveKey('review_feedback');

    Event::assertDispatched(
        AssignmentTransitioned::class,
        fn (AssignmentTransitioned $e): bool => $e->eventKey() === 'assignment.draft_rejected'
            && $e->metadata()['from'] === 'draft_submitted'
            && $e->metadata()['to'] === 'rejected',
    );
});

it('rejectDraft rejects an empty reason (reason required)', function (): void {
    $assignment = assignmentInStatus(AssignmentStatus::DraftSubmitted);

    try {
        sm()->rejectDraft($assignment, '   ');
        $this->fail('Expected a reason-required rejection.');
    } catch (AssignmentTransitionException $e) {
        expect($e->errorCode)->toBe('assignment.reason_required');
    }

    expect(reload($assignment)->status)->toBe(AssignmentStatus::DraftSubmitted);
});

it('rejectDraft fails closed from a non-draft_submitted source', function (string $from): void {
    $assignment = assignmentInStatus(AssignmentStatus::from($from));

    try {
        sm()->rejectDraft($assignment, 'nope');
        $this->fail('Expected an invalid-transition rejection.');
    } catch (AssignmentTransitionException $e) {
        expect($e->errorCode)->toBe('assignment.invalid_transition');
    }

    expect(reload($assignment)->status->value)->toBe($from);
})->with([
    'producing' => ['producing'],
    'approved' => ['approved'],
    'revision_requested' => ['revision_requested'],
    'posted' => ['posted'],
]);

// ---------------------------------------------------------------------------
// VERIFY LIVE (Sprint 9 Chunk 2, D-11) — flag-gated; ON commits, OFF refuses.
// ---------------------------------------------------------------------------

it('verifyLive: posted → live_verified when social_verification_enabled is ON (stamps verified_live_at + fires the verb)', function (): void {
    Feature::define(SocialVerificationEnabled::NAME, true);
    Event::fake([AssignmentTransitioned::class]);
    $assignment = assignmentInStatus(AssignmentStatus::Posted);

    sm()->verifyLive($assignment, context: ['posted_content_id' => 'pc_1', 'platform_post_id' => 'mock_post_abc']);

    $fresh = reload($assignment);
    expect($fresh->status)->toBe(AssignmentStatus::LiveVerified)
        ->and($fresh->verified_live_at)->not->toBeNull();
    expect(lastAuditFor($assignment, AuditAction::AssignmentLiveVerified))->not->toBeNull();

    Event::assertDispatched(
        AssignmentTransitioned::class,
        fn (AssignmentTransitioned $e): bool => $e->eventKey() === 'assignment.live_verified'
            && ($e->metadata()['posted_content_id'] ?? null) === 'pc_1',
    );
});

// ---------------------------------------------------------------------------
// VERIFICATION-RESOLUTION — manual override + the fresh-resubmit edge.
// ---------------------------------------------------------------------------

it('manuallyVerify: posted → manually_verified fires assignment.manually_verified (a DISTINCT override verb) with the reason + stamps verified_live_at', function (): void {
    Event::fake([AssignmentTransitioned::class]);
    $assignment = assignmentInStatus(AssignmentStatus::Posted);

    sm()->manuallyVerify($assignment, 'Reviewed the link manually — the post is live and on-brief');

    $fresh = reload($assignment);
    expect($fresh->status)->toBe(AssignmentStatus::ManuallyVerified)
        ->and($fresh->status->isTerminal())->toBeFalse()
        ->and($fresh->status->isPaymentEligible())->toBeTrue()
        ->and($fresh->verified_live_at)->not->toBeNull();

    // The override verb is DISTINCT from live_verified ("a real pass") — no
    // live_verified row was written.
    $audit = lastAuditFor($assignment, AuditAction::AssignmentManuallyVerified);
    expect($audit)->not->toBeNull()
        ->and($audit?->reason)->toBe('Reviewed the link manually — the post is live and on-brief');
    expect(lastAuditFor($assignment, AuditAction::AssignmentLiveVerified))->toBeNull();

    Event::assertDispatched(
        AssignmentTransitioned::class,
        fn (AssignmentTransitioned $e): bool => $e->eventKey() === 'assignment.manually_verified'
            && $e->metadata()['from'] === 'posted'
            && $e->metadata()['to'] === 'manually_verified',
    );
});

it('manuallyVerify rejects an empty reason (reason required)', function (): void {
    $assignment = assignmentInStatus(AssignmentStatus::Posted);

    try {
        sm()->manuallyVerify($assignment, '   ');
        $this->fail('Expected a reason-required rejection.');
    } catch (AssignmentTransitionException $e) {
        expect($e->errorCode)->toBe('assignment.reason_required');
    }

    expect(reload($assignment)->status)->toBe(AssignmentStatus::Posted);
});

it('returnForResubmit: posted → approved fires assignment.resubmit_requested (the fresh-resubmit edge)', function (): void {
    Event::fake([AssignmentTransitioned::class]);
    $assignment = assignmentInStatus(AssignmentStatus::Posted);

    sm()->returnForResubmit($assignment);

    expect(reload($assignment)->status)->toBe(AssignmentStatus::Approved);
    expect(lastAuditFor($assignment, AuditAction::AssignmentResubmitRequested))->not->toBeNull();

    Event::assertDispatched(
        AssignmentTransitioned::class,
        fn (AssignmentTransitioned $e): bool => $e->eventKey() === 'assignment.resubmit_requested'
            && $e->metadata()['from'] === 'posted'
            && $e->metadata()['to'] === 'approved',
    );
});

it('manuallyVerify + returnForResubmit fail closed from a non-posted source', function (string $method, string $from): void {
    $assignment = assignmentInStatus(AssignmentStatus::from($from));

    try {
        $method === 'manuallyVerify'
            ? sm()->manuallyVerify($assignment, 'reason')
            : sm()->returnForResubmit($assignment);
        $this->fail('Expected an invalid-transition rejection.');
    } catch (AssignmentTransitionException $e) {
        expect($e->errorCode)->toBe('assignment.invalid_transition');
    }

    expect(reload($assignment)->status->value)->toBe($from);
})->with([
    'manuallyVerify from approved' => ['manuallyVerify', 'approved'],
    'manuallyVerify from draft_submitted' => ['manuallyVerify', 'draft_submitted'],
    'manuallyVerify from live_verified' => ['manuallyVerify', 'live_verified'],
    'returnForResubmit from approved' => ['returnForResubmit', 'approved'],
    'returnForResubmit from producing' => ['returnForResubmit', 'producing'],
]);

// ---------------------------------------------------------------------------
// VENDOR / FLAG-GATED — unreachable under own power; no manual path.
// ---------------------------------------------------------------------------

it('verifyLive is vendor-gated (social adapter) — source guard passes but the gate refuses', function (): void {
    // The gate is the flag: pin it OFF to exercise the refusal path. (The flag
    // now defaults ON under the mock driver, so the default no longer gates —
    // the gate itself is what's under test here.)
    Feature::define(SocialVerificationEnabled::NAME, false);
    Event::fake([AssignmentTransitioned::class]);
    $assignment = assignmentInStatus(AssignmentStatus::Posted);

    try {
        sm()->verifyLive($assignment);
        $this->fail('Expected a vendor gate.');
    } catch (AssignmentTransitionGatedException $e) {
        expect($e->errorCode)->toBe('assignment.social_adapter_unavailable');
    }

    // No state change, no audit, no event — truly unreachable.
    expect(reload($assignment)->status)->toBe(AssignmentStatus::Posted);
    expect(lastAuditFor($assignment, AuditAction::AssignmentLiveVerified))->toBeNull();
    Event::assertNotDispatched(AssignmentTransitioned::class);
});

it('verifyLive from a wrong source throws invalid_transition (proving the source guard exists)', function (): void {
    $assignment = assignmentInStatus(AssignmentStatus::Approved);

    try {
        sm()->verifyLive($assignment);
        $this->fail('Expected an invalid-transition rejection.');
    } catch (AssignmentTransitionGatedException $e) {
        $this->fail('Should have failed the source guard before the vendor gate.');
    } catch (AssignmentTransitionException $e) {
        expect($e->errorCode)->toBe('assignment.invalid_transition');
    }
});

it('holdPayment + releasePayment are escrow-gated (Sprint 10) — no manual path to payment states', function (): void {
    $held = assignmentInStatus(AssignmentStatus::LiveVerified);
    try {
        sm()->holdPayment($held);
        $this->fail('Expected an escrow gate.');
    } catch (AssignmentTransitionGatedException $e) {
        expect($e->errorCode)->toBe('assignment.escrow_unavailable');
    }
    expect(reload($held)->status)->toBe(AssignmentStatus::LiveVerified);

    $released = assignmentInStatus(AssignmentStatus::PaymentHeld);
    try {
        sm()->releasePayment($released);
        $this->fail('Expected an escrow gate.');
    } catch (AssignmentTransitionGatedException $e) {
        expect($e->errorCode)->toBe('assignment.escrow_unavailable');
    }
    expect(reload($released)->status)->toBe(AssignmentStatus::PaymentHeld);
});

// §5.35 break-revert — Direction A: the flag gate is load-bearing ONLY when a
// real contract is involved. Flag OFF + a real contract still refuses.
it('contract is gated when per_campaign_contract_enabled is OFF AND a real contract is passed', function (): void {
    Feature::define(PerCampaignContractEnabled::NAME, false);
    $assignment = assignmentInStatus(AssignmentStatus::Accepted);
    $contract = Contract::factory()->create();

    try {
        sm()->contract($assignment, $contract);
        $this->fail('Expected a per-campaign-contract gate.');
    } catch (AssignmentTransitionGatedException $e) {
        expect($e->errorCode)->toBe('assignment.per_campaign_contract_disabled');
    }

    expect(reload($assignment)->status)->toBe(AssignmentStatus::Accepted);
});

// §5.35 break-revert — Direction B: a contract-less advance ($contract === null)
// is the ABSENCE of the contract feature and is permitted regardless of the
// flag (toggle-off-flow chunk, D1). Restoring the unconditional flag gate
// reddens this spec.
it('contract: a null (contract-less) advance succeeds even when per_campaign_contract_enabled is OFF (toggle-off flow, D1)', function (): void {
    Feature::define(PerCampaignContractEnabled::NAME, false);
    $assignment = assignmentInStatus(AssignmentStatus::Accepted);

    sm()->contract($assignment, null);

    $fresh = reload($assignment);
    expect($fresh->status)->toBe(AssignmentStatus::Contracted)
        ->and($fresh->contract_id)->toBeNull();
    expect(lastAuditFor($assignment, AuditAction::AssignmentContracted))->not->toBeNull();
});

// D6 — the $context threads into the transition audit metadata so the three
// contract-less paths (accept-chained auto-advance, D4 backfill, agency manual
// proceed) are distinguishable in the audit trail.
it('contract: the $context is merged into the transition audit metadata (D6)', function (): void {
    $assignment = assignmentInStatus(AssignmentStatus::Accepted);

    sm()->contract($assignment, null, null, ['auto_advanced' => true]);

    $audit = lastAuditFor($assignment, AuditAction::AssignmentContracted);
    expect($audit)->not->toBeNull()
        ->and($audit?->metadata['auto_advanced'] ?? null)->toBeTrue();
});
