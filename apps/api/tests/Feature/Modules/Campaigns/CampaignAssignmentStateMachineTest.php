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

// ---------------------------------------------------------------------------
// LEGAL transitions — each fires + logs the right verb + stamps the timestamp.
// ---------------------------------------------------------------------------

it('decline: invited → declined logs assignment.declined + stamps responded_at + dispatches the event', function (): void {
    Event::fake([AssignmentTransitioned::class]);
    $assignment = assignmentInStatus(AssignmentStatus::Invited);

    sm()->decline($assignment);

    expect($assignment->fresh()->status)->toBe(AssignmentStatus::Declined)
        ->and($assignment->fresh()->responded_at)->not->toBeNull();

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

    $fresh = $assignment->fresh();
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

    $fresh = $assignment->fresh();
    expect($fresh->status)->toBe(AssignmentStatus::Countered)
        ->and($fresh->countered_fee_minor_units)->toBe(750_000)
        ->and($fresh->countered_fee_currency)->toBe('EUR')
        // The agency's original offer is PRESERVED — never overwritten.
        ->and($fresh->agreed_fee_minor_units)->toBe(500_000)
        ->and($fresh->responded_at)->not->toBeNull();

    $audit = lastAuditFor($assignment, AuditAction::AssignmentCountered);
    expect($audit)->not->toBeNull()
        ->and($audit->metadata['countered_fee_minor_units'])->toBe(750_000);
});

it('contract: accepted → contracted is reachable when contract_signing_enabled is ON', function (): void {
    Feature::define(ContractSigningEnabled::NAME, true);
    $assignment = assignmentInStatus(AssignmentStatus::Accepted);

    sm()->contract($assignment);

    expect($assignment->fresh()->status)->toBe(AssignmentStatus::Contracted);
    expect(lastAuditFor($assignment, AuditAction::AssignmentContracted))->not->toBeNull();
});

it('startProducing: contracted → producing logs assignment.producing', function (): void {
    $assignment = assignmentInStatus(AssignmentStatus::Contracted);

    sm()->startProducing($assignment);

    expect($assignment->fresh()->status)->toBe(AssignmentStatus::Producing);
    expect(lastAuditFor($assignment, AuditAction::AssignmentProducing))->not->toBeNull();
});

it('submitDraft: producing → draft_submitted stamps submitted_draft_at', function (): void {
    $assignment = assignmentInStatus(AssignmentStatus::Producing);

    sm()->submitDraft($assignment);

    $fresh = $assignment->fresh();
    expect($fresh->status)->toBe(AssignmentStatus::DraftSubmitted)
        ->and($fresh->submitted_draft_at)->not->toBeNull();
    expect(lastAuditFor($assignment, AuditAction::AssignmentDraftSubmitted))->not->toBeNull();
});

it('requestRevision: draft_submitted → revision_requested, then the loop back to producing', function (): void {
    $assignment = assignmentInStatus(AssignmentStatus::DraftSubmitted);

    sm()->requestRevision($assignment);
    expect($assignment->fresh()->status)->toBe(AssignmentStatus::RevisionRequested);
    expect(lastAuditFor($assignment, AuditAction::AssignmentRevisionRequested))->not->toBeNull();

    // The review loop: revision_requested → producing.
    sm()->startProducing($assignment);
    expect($assignment->fresh()->status)->toBe(AssignmentStatus::Producing);
});

it('approve: draft_submitted → approved fires the board verb assignment.draft_approved + stamps approved_at', function (): void {
    $assignment = assignmentInStatus(AssignmentStatus::DraftSubmitted);

    sm()->approve($assignment);

    $fresh = $assignment->fresh();
    expect($fresh->status)->toBe(AssignmentStatus::Approved)
        ->and($fresh->approved_at)->not->toBeNull();
    // Verb != landing-state name — it is the board event-key.
    expect(lastAuditFor($assignment, AuditAction::AssignmentDraftApproved))->not->toBeNull();
});

it('markPosted: approved → posted fires the board verb assignment.posted_by_creator + stamps posted_at', function (): void {
    $assignment = assignmentInStatus(AssignmentStatus::Approved);

    sm()->markPosted($assignment);

    $fresh = $assignment->fresh();
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
    expect($assignment->fresh()->status)->toBe(AssignmentStatus::Invited);
});

it('rejects every illegal source → target pair fail-closed', function (string $method, string $from): void {
    $assignment = assignmentInStatus(AssignmentStatus::from($from));
    Feature::define(ContractSigningEnabled::NAME, true);

    expect(fn () => sm()->{$method}($assignment))
        ->toThrow(AssignmentTransitionException::class);

    // No write happened — the source state is preserved.
    expect($assignment->fresh()->status->value)->toBe($from);
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

it('rejects any transition out of a terminal state (declined / payment_released)', function (string $from): void {
    Feature::define(ContractSigningEnabled::NAME, true);

    expect(fn () => sm()->accept(assignmentInStatus(AssignmentStatus::from($from))))
        ->toThrow(AssignmentTransitionException::class);
    expect(fn () => sm()->approve(assignmentInStatus(AssignmentStatus::from($from))))
        ->toThrow(AssignmentTransitionException::class);
})->with([
    'declined' => ['declined'],
    'payment_released' => ['payment_released'],
]);

// ---------------------------------------------------------------------------
// CANCEL — from every non-terminal; rejected from terminal / without reason.
// ---------------------------------------------------------------------------

it('cancels from every non-terminal state → cancelled (stamps reason + actor + logs the reason)', function (string $from): void {
    $assignment = assignmentInStatus(AssignmentStatus::from($from));

    sm()->cancel($assignment, 'Brand pulled the budget');

    $fresh = $assignment->fresh();
    expect($fresh->status)->toBe(AssignmentStatus::Cancelled)
        ->and($fresh->cancelled_at)->not->toBeNull()
        ->and($fresh->cancelled_reason)->toBe('Brand pulled the budget');

    $audit = lastAuditFor($assignment, AuditAction::AssignmentCancelled);
    expect($audit)->not->toBeNull()
        ->and($audit->reason)->toBe('Brand pulled the budget');
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

    expect($assignment->fresh()->status->value)->toBe($from);
})->with([
    'declined' => ['declined'],
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

    expect($assignment->fresh()->status)->toBe(AssignmentStatus::Invited);
});

// ---------------------------------------------------------------------------
// VENDOR-GATED (D-6) — unreachable under own power; no manual path.
// ---------------------------------------------------------------------------

it('verifyLive is vendor-gated (social adapter) — source guard passes but the gate refuses', function (): void {
    Event::fake([AssignmentTransitioned::class]);
    $assignment = assignmentInStatus(AssignmentStatus::Posted);

    try {
        sm()->verifyLive($assignment);
        $this->fail('Expected a vendor gate.');
    } catch (AssignmentTransitionGatedException $e) {
        expect($e->errorCode)->toBe('assignment.social_adapter_unavailable');
    }

    // No state change, no audit, no event — truly unreachable.
    expect($assignment->fresh()->status)->toBe(AssignmentStatus::Posted);
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
    expect($held->fresh()->status)->toBe(AssignmentStatus::LiveVerified);

    $released = assignmentInStatus(AssignmentStatus::PaymentHeld);
    try {
        sm()->releasePayment($released);
        $this->fail('Expected an escrow gate.');
    } catch (AssignmentTransitionGatedException $e) {
        expect($e->errorCode)->toBe('assignment.escrow_unavailable');
    }
    expect($released->fresh()->status)->toBe(AssignmentStatus::PaymentHeld);
});

it('contract is gated when contract_signing_enabled is OFF (no transition)', function (): void {
    Feature::define(ContractSigningEnabled::NAME, false);
    $assignment = assignmentInStatus(AssignmentStatus::Accepted);

    try {
        sm()->contract($assignment);
        $this->fail('Expected a contract-signing gate.');
    } catch (AssignmentTransitionGatedException $e) {
        expect($e->errorCode)->toBe('assignment.contract_signing_disabled');
    }

    expect($assignment->fresh()->status)->toBe(AssignmentStatus::Accepted);
});
