<?php

declare(strict_types=1);

use App\Modules\Boards\Enums\MovementTrigger;
use App\Modules\Boards\Models\BoardCard;
use App\Modules\Boards\Models\BoardCardMovement;
use App\Modules\Boards\Services\BoardAutomationService;
use App\Modules\Boards\Services\BoardCardService;
use App\Modules\Boards\Services\BoardService;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** Provision a board + a card sitting in the "Invited" column. */
function seededCardInInvited(): BoardCard
{
    $assignment = CampaignAssignment::factory()->create(['status' => AssignmentStatus::Invited]);
    $board = app(BoardService::class)->ensureBoard($assignment->campaign);

    return app(BoardCardService::class)->forAssignment($board, $assignment)->load('column');
}

it('moves the card to the mapped column + records an event movement (triggered_by=event)', function (): void {
    $card = seededCardInInvited();
    expect($card->column->name)->toBe('Invited');

    $actor = User::factory()->create();

    app(BoardAutomationService::class)->processEvent(
        assignmentId: $card->assignment_id,
        eventKey: 'assignment.draft_approved',
        metadata: [],
        triggeredByUserId: $actor->id,
    );

    $card->refresh()->load('column');
    $movement = BoardCardMovement::query()->where('card_id', $card->id)->latest('id')->firstOrFail();

    expect($card->column->name)->toBe('Approved')
        ->and($movement->triggered_by)->toBe(MovementTrigger::Event)
        ->and($movement->triggered_event_key)->toBe('assignment.draft_approved')
        ->and($movement->triggered_by_user_id)->toBe($actor->id)
        ->and($movement->to_column_id)->toBe($card->column_id);
});

it('is an idempotent no-op when the card is already in the target column (§14.2)', function (): void {
    $card = seededCardInInvited();
    $service = app(BoardAutomationService::class);

    // First fire moves Invited → Approved (1 movement).
    $service->processEvent($card->assignment_id, 'assignment.draft_approved', [], null);
    // Second fire of the same event is a no-op (already in Approved).
    $service->processEvent($card->assignment_id, 'assignment.draft_approved', [], null);

    expect(BoardCardMovement::query()->where('card_id', $card->id)->count())->toBe(1);
});

it('is a no-op when the assignment has no card yet (D-7 belt + suspenders)', function (): void {
    // Board exists, but the assignment has NO card.
    $assignment = CampaignAssignment::factory()->create();
    app(BoardService::class)->ensureBoard($assignment->campaign);

    app(BoardAutomationService::class)->processEvent(
        assignmentId: $assignment->id,
        eventKey: 'assignment.draft_approved',
        metadata: [],
        triggeredByUserId: null,
    );

    expect(BoardCard::query()->where('assignment_id', $assignment->id)->exists())->toBeFalse()
        ->and(BoardCardMovement::query()->count())->toBe(0);
});

it('is a no-op when the campaign has no board', function (): void {
    $assignment = CampaignAssignment::factory()->create();

    app(BoardAutomationService::class)->processEvent(
        assignmentId: $assignment->id,
        eventKey: 'assignment.draft_approved',
        metadata: [],
        triggeredByUserId: null,
    );

    expect(BoardCardMovement::query()->count())->toBe(0);
});

it('does not fire for a disabled automation', function (): void {
    $card = seededCardInInvited();
    $card->board->automations()->where('event_key', 'assignment.draft_approved')->update(['is_enabled' => false]);

    app(BoardAutomationService::class)->processEvent($card->assignment_id, 'assignment.draft_approved', [], null);

    expect(BoardCardMovement::query()->where('card_id', $card->id)->count())->toBe(0);
});
