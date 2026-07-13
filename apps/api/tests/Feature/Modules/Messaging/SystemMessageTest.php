<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Events\AssignmentTransitioned;
use App\Modules\Campaigns\Listeners\WriteSystemMessage;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Campaigns\Services\CampaignAssignmentStateMachine;
use App\Modules\Creators\Models\Contract;
use App\Modules\Messaging\Enums\MessageKind;
use App\Modules\Messaging\Enums\MessageSenderRole;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageThread;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Sprint 11 (S6) — the WriteSystemMessage listener (D-4): the 5th
 * AssignmentTransitioned consumer writes a system message into the thread on
 * curated lifecycle transitions, gated by the SYSTEM_MESSAGE_TRANSITIONS
 * allowlist. system_event_key = the AuditAction verb string; no human sender.
 *
 * §5.2 split: one test asserts the PRODUCER dispatches AssignmentTransitioned
 * (Event::fake); the rest assert the registered LISTENER's effect with the
 * event flowing for real.
 */
function systemMessageAssignment(AssignmentStatus $status = AssignmentStatus::Accepted): CampaignAssignment
{
    return CampaignAssignment::factory()->status($status)->createOne();
}

function dispatchTransition(CampaignAssignment $assignment, AuditAction $action, AssignmentStatus $to): void
{
    event(new AssignmentTransitioned($assignment, $assignment->status, $to, $action));
}

function latestSystemMessage(CampaignAssignment $assignment): ?Message
{
    $thread = MessageThread::withoutGlobalScopes()->where('assignment_id', $assignment->id)->first();
    if ($thread === null) {
        return null;
    }

    return Message::query()
        ->where('thread_id', $thread->id)
        ->where('kind', MessageKind::System->value)
        ->latest('id')
        ->first();
}

// ── Producer half (§5.2) ───────────────────────────────────────────────────

it('a real transition dispatches AssignmentTransitioned (producer)', function (): void {
    Event::fake([AssignmentTransitioned::class]);

    $assignment = systemMessageAssignment(AssignmentStatus::Invited);
    app(CampaignAssignmentStateMachine::class)->decline($assignment);

    Event::assertDispatched(
        AssignmentTransitioned::class,
        static fn (AssignmentTransitioned $e): bool => $e->action === AuditAction::AssignmentDeclined,
    );
});

// ── Listener effect ─────────────────────────────────────────────────────────

it('writes a system message on an allowlisted lifecycle transition + provisions the thread defensively', function (): void {
    $assignment = systemMessageAssignment();
    // A REAL contract is attached — the `contracted` verb renders the
    // contract-signed copy (see the contract-less split below).
    $assignment->contract_id = Contract::factory()->createOne()->id;

    expect(MessageThread::withoutGlobalScopes()->where('assignment_id', $assignment->id)->exists())->toBeFalse();

    dispatchTransition($assignment, AuditAction::AssignmentContracted, AssignmentStatus::Contracted);

    $message = latestSystemMessage($assignment);
    expect($message)->not->toBeNull()
        ->and($message?->kind)->toBe(MessageKind::System)
        ->and($message?->sender_role)->toBe(MessageSenderRole::System)
        ->and($message?->sender_user_id)->toBeNull()
        ->and($message?->body)->toBeNull()
        ->and($message?->system_event_key)->toBe('assignment.contracted');

    $thread = MessageThread::withoutGlobalScopes()->where('assignment_id', $assignment->id)->firstOrFail();
    expect($thread->last_message_at)->not->toBeNull();
});

it('a CONTRACT-LESS contracted advance writes the truthful no-contract key, not the contract-signed one', function (): void {
    // Toggle-off flow (AH-043): a contract-less advance (contract_id === null)
    // must NEVER claim a contract was signed — same Q1 invariant as the
    // notification gate. Covers BOTH the requires=false auto-advance AND the
    // agency's manual proceed-without-contract; both reach here as a
    // contract-less `AssignmentContracted`.
    $assignment = systemMessageAssignment();
    expect($assignment->contract_id)->toBeNull();

    dispatchTransition($assignment, AuditAction::AssignmentContracted, AssignmentStatus::Contracted);

    $message = latestSystemMessage($assignment);
    expect($message)->not->toBeNull()
        ->and($message?->system_event_key)->toBe(WriteSystemMessage::CONTRACTED_WITHOUT_CONTRACT_KEY)
        ->and($message?->system_event_key)->toBe('assignment.contracted_without_contract');
});

it('writes NO system message on a non-allowlisted transition (field churn / payment_funded)', function (): void {
    $assignment = systemMessageAssignment(AssignmentStatus::Contracted);

    dispatchTransition($assignment, AuditAction::AssignmentPaymentFunded, AssignmentStatus::PaymentHeld);

    expect(latestSystemMessage($assignment))->toBeNull();
});

it('STILL writes a system message on the terminal payment_released event (D-13)', function (): void {
    $assignment = systemMessageAssignment(AssignmentStatus::PaymentHeld);

    dispatchTransition($assignment, AuditAction::AssignmentPaymentReleased, AssignmentStatus::PaymentReleased);

    $message = latestSystemMessage($assignment);
    expect($message)->not->toBeNull()
        ->and($message?->system_event_key)->toBe('assignment.payment_released');
});

it('covers every allowlisted verb (catalogue tripwire)', function (): void {
    $expected = [
        'assignment.contracted',
        'assignment.draft_submitted',
        'assignment.draft_approved',
        'assignment.revision_requested',
        'assignment.draft_rejected',
        'assignment.posted_by_creator',
        'assignment.live_verified',
        'assignment.manually_verified',
        'assignment.resubmit_requested',
        'assignment.payment_released',
    ];

    $actual = array_map(
        static fn (AuditAction $a): string => $a->value,
        WriteSystemMessage::SYSTEM_MESSAGE_TRANSITIONS,
    );

    expect($actual)->toBe($expected);
});
