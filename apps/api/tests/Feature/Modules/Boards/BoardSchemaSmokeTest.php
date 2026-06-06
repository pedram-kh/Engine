<?php

declare(strict_types=1);

use App\Modules\Boards\Models\Board;
use App\Modules\Boards\Models\BoardAutomation;
use App\Modules\Boards\Models\BoardCard;
use App\Modules\Boards\Models\BoardCardMovement;
use App\Modules\Boards\Models\BoardColumn;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAssignment;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('builds the full board graph via factories with relations intact', function (): void {
    $board = Board::factory()->create();
    $column = BoardColumn::factory()->forBoard($board)->create();
    $automation = BoardAutomation::factory()->forBoard($board)->target($column)->create();
    $card = BoardCard::factory()->forBoard($board)->inColumn($column)->create();
    $movement = BoardCardMovement::factory()->forCard($card)->create(['to_column_id' => $column->id]);

    expect($board->columns()->count())->toBe(1)
        ->and($board->automations()->count())->toBe(1)
        ->and($board->cards()->count())->toBe(1)
        ->and($automation->targetColumn?->id)->toBe($column->id)
        ->and($card->column?->id)->toBe($column->id)
        ->and($card->movements()->count())->toBe(1)
        ->and($movement->toColumn?->id)->toBe($column->id);
});

it('links a board to its campaign and a card to its assignment (1:1 relations)', function (): void {
    $campaign = Campaign::factory()->create();
    $board = Board::factory()->forCampaign($campaign)->create();
    $assignment = CampaignAssignment::factory()->create(['campaign_id' => $campaign->id]);
    $card = BoardCard::factory()->forBoard($board)->forAssignment($assignment)->create();

    expect($campaign->board?->id)->toBe($board->id)
        ->and($board->campaign?->id)->toBe($campaign->id)
        ->and($assignment->boardCard?->id)->toBe($card->id)
        ->and($card->assignment?->id)->toBe($assignment->id);
});

it('enforces the assignment_id UNIQUE on board_cards', function (): void {
    $assignment = CampaignAssignment::factory()->create();
    BoardCard::factory()->forAssignment($assignment)->create();

    expect(fn () => BoardCard::factory()->forAssignment($assignment)->create())
        ->toThrow(UniqueConstraintViolationException::class);
});

it('append-only movements have no updated_at', function (): void {
    $movement = BoardCardMovement::factory()->create();

    expect($movement->getAttributes())->not->toHaveKey('updated_at');
});
