<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Boards\Enums\MovementTrigger;
use App\Modules\Boards\Models\Board;
use App\Modules\Boards\Models\BoardCard;
use App\Modules\Boards\Models\BoardCardMovement;
use App\Modules\Boards\Models\BoardColumn;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * @return array{0: Agency, 1: User, 2: Campaign, 3: Board}
 */
function boardForDelete(): array
{
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $campaign = Campaign::factory()->create(['agency_id' => $agency->id]);
    test()->actingAs($admin)->getJson("/api/v1/agencies/{$agency->ulid}/campaigns/{$campaign->ulid}/board")->assertOk();
    $board = Board::query()->where('campaign_id', $campaign->id)->firstOrFail();

    return [$agency, $admin, $campaign, $board];
}

function columnUrl(Agency $agency, Campaign $campaign, BoardColumn $column): string
{
    return "/api/v1/agencies/{$agency->ulid}/campaigns/{$campaign->ulid}/board/columns/{$column->ulid}";
}

it('deletes an empty column directly', function (): void {
    [$agency, $admin, $campaign, $board] = boardForDelete();
    $column = $board->columns()->where('name', 'In Review')->firstOrFail();

    test()->actingAs($admin)->deleteJson(columnUrl($agency, $campaign, $column))->assertNoContent();

    expect(BoardColumn::query()->whereKey($column->id)->exists())->toBeFalse()
        ->and($board->columns()->count())->toBe(6);
});

it('rejects deleting a non-empty column without a destination (422)', function (): void {
    [$agency, $admin, $campaign, $board] = boardForDelete();
    $invited = $board->columns()->where('name', 'Invited')->firstOrFail();
    $assignment = CampaignAssignment::factory()->create(['campaign_id' => $campaign->id]);
    BoardCard::factory()->forBoard($board)->inColumn($invited)->forAssignment($assignment)->create();

    test()->actingAs($admin)->deleteJson(columnUrl($agency, $campaign, $invited))->assertStatus(422);

    expect(BoardColumn::query()->whereKey($invited->id)->exists())->toBeTrue();
});

it('re-homes cards as manual movements then deletes the column', function (): void {
    [$agency, $admin, $campaign, $board] = boardForDelete();
    $invited = $board->columns()->where('name', 'Invited')->firstOrFail();
    $posted = $board->columns()->where('name', 'Posted')->firstOrFail();
    $assignment = CampaignAssignment::factory()->create(['campaign_id' => $campaign->id, 'status' => AssignmentStatus::Producing]);
    $card = BoardCard::factory()->forBoard($board)->inColumn($invited)->forAssignment($assignment)->create();

    test()->actingAs($admin)->deleteJson(columnUrl($agency, $campaign, $invited), [
        'destination_column_id' => $posted->ulid,
    ])->assertNoContent();

    expect(BoardColumn::query()->whereKey($invited->id)->exists())->toBeFalse()
        ->and($card->fresh()->column_id)->toBe($posted->id);

    $movement = BoardCardMovement::query()->where('card_id', $card->id)->firstOrFail();
    expect($movement->triggered_by)->toBe(MovementTrigger::User)
        ->and($movement->to_column_id)->toBe($posted->id)
        // from_column was SET NULL by the column delete (history survives, §14.3).
        ->and($movement->from_column_id)->toBeNull();

    // The load-bearing invariant (§4.4 / D-8): re-home goes THROUGH the manual-
    // move path, which never touches the state machine — the assignment status
    // is unchanged by a column delete.
    expect($assignment->fresh()->status)->toBe(AssignmentStatus::Producing);
});

it('refuses to delete the last remaining column (422)', function (): void {
    [$agency, $admin, $campaign, $board] = boardForDelete();

    // Delete down to one column.
    $board->columns()->where('name', '!=', 'To Define')->get()
        ->each(fn (BoardColumn $c) => test()->actingAs($admin)->deleteJson(columnUrl($agency, $campaign, $c))->assertNoContent());

    $last = $board->columns()->firstOrFail();
    test()->actingAs($admin)->deleteJson(columnUrl($agency, $campaign, $last))->assertStatus(422);

    expect($board->columns()->count())->toBe(1);
});
