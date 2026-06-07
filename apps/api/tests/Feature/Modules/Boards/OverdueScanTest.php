<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Boards\Enums\BoardAutomationActionType;
use App\Modules\Boards\Enums\MovementTrigger;
use App\Modules\Boards\Models\Board;
use App\Modules\Boards\Models\BoardAutomation;
use App\Modules\Boards\Models\BoardCard;
use App\Modules\Boards\Models\BoardCardMovement;
use App\Modules\Boards\Models\BoardColumn;
use App\Modules\Boards\Services\BoardService;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Sprint 12 Chunk 3 (D-3/D-4/D-5/D-6) — the daily overdue sweep
 * (`boards:scan-overdue`), the app's SECOND scheduled command. It fires the two
 * time-triggered board events DIRECTLY via processEvent (no synthetic
 * AssignmentTransitioned), with a *_overdue_flagged_at one-shot marker, across
 * ALL agencies with per-card tenant self-resolution.
 *
 * Defaults seed NO overdue automation (an overdue key is inert until an agency
 * maps it), so each fixture wires one explicitly — the agency-config scenario.
 *
 * @return array{agency: Agency, campaign: Campaign, assignment: CampaignAssignment, board: Board, card: BoardCard, target: ?BoardColumn}
 */
function overdueFixture(array $assignmentAttrs, ?string $eventKey = null, AssignmentStatus $status = AssignmentStatus::Invited): array
{
    $agency = Agency::factory()->createOne();
    $campaign = Campaign::factory()->create(['agency_id' => $agency->id]);
    $assignment = CampaignAssignment::factory()->create(array_merge([
        'agency_id' => $agency->id,
        'campaign_id' => $campaign->id,
        'status' => $status,
    ], $assignmentAttrs));

    // Lazily provision the board + heal the card (the board-GET path, D-4).
    app(BoardService::class)->forCampaign($campaign);
    $board = Board::query()->where('campaign_id', $campaign->id)->firstOrFail();
    $card = BoardCard::query()->where('assignment_id', $assignment->id)->firstOrFail();

    $target = null;
    if ($eventKey !== null) {
        // Map the overdue event → "Cancelled" (distinct from where an Invited
        // card sits, so a fire is an observable move).
        $target = $board->columns()->where('name', 'Cancelled')->firstOrFail();
        BoardAutomation::query()->create([
            'board_id' => $board->id,
            'event_key' => $eventKey,
            'action_type' => BoardAutomationActionType::MoveToColumn,
            'target_column_id' => $target->id,
            'condition' => null,
            'is_enabled' => true,
        ]);
    }

    return compact('agency', 'campaign', 'assignment', 'board', 'card', 'target');
}

it('registers boards:scan-overdue as a daily scheduled command (the app second schedule)', function (): void {
    $this->artisan('schedule:list')
        ->expectsOutputToContain('boards:scan-overdue')
        ->assertExitCode(0);
});

it('fires posting_overdue for an overdue assignment with a mapped automation (card moves + event movement)', function (): void {
    ['assignment' => $assignment, 'card' => $card, 'target' => $target] = overdueFixture(
        ['posting_due_at' => now()->subDay()],
        'assignment.posting_overdue',
    );
    assert($target !== null);

    $this->artisan('boards:scan-overdue')->assertExitCode(0);

    $card->refresh();
    expect($card->column_id)->toBe($target->id);

    $movement = BoardCardMovement::query()
        ->where('card_id', $card->id)
        ->where('triggered_event_key', 'assignment.posting_overdue')
        ->firstOrFail();

    expect($movement->triggered_by)->toBe(MovementTrigger::Event)
        ->and($movement->triggered_by_user_id)->toBeNull()
        ->and($movement->to_column_id)->toBe($target->id);

    // The one-shot marker is stamped (D-4).
    expect($assignment->refresh()->posting_overdue_flagged_at)->not->toBeNull();
});

it('is a one-shot in steady state — two scans produce exactly one overdue movement', function (): void {
    ['card' => $card] = overdueFixture(
        ['posting_due_at' => now()->subDay()],
        'assignment.posting_overdue',
    );

    $this->artisan('boards:scan-overdue')->assertExitCode(0);
    $this->artisan('boards:scan-overdue')->assertExitCode(0);

    expect(
        BoardCardMovement::query()
            ->where('card_id', $card->id)
            ->where('triggered_event_key', 'assignment.posting_overdue')
            ->count(),
    )->toBe(1);
});

/**
 * ⚠ The load-bearing one-shot test (D-4) — kept SEPARATE from steady state. The
 * engine's already-in-target no-op covers steady state; the *_overdue_flagged_at
 * marker is what covers the DRAGGED-OUT case: a human drags the card out of the
 * overdue column while still overdue, and the next daily scan must NOT re-fire
 * (which the already-in-target no-op alone would, fabricating a new movement
 * daily).
 */
it('does NOT re-fire after the card is dragged OUT of the overdue column (the flagged_at one-shot, not just already-in-target)', function (): void {
    ['board' => $board, 'card' => $card, 'target' => $target] = overdueFixture(
        ['posting_due_at' => now()->subDay()],
        'assignment.posting_overdue',
    );
    assert($target !== null);

    // First scan fires: card → target, marker stamped.
    $this->artisan('boards:scan-overdue')->assertExitCode(0);
    expect($card->refresh()->column_id)->toBe($target->id);

    // A human drags the card OUT of the overdue column (still overdue).
    $invited = $board->columns()->where('name', 'Invited')->firstOrFail();
    $card->update(['column_id' => $invited->id]);

    // Second scan: the flagged_at gate must prevent a second fire — even though
    // the card is no longer in the target (so already-in-target would NOT save us).
    $this->artisan('boards:scan-overdue')->assertExitCode(0);

    $card->refresh();
    expect(
        BoardCardMovement::query()
            ->where('card_id', $card->id)
            ->where('triggered_event_key', 'assignment.posting_overdue')
            ->count(),
    )->toBe(1)
        ->and($card->column_id)->toBe($invited->id);
});

it('skips posting_overdue when posting_due_at is NULL (skip nulls)', function (): void {
    ['assignment' => $assignment, 'card' => $card] = overdueFixture(
        ['posting_due_at' => null],
        'assignment.posting_overdue',
    );

    $this->artisan('boards:scan-overdue')->assertExitCode(0);

    expect($assignment->refresh()->posting_overdue_flagged_at)->toBeNull()
        ->and(BoardCardMovement::query()->where('card_id', $card->id)->count())->toBe(0);
});

it('fires draft_overdue when draft_due_at is set and passed (the net-new field end-to-end)', function (): void {
    ['assignment' => $assignment, 'card' => $card, 'target' => $target] = overdueFixture(
        ['draft_due_at' => now()->subDay()],
        'assignment.draft_overdue',
    );
    assert($target !== null);

    $this->artisan('boards:scan-overdue')->assertExitCode(0);

    expect($card->refresh()->column_id)->toBe($target->id)
        ->and($assignment->refresh()->draft_overdue_flagged_at)->not->toBeNull();

    $movement = BoardCardMovement::query()
        ->where('card_id', $card->id)
        ->where('triggered_event_key', 'assignment.draft_overdue')
        ->firstOrFail();
    expect($movement->triggered_by)->toBe(MovementTrigger::Event);
});

it('skips draft_overdue when draft_due_at is NULL (capable-but-inert until a deadline is set)', function (): void {
    ['assignment' => $assignment, 'card' => $card] = overdueFixture(
        ['draft_due_at' => null],
        'assignment.draft_overdue',
    );

    $this->artisan('boards:scan-overdue')->assertExitCode(0);

    expect($assignment->refresh()->draft_overdue_flagged_at)->toBeNull()
        ->and(BoardCardMovement::query()->where('card_id', $card->id)->count())->toBe(0);
});

/**
 * ⚠ Cross-agency ABSENCE (D-6, the MessageDigestTest mirror). The sweep is a
 * deliberate global query, but per-card isolation is structural: each card is
 * handed to processEvent which self-resolves ITS board's automation config —
 * agency A's overdue automation can never move agency B's card.
 */
it("does NOT let agency A's overdue automation fire on agency B's card (cross-agency absence)", function (): void {
    // A has an overdue automation mapped → A's "Cancelled".
    ['assignment' => $assignmentA, 'card' => $cardA, 'target' => $targetA] = overdueFixture(
        ['posting_due_at' => now()->subDay()],
        'assignment.posting_overdue',
    );
    assert($targetA !== null);

    // B has an overdue assignment + card but NO overdue automation of its own.
    ['assignment' => $assignmentB, 'card' => $cardB] = overdueFixture(
        ['posting_due_at' => now()->subDay()],
        null,
    );
    $originalColumnB = $cardB->column_id;

    $this->artisan('boards:scan-overdue')->assertExitCode(0);

    // A's card moved (A's automation fired on A's card)…
    expect($cardA->refresh()->column_id)->toBe($targetA->id);

    // …but B's card is UNCHANGED — A's automation never reached it.
    expect($cardB->refresh()->column_id)->toBe($originalColumnB)
        ->and(BoardCardMovement::query()->where('card_id', $cardB->id)->count())->toBe(0);

    // Both assignments are flagged (the global sweep fired for both; B's
    // processEvent no-opped for want of a mapped automation).
    expect($assignmentA->refresh()->posting_overdue_flagged_at)->not->toBeNull()
        ->and($assignmentB->refresh()->posting_overdue_flagged_at)->not->toBeNull();
});
