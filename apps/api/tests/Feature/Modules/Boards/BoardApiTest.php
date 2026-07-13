<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Boards\Models\Board;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function boardUrl(Agency $agency, Campaign $campaign, string $suffix = ''): string
{
    return "/api/v1/agencies/{$agency->ulid}/campaigns/{$campaign->ulid}/board{$suffix}";
}

/**
 * @return array{0: Agency, 1: User, 2: Campaign}
 */
function agencyCampaign(): array
{
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $campaign = Campaign::factory()->create(['agency_id' => $agency->id]);

    return [$agency, $admin, $campaign];
}

it('GET board lazy-provisions the board + columns + automations + heals cards', function (): void {
    [$agency, $admin, $campaign] = agencyCampaign();
    CampaignAssignment::factory()->count(2)->create(['campaign_id' => $campaign->id]);

    $response = $this->actingAs($admin)->getJson(boardUrl($agency, $campaign));

    $response->assertOk()
        ->assertJsonPath('data.type', 'boards')
        ->assertJsonCount(7, 'data.columns')
        ->assertJsonCount(10, 'data.automations')
        ->assertJsonCount(2, 'data.cards');

    expect(Board::query()->where('campaign_id', $campaign->id)->count())->toBe(1);
});

it('GET board emits previously_declined on the card assignment (re-offer-after-decline chunk)', function (): void {
    [$agency, $admin, $campaign] = agencyCampaign();
    CampaignAssignment::factory()->create([
        'campaign_id' => $campaign->id,
        'status' => AssignmentStatus::Invited,
        'previously_declined' => true,
    ]);

    $this->actingAs($admin)->getJson(boardUrl($agency, $campaign))
        ->assertOk()
        ->assertJsonPath('data.cards.0.relationships.assignment.data.previously_declined', true);
});

it('GET board emits the offer fee + signed avatar on the card assignment (board-card facelift)', function (): void {
    [$agency, $admin, $campaign] = agencyCampaign();
    CampaignAssignment::factory()->create([
        'campaign_id' => $campaign->id,
        'status' => AssignmentStatus::Invited,
        'agreed_fee_minor_units' => 20000,
        'agreed_fee_currency' => 'EUR',
        'fee_per' => 'script',
    ]);

    $this->actingAs($admin)->getJson(boardUrl($agency, $campaign))
        ->assertOk()
        ->assertJsonPath('data.cards.0.relationships.assignment.data.agreed_fee_minor_units', 20000)
        ->assertJsonPath('data.cards.0.relationships.assignment.data.agreed_fee_currency', 'EUR')
        ->assertJsonPath('data.cards.0.relationships.assignment.data.fee_per', 'script')
        // Signed avatar is minted here; null on the local (non-S3) test disk,
        // but the key is present so the SPA can rely on the shape.
        ->assertJsonPath('data.cards.0.relationships.assignment.data.creator.avatar_url', null);
});

it('GET board is idempotent across polls (no duplicate rows)', function (): void {
    [$agency, $admin, $campaign] = agencyCampaign();
    CampaignAssignment::factory()->create(['campaign_id' => $campaign->id]);

    $this->actingAs($admin)->getJson(boardUrl($agency, $campaign))->assertOk();
    $this->actingAs($admin)->getJson(boardUrl($agency, $campaign))->assertOk();

    $board = Board::query()->where('campaign_id', $campaign->id)->firstOrFail();
    expect($board->columns()->count())->toBe(7)
        ->and($board->cards()->count())->toBe(1);
});

it('creates a column at the end of the board', function (): void {
    [$agency, $admin, $campaign] = agencyCampaign();
    $this->actingAs($admin)->getJson(boardUrl($agency, $campaign))->assertOk();

    $response = $this->actingAs($admin)->postJson(boardUrl($agency, $campaign, '/columns'), [
        'name' => 'Blocked',
        'color_token' => 'status-blocked',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.attributes.name', 'Blocked')
        ->assertJsonPath('data.attributes.position', 8);
});

it('rejects a color token outside the design-system palette', function (): void {
    [$agency, $admin, $campaign] = agencyCampaign();
    $this->actingAs($admin)->getJson(boardUrl($agency, $campaign))->assertOk();

    $this->actingAs($admin)->postJson(boardUrl($agency, $campaign, '/columns'), [
        'name' => 'Custom',
        'color_token' => 'status-rainbow',
    ])->assertStatus(422);
});

it('renames a column and recolors it', function (): void {
    [$agency, $admin, $campaign] = agencyCampaign();
    $this->actingAs($admin)->getJson(boardUrl($agency, $campaign))->assertOk();
    $board = Board::query()->where('campaign_id', $campaign->id)->firstOrFail();
    $column = $board->columns()->where('name', 'To Define')->firstOrFail();

    $this->actingAs($admin)->patchJson(boardUrl($agency, $campaign, "/columns/{$column->ulid}"), [
        'name' => 'Backlog',
        'color_token' => 'status-progress',
    ])->assertOk()
        ->assertJsonPath('data.attributes.name', 'Backlog')
        ->assertJsonPath('data.attributes.color_token', 'status-progress');
});

it('swaps the terminal-success flag when a second column is marked (§7.5)', function (): void {
    [$agency, $admin, $campaign] = agencyCampaign();
    $this->actingAs($admin)->getJson(boardUrl($agency, $campaign))->assertOk();
    $board = Board::query()->where('campaign_id', $campaign->id)->firstOrFail();
    $invited = $board->columns()->where('name', 'Invited')->firstOrFail();

    $this->actingAs($admin)->patchJson(boardUrl($agency, $campaign, "/columns/{$invited->ulid}"), [
        'is_terminal_success' => true,
    ])->assertOk();

    // The previous terminal-success ("Paid") was swapped off; only Invited holds it.
    expect($board->columns()->where('is_terminal_success', true)->pluck('name')->all())->toBe(['Invited']);
});

it('reorders columns and reassigns positions 1..n', function (): void {
    [$agency, $admin, $campaign] = agencyCampaign();
    $this->actingAs($admin)->getJson(boardUrl($agency, $campaign))->assertOk();
    $board = Board::query()->where('campaign_id', $campaign->id)->firstOrFail();

    $ulids = $board->columns()->orderBy('position')->pluck('ulid')->all();
    $reversed = array_reverse($ulids);

    $this->actingAs($admin)->patchJson(boardUrl($agency, $campaign, '/columns/reorder'), [
        'column_ids' => $reversed,
    ])->assertOk();

    $newOrder = $board->columns()->orderBy('position')->pluck('ulid')->all();
    expect($newOrder)->toBe($reversed)
        ->and($board->columns()->orderBy('position')->pluck('position')->all())->toBe([1, 2, 3, 4, 5, 6, 7]);
});

it('rejects a reorder list that does not match the board columns', function (): void {
    [$agency, $admin, $campaign] = agencyCampaign();
    $this->actingAs($admin)->getJson(boardUrl($agency, $campaign))->assertOk();

    $this->actingAs($admin)->patchJson(boardUrl($agency, $campaign, '/columns/reorder'), [
        'column_ids' => ['01JQXNOTAREALULID0000000000'],
    ])->assertStatus(422);
});

it('lists automations and updates one (disable + retarget)', function (): void {
    [$agency, $admin, $campaign] = agencyCampaign();
    $this->actingAs($admin)->getJson(boardUrl($agency, $campaign))->assertOk();
    $board = Board::query()->where('campaign_id', $campaign->id)->firstOrFail();

    $this->actingAs($admin)->getJson(boardUrl($agency, $campaign, '/automations'))
        ->assertOk()
        ->assertJsonCount(10, 'data');

    $automation = $board->automations()->where('event_key', 'assignment.draft_approved')->firstOrFail();
    $posted = $board->columns()->where('name', 'Posted')->firstOrFail();

    $this->actingAs($admin)->patchJson(boardUrl($agency, $campaign, "/automations/{$automation->ulid}"), [
        'is_enabled' => false,
        'target_column_id' => $posted->ulid,
    ])->assertOk()
        ->assertJsonPath('data.attributes.is_enabled', false)
        ->assertJsonPath('data.attributes.target_column_id', $posted->ulid);
});

it('staff cannot configure columns (update gate is admin + manager)', function (): void {
    [$agency, , $campaign] = agencyCampaign();
    $staff = User::factory()->agencyStaff($agency)->createOne();
    $this->actingAs($staff)->getJson(boardUrl($agency, $campaign))->assertOk();

    $this->actingAs($staff)->postJson(boardUrl($agency, $campaign, '/columns'), [
        'name' => 'Nope',
        'color_token' => 'status-blocked',
    ])->assertForbidden();
});
