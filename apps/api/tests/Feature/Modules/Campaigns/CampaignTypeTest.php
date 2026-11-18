<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Enums\CampaignType;
use App\Modules\Campaigns\Exceptions\CampaignBrandChangeException;
use App\Modules\Campaigns\Exceptions\CampaignTypeChangeException;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\WaveFixtures;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * PL-1.2 sub-step 1 — the campaign type (C1, C2), the migration (S1), the brand guard (G1, P8) and the
 * zero-budget guard (WB1). Every assertion that matters reads the PERSISTED ROW (the AH-054 catch).
 */

/** @return array{agency: Agency, admin: User, manager: User, staff: User, brand: Brand} */
function typeFixture(): array
{
    $agency = Agency::factory()->createOne();

    return [
        'agency' => $agency,
        'admin' => User::factory()->agencyAdmin($agency)->createOne(),
        'manager' => User::factory()->agencyManager($agency)->createOne(),
        'staff' => User::factory()->agencyStaff($agency)->createOne(),
        'brand' => Brand::factory()->forAgency($agency->id)->createOne(),
    ];
}

/** @param array<string, mixed> $over  @return array<string, mixed> */
function typePayload(Brand $brand, array $over = []): array
{
    return ['brand_id' => $brand->ulid, 'name' => 'Type test', 'budget_minor_units' => 0, 'budget_currency' => 'EUR', ...$over];
}

function typeUrl(Agency $agency, string $suffix = ''): string
{
    return "/api/v1/agencies/{$agency->ulid}/campaigns".($suffix !== '' ? '/'.$suffix : '');
}

it('C1: a campaign created without a type is normal, and its posting default is untouched', function (): void {
    $f = typeFixture();

    $res = $this->actingAs($f['admin'])->postJson(typeUrl($f['agency']), typePayload($f['brand']))->assertCreated();

    $row = Campaign::query()->firstOrFail();
    expect($row->campaign_type)->toBe(CampaignType::Normal)
        ->and($row->creator_posts_content)->toBeTrue()
        ->and($res->json('data.attributes.campaign_type'))->toBe('normal');
});

it('C2: with the flag OFF a for_wave create is 422 campaign.wave_type_disabled and writes no row', function (): void {
    $f = typeFixture();

    $this->actingAs($f['admin'])->postJson(typeUrl($f['agency']), typePayload($f['brand'], ['campaign_type' => 'for_wave']))
        ->assertUnprocessable()->assertJsonPath('errors.0.code', 'campaign.wave_type_disabled');

    expect(Campaign::query()->count())->toBe(0);
});

it('C2: with the flag ON a for_wave create persists the type and forces posting off, even when the body asks for it', function (): void {
    $f = typeFixture();
    WaveFixtures::armed();

    $res = $this->actingAs($f['admin'])
        ->postJson(typeUrl($f['agency']), typePayload($f['brand'], ['campaign_type' => 'for_wave', 'creator_posts_content' => true]))
        ->assertCreated();

    $row = Campaign::query()->firstOrFail();
    expect($row->campaign_type)->toBe(CampaignType::ForWave)
        ->and($row->creator_posts_content)->toBeFalse()
        ->and($row->status->value)->toBe('draft')
        ->and($res->json('data.attributes.campaign_type'))->toBe('for_wave');
});

it('C2: an unknown type is a validation error', function (): void {
    $f = typeFixture();
    WaveFixtures::armed();

    $this->actingAs($f['admin'])->postJson(typeUrl($f['agency']), typePayload($f['brand'], ['campaign_type' => 'bogus']))
        ->assertUnprocessable();
    expect(Campaign::query()->count())->toBe(0);
});

it('C1: the type is immutable: a different value is 422 campaign.type_immutable, the same value is a no-op', function (): void {
    $f = typeFixture();
    WaveFixtures::armed();
    $normal = Campaign::factory()->forAgency($f['agency']->id)->createOne(['brand_id' => $f['brand']->id]);
    $wave = Campaign::factory()->forWave()->createOne(['agency_id' => $f['agency']->id, 'brand_id' => $f['brand']->id]);

    $this->actingAs($f['admin'])->patchJson(typeUrl($f['agency'], $normal->ulid), ['campaign_type' => 'for_wave'])
        ->assertUnprocessable()->assertJsonPath('errors.0.code', 'campaign.type_immutable');
    $this->actingAs($f['admin'])->patchJson(typeUrl($f['agency'], $wave->ulid), ['campaign_type' => 'normal'])
        ->assertUnprocessable()->assertJsonPath('errors.0.code', 'campaign.type_immutable');
    $this->actingAs($f['admin'])->patchJson(typeUrl($f['agency'], $wave->ulid), ['campaign_type' => 'for_wave', 'name' => 'Renamed'])
        ->assertOk();

    expect(Campaign::query()->findOrFail($normal->id)->campaign_type)->toBe(CampaignType::Normal)
        ->and(Campaign::query()->findOrFail($wave->id)->campaign_type)->toBe(CampaignType::ForWave)
        ->and(Campaign::query()->findOrFail($wave->id)->name)->toBe('Renamed');
});

it('C1: creator_posts_content true on a for-a-wave campaign is 422 campaign.wave_posting_not_allowed', function (): void {
    $f = typeFixture();
    WaveFixtures::armed();
    $wave = Campaign::factory()->forWave()->createOne(['agency_id' => $f['agency']->id, 'brand_id' => $f['brand']->id]);

    $this->actingAs($f['admin'])->patchJson(typeUrl($f['agency'], $wave->ulid), ['creator_posts_content' => true])
        ->assertUnprocessable()->assertJsonPath('errors.0.code', 'campaign.wave_posting_not_allowed');

    expect(Campaign::query()->findOrFail($wave->id)->creator_posts_content)->toBeFalse();
});

it('S1: campaign_type is NOT NULL with default normal, board_columns.stage_key is nullable, and an old-style row reads normal / NULL', function (): void {
    expect(Schema::hasColumn('campaigns', 'campaign_type'))->toBeTrue()
        ->and(Schema::hasColumn('board_columns', 'stage_key'))->toBeTrue();

    $f = typeFixture();
    // A row inserted the way every pre-PL-1.2 writer does: no mention of either column.
    $id = DB::table('campaigns')->insertGetId([
        'ulid' => (string) Str::ulid(), 'agency_id' => $f['agency']->id, 'brand_id' => $f['brand']->id,
        'name' => 'Old', 'objective' => 'ugc', 'status' => 'draft', 'budget_minor_units' => 1, 'budget_currency' => 'EUR',
        'created_by_user_id' => $f['admin']->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    expect(Campaign::query()->findOrFail($id)->campaign_type)->toBe(CampaignType::Normal);

    $col = collect(Schema::getColumns('campaigns'))->firstWhere('name', 'campaign_type');
    expect($col['nullable'])->toBeFalse();
    $stage = collect(Schema::getColumns('board_columns'))->firstWhere('name', 'stage_key');
    expect($stage['nullable'])->toBeTrue();
});

it('G1 (P8): changing the brand of a saved campaign throws; creating and saving other fields do not', function (): void {
    $f = typeFixture();
    $other = Brand::factory()->forAgency($f['agency']->id)->createOne();

    $campaign = Campaign::factory()->createOne(['agency_id' => $f['agency']->id, 'brand_id' => $f['brand']->id]);
    $campaign->update(['name' => 'Fine']);
    expect($campaign->fresh()?->name)->toBe('Fine');

    expect(fn () => $campaign->update(['brand_id' => $other->id]))->toThrow(CampaignBrandChangeException::class);
    expect($campaign->fresh()?->brand_id)->toBe($f['brand']->id);

    // Assigning the same value is not a change.
    $campaign->brand_id = $f['brand']->id;
    $campaign->save();
});

it('G1 (type): changing the type of a saved campaign throws in the model, both ways; the same value and other fields do not', function (): void {
    $f = typeFixture();

    $normal = Campaign::factory()->createOne(['agency_id' => $f['agency']->id, 'brand_id' => $f['brand']->id]);
    expect(fn () => $normal->update(['campaign_type' => CampaignType::ForWave]))->toThrow(CampaignTypeChangeException::class)
        ->and($normal->fresh()?->campaign_type)->toBe(CampaignType::Normal);

    $wave = Campaign::factory()->forWave()->createOne(['agency_id' => $f['agency']->id, 'brand_id' => $f['brand']->id]);
    expect(fn () => $wave->update(['campaign_type' => 'normal']))->toThrow(CampaignTypeChangeException::class)
        ->and($wave->fresh()?->campaign_type)->toBe(CampaignType::ForWave);

    $wave->campaign_type = CampaignType::ForWave;
    $wave->name = 'Renamed';
    $wave->save();
    expect($wave->fresh()?->name)->toBe('Renamed');
});

it('WB1: a for-a-wave campaign with budget 0 cannot go active or be listed; draft is fine; with a budget both work', function (): void {
    $f = typeFixture();
    WaveFixtures::armed();
    $wave = Campaign::factory()->forWave()->createOne([
        'agency_id' => $f['agency']->id, 'brand_id' => $f['brand']->id, 'budget_minor_units' => 0, 'budget_currency' => 'EUR',
        'description' => 'A description', 'listing_duration' => '2 weeks', 'listing_fee' => '200 EUR',
        'listing_languages' => ['en'], 'listing_regions' => ['SK'],
    ]);
    $url = typeUrl($f['agency'], $wave->ulid);

    $this->actingAs($f['admin'])->patchJson($url, ['status' => 'active'])
        ->assertUnprocessable()->assertJsonPath('errors.0.code', 'campaign.wave_budget_required');
    $this->actingAs($f['admin'])->patchJson($url, ['listed_on_jobs_board' => true])
        ->assertUnprocessable()->assertJsonPath('errors.0.code', 'campaign.wave_budget_required');
    expect(Campaign::query()->findOrFail($wave->id)->status->value)->toBe('draft')
        ->and(Campaign::query()->findOrFail($wave->id)->listed_on_jobs_board)->toBeFalse();

    // Staying a draft with budget 0, and renaming, are fine.
    $this->actingAs($f['admin'])->patchJson($url, ['name' => 'Still draft'])->assertOk();

    $this->actingAs($f['admin'])->patchJson($url, ['budget_minor_units' => 50_000, 'status' => 'active'])->assertOk();
    $this->actingAs($f['admin'])->patchJson($url, ['listed_on_jobs_board' => true])->assertOk();
    expect(Campaign::query()->findOrFail($wave->id)->status->value)->toBe('active');

    // Budget cannot go back to 0 while active.
    $this->actingAs($f['admin'])->patchJson($url, ['budget_minor_units' => 0])
        ->assertUnprocessable()->assertJsonPath('errors.0.code', 'campaign.wave_budget_required');
    expect(Campaign::query()->findOrFail($wave->id)->budget_minor_units)->toBe(50_000);
});

it('WB1 (P7): a NORMAL campaign with budget 0 still goes active (tech-debt row 41, unchanged)', function (): void {
    $f = typeFixture();
    $normal = Campaign::factory()->createOne(['agency_id' => $f['agency']->id, 'brand_id' => $f['brand']->id, 'budget_minor_units' => 0]);

    $this->actingAs($f['admin'])->patchJson(typeUrl($f['agency'], $normal->ulid), ['status' => 'active'])->assertOk();
    expect(Campaign::query()->findOrFail($normal->id)->status->value)->toBe('active');
});
