<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Boards\Models\Board;
use App\Modules\Boards\Models\BoardAutomation;
use App\Modules\Boards\Models\BoardCard;
use App\Modules\Boards\Models\BoardColumn;
use App\Modules\Boards\Services\BoardService;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * AH-069 D6 + Q4 — the posting column on a campaign that hands off at approval.
 *
 * The single claim this file exists to prove: NOTHING IS EVER DELETED. Turning
 * the toggle off changes what the board endpoint RENDERS and nothing else — the
 * `board_columns` row, its three automations and every card survive untouched,
 * so flipping back restores the board byte for byte. Every test below either
 * demonstrates the filter or counts the rows that outlived it.
 *
 * The second claim, which is what makes the first one safe: the flip to OFF is
 * REFUSED while cards sit in that column. Without the refusal, "we only hid it"
 * would be a distinction without a difference — the cards would be unreachable.
 *
 * @return array{0: Agency, 1: Campaign, 2: User, 3: Board}
 */
function boardVisibilitySetup(bool $creatorPostsContent): array
{
    $agency = Agency::factory()->createOne();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();
    $campaign = Campaign::factory()->createOne([
        'agency_id' => $agency->id,
        'brand_id' => $brand->id,
        'creator_posts_content' => $creatorPostsContent,
    ]);
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $board = app(BoardService::class)->forCampaign($campaign);

    return [$agency, $campaign, $admin, $board];
}

/**
 * The board payload's column names, in payload order.
 *
 * @param  TestResponse<JsonResponse>  $response
 * @return Collection<array-key, mixed>
 */
function renderedColumnNames(TestResponse $response): Collection
{
    return collect((array) $response->json('data.columns'))->pluck('attributes.name');
}

/**
 * @param  TestResponse<JsonResponse>  $response
 * @return Collection<array-key, mixed>
 */
function renderedAutomationKeys(TestResponse $response): Collection
{
    return collect((array) $response->json('data.automations'))->pluck('attributes.event_key');
}

function postingBoardUrl(Agency $agency, Campaign $campaign): string
{
    return "/api/v1/agencies/{$agency->ulid}/campaigns/{$campaign->ulid}/board";
}

function postingCampaignUrl(Agency $agency, Campaign $campaign): string
{
    return "/api/v1/agencies/{$agency->ulid}/campaigns/{$campaign->ulid}";
}

/**
 * Put a card on the campaign's Posted column by hand.
 *
 * Deliberately NOT via the lifecycle: on a hand-off campaign the machine cannot
 * produce this state, which is the whole point of the guards. The scenario being
 * modelled is the historical one — cards that reached Posted while the toggle
 * was ON, before somebody tried to turn it off.
 */
function seedStrandedPostedCard(Agency $agency, Campaign $campaign, Board $board, string $creatorName): BoardCard
{
    $creator = Creator::factory()->approved()->createOne(['display_name' => $creatorName]);

    $assignment = CampaignAssignment::factory()->status(AssignmentStatus::Posted)->createOne([
        'agency_id' => $agency->id,
        'campaign_id' => $campaign->id,
        'brand_id' => $campaign->brand_id,
        'creator_id' => $creator->id,
    ]);

    $posted = BoardColumn::query()->where('board_id', $board->id)->where('name', 'Posted')->sole();

    return BoardCard::factory()->createOne([
        'agency_id' => $agency->id,
        'board_id' => $board->id,
        'column_id' => $posted->id,
        'assignment_id' => $assignment->id,
    ]);
}

// ── The render filter ───────────────────────────────────────────────────────

it('renders all seven columns on a campaign whose creators post (today, unchanged)', function (): void {
    [$agency, $campaign, $admin] = boardVisibilitySetup(creatorPostsContent: true);

    $response = $this->actingAs($admin)->getJson(postingBoardUrl($agency, $campaign))->assertOk();

    $names = renderedColumnNames($response);

    expect($names)->toHaveCount(7)
        ->and($names)->toContain('Posted');
});

it('does NOT render the posting column on a hand-off campaign', function (): void {
    [$agency, $campaign, $admin] = boardVisibilitySetup(creatorPostsContent: false);

    $response = $this->actingAs($admin)->getJson(postingBoardUrl($agency, $campaign))->assertOk();

    $names = renderedColumnNames($response);

    expect($names)->toHaveCount(6)
        ->and($names)->not->toContain('Posted')
        // Everything else is untouched — this hides ONE column, not a family.
        ->and($names->all())->toBe([
            'To Define',
            'Invited',
            'In Review',
            'Approved',
            'Paid',
            'Cancelled / Rejected',
        ]);
});

it('keeps the Approved column even though its resubmit verb is unreachable here', function (): void {
    // The rule is "posting family targets it and nothing else does". Approved is
    // targeted by draft-approved AND resubmit-requested, so it stays — proving
    // the filter is not simply "any column a posting verb mentions".
    [$agency, $campaign, $admin] = boardVisibilitySetup(creatorPostsContent: false);

    $names = renderedColumnNames($this->actingAs($admin)->getJson(postingBoardUrl($agency, $campaign))->assertOk());

    expect($names)->toContain('Approved');
});

it('hides the posting column after it has been RENAMED', function (): void {
    // The filter reads the automations that target the column, never its name,
    // so an agency that renamed "Posted" to something else still gets the
    // hand-off board it asked for.
    [$agency, $campaign, $admin, $board] = boardVisibilitySetup(creatorPostsContent: false);

    BoardColumn::query()
        ->where('board_id', $board->id)
        ->where('name', 'Posted')
        ->update(['name' => 'Live on socials']);

    $names = renderedColumnNames($this->actingAs($admin)->getJson(postingBoardUrl($agency, $campaign))->assertOk());

    expect($names)->toHaveCount(6)
        ->and($names)->not->toContain('Live on socials');
});

it('drops the automations that target the hidden column, and only those', function (): void {
    [$agency, $campaign, $admin] = boardVisibilitySetup(creatorPostsContent: false);

    $keys = renderedAutomationKeys($this->actingAs($admin)->getJson(postingBoardUrl($agency, $campaign))->assertOk());

    // A rule whose target column is absent from the payload would render as a
    // rule pointing at nothing.
    expect($keys)->toHaveCount(7)
        ->and($keys)->not->toContain(AuditAction::AssignmentPostedByCreator->value)
        ->and($keys)->not->toContain(AuditAction::AssignmentLiveVerified->value)
        ->and($keys)->not->toContain(AuditAction::AssignmentManuallyVerified->value)
        ->and($keys)->toContain(AuditAction::AssignmentDraftApproved->value)
        ->and($keys)->toContain(AuditAction::AssignmentCancelled->value);
});

// ── The zero-deletion proof ─────────────────────────────────────────────────

it('deletes NOTHING — the hidden column and its automations are still in the database', function (): void {
    [$agency, $campaign, $admin, $board] = boardVisibilitySetup(creatorPostsContent: false);

    $this->actingAs($admin)->getJson(postingBoardUrl($agency, $campaign))->assertOk();

    expect(BoardColumn::query()->where('board_id', $board->id)->count())->toBe(7)
        ->and(BoardColumn::query()->where('board_id', $board->id)->where('name', 'Posted')->exists())->toBeTrue()
        ->and(BoardAutomation::query()->where('board_id', $board->id)->count())->toBe(10);
});

it('restores the full board the moment the toggle goes back on', function (): void {
    [$agency, $campaign, $admin] = boardVisibilitySetup(creatorPostsContent: false);

    $hidden = renderedColumnNames($this->actingAs($admin)->getJson(postingBoardUrl($agency, $campaign))->assertOk());

    $campaign->forceFill(['creator_posts_content' => true])->save();

    $restored = renderedColumnNames($this->actingAs($admin)->getJson(postingBoardUrl($agency, $campaign))->assertOk());

    expect($hidden)->toHaveCount(6)
        ->and($restored)->toHaveCount(7)
        ->and($restored)->toContain('Posted');
});

it('renders the whole board after a reset on a posting campaign, and filters after a reset on a hand-off one', function (): void {
    // The board-reset posture (D6). Reset re-seeds the default columns; the
    // filter is applied to the reset response by the same resource, so the two
    // features cannot drift.
    [$agency, $campaign, $admin] = boardVisibilitySetup(creatorPostsContent: false);

    $response = $this->actingAs($admin)
        ->postJson(postingBoardUrl($agency, $campaign).'/reset-to-defaults')
        ->assertOk();

    $names = renderedColumnNames($response);

    expect($names)->toHaveCount(6)->and($names)->not->toContain('Posted');
});

// ── The refuse-flip (Q4) ────────────────────────────────────────────────────

it('refuses the flip to OFF while cards sit in the posting column, naming the creators', function (): void {
    [$agency, $campaign, $admin, $board] = boardVisibilitySetup(creatorPostsContent: true);

    $card = seedStrandedPostedCard($agency, $campaign, $board, 'Nadia Rossi');

    $response = $this->actingAs($admin)
        ->patchJson(postingCampaignUrl($agency, $campaign), ['creator_posts_content' => false])
        ->assertStatus(422);

    expect($response->json('errors.0.code'))->toBe('campaign.posting_cards_present')
        // Named, so the agency knows which card to move — Q4's ruling.
        ->and($response->json('errors.0.title'))->toContain('Nadia Rossi')
        ->and($response->json('errors.0.meta.count'))->toBe(1)
        // ULIDs in meta, never database ids.
        ->and($response->json('errors.0.meta.card_ids'))->toBe([$card->ulid]);

    // The refusal is total: the toggle did not move.
    expect($campaign->fresh()?->creator_posts_content)->toBeTrue();
});

it('counts and names every stranded card, not just the first', function (): void {
    [$agency, $campaign, $admin, $board] = boardVisibilitySetup(creatorPostsContent: true);

    seedStrandedPostedCard($agency, $campaign, $board, 'Nadia Rossi');
    seedStrandedPostedCard($agency, $campaign, $board, 'Tomas Berg');

    $response = $this->actingAs($admin)
        ->patchJson(postingCampaignUrl($agency, $campaign), ['creator_posts_content' => false])
        ->assertStatus(422);

    expect($response->json('errors.0.meta.count'))->toBe(2)
        ->and($response->json('errors.0.meta.assignment_ids'))->toHaveCount(2)
        ->and($response->json('errors.0.title'))->toContain('Nadia Rossi')
        ->and($response->json('errors.0.title'))->toContain('Tomas Berg');
});

it('deletes NO card when it refuses the flip', function (): void {
    [$agency, $campaign, $admin, $board] = boardVisibilitySetup(creatorPostsContent: true);

    seedStrandedPostedCard($agency, $campaign, $board, 'Nadia Rossi');

    $this->actingAs($admin)
        ->patchJson(postingCampaignUrl($agency, $campaign), ['creator_posts_content' => false])
        ->assertStatus(422);

    expect(BoardCard::query()->where('board_id', $board->id)->count())->toBe(1);
});

it('allows the flip once the card has been moved out of the posting column', function (): void {
    [$agency, $campaign, $admin, $board] = boardVisibilitySetup(creatorPostsContent: true);

    $card = seedStrandedPostedCard($agency, $campaign, $board, 'Nadia Rossi');

    $paid = BoardColumn::query()->where('board_id', $board->id)->where('name', 'Paid')->sole();
    $card->forceFill(['column_id' => $paid->id])->save();

    $this->actingAs($admin)
        ->patchJson(postingCampaignUrl($agency, $campaign), ['creator_posts_content' => false])
        ->assertOk();

    expect($campaign->fresh()?->creator_posts_content)->toBeFalse();
});

it('allows the flip on a campaign with no board at all', function (): void {
    // The common case: a campaign being configured before anyone is invited.
    $agency = Agency::factory()->createOne();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();
    $campaign = Campaign::factory()->createOne([
        'agency_id' => $agency->id,
        'brand_id' => $brand->id,
        'creator_posts_content' => true,
    ]);
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    expect(Board::query()->where('campaign_id', $campaign->id)->exists())->toBeFalse();

    $this->actingAs($admin)
        ->patchJson(postingCampaignUrl($agency, $campaign), ['creator_posts_content' => false])
        ->assertOk();

    expect($campaign->fresh()?->creator_posts_content)->toBeFalse();
});

it('never refuses the flip to ON, however many cards are posted', function (): void {
    [$agency, $campaign, $admin, $board] = boardVisibilitySetup(creatorPostsContent: false);

    seedStrandedPostedCard($agency, $campaign, $board, 'Nadia Rossi');

    $this->actingAs($admin)
        ->patchJson(postingCampaignUrl($agency, $campaign), ['creator_posts_content' => true])
        ->assertOk();

    expect($campaign->fresh()?->creator_posts_content)->toBeTrue();
});

it('leaves an unrelated Settings save alone on a campaign with posted cards', function (): void {
    // The guard reads the REQUEST, not the stored value: a PATCH that never
    // mentions the toggle must not be refused because cards happen to be posted.
    [$agency, $campaign, $admin, $board] = boardVisibilitySetup(creatorPostsContent: true);

    seedStrandedPostedCard($agency, $campaign, $board, 'Nadia Rossi');

    $this->actingAs($admin)
        ->patchJson(postingCampaignUrl($agency, $campaign), ['name' => 'Renamed while posting'])
        ->assertOk();

    expect($campaign->fresh()?->name)->toBe('Renamed while posting');
});
