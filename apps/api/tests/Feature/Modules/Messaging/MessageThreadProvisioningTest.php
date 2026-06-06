<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Events\AssignmentTransitioned;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Messaging\Models\MessageThread;
use App\Modules\Messaging\Services\MessageThreadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Thread provisioning idempotency (D-3) across the three create sites. The
 * invite listener is exercised here via the REAL event (the Event::fake split,
 * §5.2: this half asserts the listener CONSEQUENCE — the thread row — fires
 * when the event is not faked). The producer half — that
 * CampaignAssignmentController::store() dispatches AssignmentTransitioned with
 * AuditAction::AssignmentInvited — is covered by the existing Campaigns suite.
 */
it('provisions a thread when an assignment is invited (real listener fires)', function (): void {
    $assignment = CampaignAssignment::factory()->create();

    AssignmentTransitioned::dispatch(
        $assignment,
        AssignmentStatus::Invited,
        AssignmentStatus::Invited,
        AuditAction::AssignmentInvited,
        null,
    );

    $thread = MessageThread::withoutGlobalScopes()->where('assignment_id', $assignment->id)->first();

    expect($thread)->not->toBeNull()
        ->and($thread?->agency_id)->toBe($assignment->agency_id);
});

it('does not provision a thread on a non-invite transition', function (): void {
    $assignment = CampaignAssignment::factory()->create();

    AssignmentTransitioned::dispatch(
        $assignment,
        AssignmentStatus::Invited,
        AssignmentStatus::Accepted,
        AuditAction::AssignmentAccepted,
        null,
    );

    expect(MessageThread::withoutGlobalScopes()->where('assignment_id', $assignment->id)->exists())->toBeFalse();
});

it('thread provisioning is idempotent — repeated calls return the one canonical row', function (): void {
    $assignment = CampaignAssignment::factory()->create();
    $service = app(MessageThreadService::class);

    $first = $service->forAssignment($assignment);
    $second = $service->forAssignment($assignment);

    expect($second->id)->toBe($first->id)
        ->and(MessageThread::withoutGlobalScopes()->where('assignment_id', $assignment->id)->count())->toBe(1);
});

it('lazy provisioning heals a thread-less assignment (no backfill needed)', function (): void {
    // An assignment that predates the listener has no thread.
    $assignment = CampaignAssignment::factory()->create();
    expect(MessageThread::withoutGlobalScopes()->where('assignment_id', $assignment->id)->exists())->toBeFalse();

    $thread = app(MessageThreadService::class)->forAssignment($assignment);

    expect($thread->exists)->toBeTrue()
        ->and($thread->assignment_id)->toBe($assignment->id);
});

it('human send is blocked on declined / rejected / cancelled but NOT on payment_released (Q2)', function (): void {
    $service = app(MessageThreadService::class);

    foreach ([AssignmentStatus::Declined, AssignmentStatus::Rejected, AssignmentStatus::Cancelled] as $blocked) {
        $assignment = CampaignAssignment::factory()->status($blocked)->create();
        $thread = $service->forAssignment($assignment)->load('assignment');
        expect($thread->humanSendBlocked())->toBeTrue("status {$blocked->value} should block human send");
    }

    // payment_released is terminal for the assignment lifecycle, but messaging
    // stays OPEN for post-delivery wrap-up (Q2).
    $paidOut = CampaignAssignment::factory()->status(AssignmentStatus::PaymentReleased)->create();
    $openThread = $service->forAssignment($paidOut)->load('assignment');
    expect($openThread->humanSendBlocked())->toBeFalse();

    // A live assignment is obviously open.
    $live = CampaignAssignment::factory()->status(AssignmentStatus::Producing)->create();
    $liveThread = $service->forAssignment($live)->load('assignment');
    expect($liveThread->humanSendBlocked())->toBeFalse();
});
