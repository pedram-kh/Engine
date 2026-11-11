<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Creators\Features\CampaignBrandExclusivityEnabled;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * AH-120 A3 — the agency-side write of the blocked-brand list and the end date:
 * create and edit, the flag-OFF 422, the validation rules, the resource (show,
 * create, update carry the list; the campaigns list does not) and the audit
 * snapshot.
 */

/**
 * @return array{agency: Agency, admin: User, own: Brand, zava: Brand, yahoo: Brand}
 */
function exclusivityAgency(): array
{
    $agency = Agency::factory()->createOne();

    return [
        'agency' => $agency,
        'admin' => User::factory()->agencyAdmin($agency)->createOne(),
        'own' => Brand::factory()->forAgency($agency->id)->createOne(['name' => 'Own']),
        'zava' => Brand::factory()->forAgency($agency->id)->createOne(['name' => 'Zava']),
        'yahoo' => Brand::factory()->forAgency($agency->id)->createOne(['name' => 'Yahoo']),
    ];
}

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function exclusivityCreatePayload(Brand $brand, array $extra = []): array
{
    return [
        'brand_id' => $brand->ulid,
        'name' => 'Exclusive UGC',
        'budget_minor_units' => 500_000,
        'budget_currency' => 'EUR',
        ...$extra,
    ];
}

function exclusivityUrl(Agency $agency, ?Campaign $campaign = null): string
{
    return "/api/v1/agencies/{$agency->ulid}/campaigns".($campaign instanceof Campaign ? "/{$campaign->ulid}" : '');
}

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-11 12:00:00', 'UTC'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

// ── flag OFF: a write that SETS a list or a date is a 422 ───────────────────

it('refuses to set a list at create while the flag is OFF (422, domain code), and writes nothing', function (): void {
    ['agency' => $agency, 'admin' => $admin, 'own' => $own, 'zava' => $zava] = exclusivityAgency();

    $this->actingAs($admin)->postJson(exclusivityUrl($agency), exclusivityCreatePayload($own, ['exclusive_brand_ids' => [$zava->ulid]]))
        ->assertStatus(422)->assertJsonPath('errors.0.code', 'campaign.brand_exclusivity_disabled');

    expect(Campaign::query()->where('agency_id', $agency->id)->count())->toBe(0);
});

it('refuses to set a date at create or edit while the flag is OFF', function (): void {
    ['agency' => $agency, 'admin' => $admin, 'own' => $own] = exclusivityAgency();
    $campaign = Campaign::factory()->forAgency($agency->id)->createOne(['brand_id' => $own->id]);

    $this->actingAs($admin)->postJson(exclusivityUrl($agency), exclusivityCreatePayload($own, ['exclusivity_ends_on' => '2027-01-31']))
        ->assertStatus(422)->assertJsonPath('errors.0.code', 'campaign.brand_exclusivity_disabled');

    $this->actingAs($admin)->patchJson(exclusivityUrl($agency, $campaign), ['exclusivity_ends_on' => '2027-01-31', 'name' => 'Renamed'])
        ->assertStatus(422)->assertJsonPath('errors.0.code', 'campaign.brand_exclusivity_disabled');

    expect($campaign->fresh()?->exclusivity_ends_on)->toBeNull()
        ->and($campaign->fresh()?->name)->not->toBe('Renamed');
});

it('refuses to set a list at edit while the flag is OFF, and leaves the stored list untouched', function (): void {
    ['agency' => $agency, 'admin' => $admin, 'own' => $own, 'zava' => $zava, 'yahoo' => $yahoo] = exclusivityAgency();
    $campaign = Campaign::factory()->forAgency($agency->id)->blocking([$zava])->createOne(['brand_id' => $own->id]);

    $this->actingAs($admin)->patchJson(exclusivityUrl($agency, $campaign), ['exclusive_brand_ids' => [$yahoo->ulid]])
        ->assertStatus(422)->assertJsonPath('errors.0.code', 'campaign.brand_exclusivity_disabled');

    expect($campaign->exclusiveBrands()->pluck('brands.id')->all())->toBe([$zava->id]);
});

it('still lets the agency clear the list and the date, and leave them untouched, while the flag is OFF', function (): void {
    ['agency' => $agency, 'admin' => $admin, 'own' => $own, 'zava' => $zava] = exclusivityAgency();
    $campaign = Campaign::factory()->forAgency($agency->id)->blocking([$zava], '2027-01-31')->createOne(['brand_id' => $own->id]);
    $url = exclusivityUrl($agency, $campaign);

    // Omitted: untouched, and the stored values are reported honestly.
    $this->actingAs($admin)->patchJson($url, ['name' => 'Renamed'])->assertOk()
        ->assertJsonPath('data.attributes.exclusive_brand_ids', [$zava->ulid])
        ->assertJsonPath('data.attributes.exclusivity_ends_on', '2027-01-31');

    $this->actingAs($admin)->patchJson($url, ['exclusive_brand_ids' => [], 'exclusivity_ends_on' => null])->assertOk()
        ->assertJsonPath('data.attributes.exclusive_brand_ids', [])
        ->assertJsonPath('data.attributes.exclusivity_ends_on', null);

    expect($campaign->exclusiveBrands()->count())->toBe(0);
});

// ── flag ON: store, report, replace, clear ──────────────────────────────────

it('stores the list and the date on create when the flag is ON, and reports them', function (): void {
    Feature::activate(CampaignBrandExclusivityEnabled::NAME);
    ['agency' => $agency, 'admin' => $admin, 'own' => $own, 'zava' => $zava, 'yahoo' => $yahoo] = exclusivityAgency();

    $this->actingAs($admin)->postJson(
        exclusivityUrl($agency),
        exclusivityCreatePayload($own, ['exclusive_brand_ids' => [$zava->ulid, $yahoo->ulid], 'exclusivity_ends_on' => '2027-03-31']),
    )->assertCreated()
        ->assertJsonPath('data.attributes.exclusive_brand_ids', [$zava->ulid, $yahoo->ulid])
        ->assertJsonPath('data.attributes.exclusive_brands.0', ['id' => $zava->ulid, 'name' => 'Zava', 'is_archived' => false])
        ->assertJsonPath('data.attributes.exclusivity_ends_on', '2027-03-31');

    $campaign = Campaign::query()->where('agency_id', $agency->id)->firstOrFail();
    expect($campaign->exclusiveBrands()->pluck('brands.id')->all())->toBe([$zava->id, $yahoo->id])
        ->and($campaign->exclusivity_ends_on?->toDateString())->toBe('2027-03-31');
});

it('defaults to no exclusivity when the payload does not name the fields', function (): void {
    Feature::activate(CampaignBrandExclusivityEnabled::NAME);
    ['agency' => $agency, 'admin' => $admin, 'own' => $own] = exclusivityAgency();

    $this->actingAs($admin)->postJson(exclusivityUrl($agency), exclusivityCreatePayload($own))->assertCreated()
        ->assertJsonPath('data.attributes.exclusive_brand_ids', [])
        ->assertJsonPath('data.attributes.exclusivity_ends_on', null);
});

it('replaces the list on edit, keeps it when omitted, clears it with an empty array', function (): void {
    Feature::activate(CampaignBrandExclusivityEnabled::NAME);
    ['agency' => $agency, 'admin' => $admin, 'own' => $own, 'zava' => $zava, 'yahoo' => $yahoo] = exclusivityAgency();
    $campaign = Campaign::factory()->forAgency($agency->id)->blocking([$zava])->createOne(['brand_id' => $own->id]);
    $url = exclusivityUrl($agency, $campaign);

    $this->actingAs($admin)->patchJson($url, ['exclusive_brand_ids' => [$yahoo->ulid]])->assertOk()
        ->assertJsonPath('data.attributes.exclusive_brand_ids', [$yahoo->ulid]);
    expect($campaign->exclusiveBrands()->pluck('brands.id')->all())->toBe([$yahoo->id]);

    $this->actingAs($admin)->patchJson($url, ['name' => 'Renamed'])->assertOk()
        ->assertJsonPath('data.attributes.exclusive_brand_ids', [$yahoo->ulid]);

    $this->actingAs($admin)->patchJson($url, ['exclusive_brand_ids' => []])->assertOk()
        ->assertJsonPath('data.attributes.exclusive_brand_ids', []);
    expect($campaign->exclusiveBrands()->count())->toBe(0);
});

it('carries the list on show, and carries the date but NOT the list on the campaigns list', function (): void {
    Feature::activate(CampaignBrandExclusivityEnabled::NAME);
    ['agency' => $agency, 'admin' => $admin, 'own' => $own, 'zava' => $zava] = exclusivityAgency();
    $campaign = Campaign::factory()->forAgency($agency->id)->blocking([$zava], '2027-03-31')->createOne(['brand_id' => $own->id]);

    $this->actingAs($admin)->getJson(exclusivityUrl($agency, $campaign))->assertOk()
        ->assertJsonPath('data.attributes.exclusive_brand_ids', [$zava->ulid])
        ->assertJsonPath('data.attributes.exclusive_brands.0.name', 'Zava');

    $row = $this->actingAs($admin)->getJson(exclusivityUrl($agency))->assertOk()->json('data.0.attributes');

    expect($row)->not->toHaveKey('exclusive_brand_ids')
        ->and($row)->not->toHaveKey('exclusive_brands')
        ->and($row['exclusivity_ends_on'])->toBe('2027-03-31');
});

it('reports an archived brand on the list as archived, and keeps it blocked', function (): void {
    Feature::activate(CampaignBrandExclusivityEnabled::NAME);
    ['agency' => $agency, 'admin' => $admin, 'own' => $own, 'zava' => $zava] = exclusivityAgency();
    $campaign = Campaign::factory()->forAgency($agency->id)->blocking([$zava])->createOne(['brand_id' => $own->id]);
    $zava->delete();

    $this->actingAs($admin)->getJson(exclusivityUrl($agency, $campaign))->assertOk()
        ->assertJsonPath('data.attributes.exclusive_brands.0', ['id' => $zava->ulid, 'name' => 'Zava', 'is_archived' => true]);

    // Re-sending the same list on an unrelated save does not fail on the archived brand.
    $this->actingAs($admin)->patchJson(exclusivityUrl($agency, $campaign), ['name' => 'Renamed', 'exclusive_brand_ids' => [$zava->ulid]])
        ->assertOk();
    expect($campaign->exclusiveBrands()->pluck('brands.id')->all())->toBe([$zava->id]);
});

// ── validation (flag ON) ────────────────────────────────────────────────────

it('refuses the campaign\'s own brand at create and at edit (Q4)', function (): void {
    Feature::activate(CampaignBrandExclusivityEnabled::NAME);
    ['agency' => $agency, 'admin' => $admin, 'own' => $own] = exclusivityAgency();
    $campaign = Campaign::factory()->forAgency($agency->id)->createOne(['brand_id' => $own->id]);

    $this->actingAs($admin)->postJson(exclusivityUrl($agency), exclusivityCreatePayload($own, ['exclusive_brand_ids' => [$own->ulid]]))
        ->assertStatus(422)->assertJsonPath('errors.0.source.pointer', '/data/attributes/exclusive_brand_ids.0');

    $this->actingAs($admin)->patchJson(exclusivityUrl($agency, $campaign), ['exclusive_brand_ids' => [$own->ulid]])
        ->assertStatus(422);

    expect($campaign->exclusiveBrands()->count())->toBe(0);
});

it('refuses a brand of another agency, and an unknown ULID', function (): void {
    Feature::activate(CampaignBrandExclusivityEnabled::NAME);
    ['agency' => $agency, 'admin' => $admin, 'own' => $own] = exclusivityAgency();
    $foreign = Brand::factory()->forAgency(Agency::factory()->createOne()->id)->createOne();

    foreach ([$foreign->ulid, '01HZZZZZZZZZZZZZZZZZZZZZZZ'] as $bad) {
        $this->actingAs($admin)->postJson(exclusivityUrl($agency), exclusivityCreatePayload($own, ['exclusive_brand_ids' => [$bad]]))
            ->assertStatus(422);
    }

    expect(Campaign::query()->where('agency_id', $agency->id)->count())->toBe(0);
});

it('refuses to ADD an archived brand, at create and at edit (Q4)', function (): void {
    Feature::activate(CampaignBrandExclusivityEnabled::NAME);
    ['agency' => $agency, 'admin' => $admin, 'own' => $own, 'zava' => $zava] = exclusivityAgency();
    $campaign = Campaign::factory()->forAgency($agency->id)->createOne(['brand_id' => $own->id]);
    $zava->delete();

    $this->actingAs($admin)->postJson(exclusivityUrl($agency), exclusivityCreatePayload($own, ['exclusive_brand_ids' => [$zava->ulid]]))
        ->assertStatus(422);
    $this->actingAs($admin)->patchJson(exclusivityUrl($agency, $campaign), ['exclusive_brand_ids' => [$zava->ulid]])
        ->assertStatus(422);

    expect($campaign->exclusiveBrands()->count())->toBe(0);
});

it('caps the list at 50 and refuses duplicates (Q11)', function (): void {
    Feature::activate(CampaignBrandExclusivityEnabled::NAME);
    ['agency' => $agency, 'admin' => $admin, 'own' => $own, 'zava' => $zava] = exclusivityAgency();

    $brands = Brand::factory()->count(51)->forAgency($agency->id)->create();
    $ulids = $brands->pluck('ulid')->all();

    $this->actingAs($admin)->postJson(exclusivityUrl($agency), exclusivityCreatePayload($own, ['exclusive_brand_ids' => $ulids]))
        ->assertStatus(422);

    $this->actingAs($admin)->postJson(exclusivityUrl($agency), exclusivityCreatePayload($own, ['exclusive_brand_ids' => array_slice($ulids, 0, 50)]))
        ->assertCreated();

    $this->actingAs($admin)->postJson(exclusivityUrl($agency), exclusivityCreatePayload($own, ['exclusive_brand_ids' => [$zava->ulid, $zava->ulid]]))
        ->assertStatus(422);
});

it('refuses a past date when it changes, accepts today, and lets clearing through (Q5)', function (): void {
    Feature::activate(CampaignBrandExclusivityEnabled::NAME);
    ['agency' => $agency, 'admin' => $admin, 'own' => $own] = exclusivityAgency();
    $campaign = Campaign::factory()->forAgency($agency->id)->createOne(['brand_id' => $own->id]);
    $url = exclusivityUrl($agency, $campaign);

    // today (UTC) is 2026-11-11
    $this->actingAs($admin)->patchJson($url, ['exclusivity_ends_on' => '2026-11-10'])
        ->assertStatus(422)->assertJsonPath('errors.0.source.pointer', '/data/attributes/exclusivity_ends_on');
    expect($campaign->fresh()?->exclusivity_ends_on)->toBeNull();

    $this->actingAs($admin)->patchJson($url, ['exclusivity_ends_on' => '2026-11-11'])->assertOk()
        ->assertJsonPath('data.attributes.exclusivity_ends_on', '2026-11-11');

    $this->actingAs($admin)->patchJson($url, ['exclusivity_ends_on' => null])->assertOk()
        ->assertJsonPath('data.attributes.exclusivity_ends_on', null);

    $this->actingAs($admin)->postJson(exclusivityUrl($agency), exclusivityCreatePayload($own, ['exclusivity_ends_on' => '2026-11-10']))
        ->assertStatus(422);

    $this->actingAs($admin)->patchJson($url, ['exclusivity_ends_on' => 'next friday'])->assertStatus(422);
});

it('never refuses an unrelated save for a date that has since passed, unchanged or re-sent', function (): void {
    Feature::activate(CampaignBrandExclusivityEnabled::NAME);
    ['agency' => $agency, 'admin' => $admin, 'own' => $own, 'zava' => $zava] = exclusivityAgency();
    $campaign = Campaign::factory()->forAgency($agency->id)->blocking([$zava], '2026-11-10')->createOne(['brand_id' => $own->id]);
    $url = exclusivityUrl($agency, $campaign);

    $this->actingAs($admin)->patchJson($url, ['name' => 'Renamed'])->assertOk();
    $this->actingAs($admin)->patchJson($url, ['exclusivity_ends_on' => '2026-11-10', 'name' => 'Again'])->assertOk();

    expect($campaign->fresh()?->exclusivity_ends_on?->toDateString())->toBe('2026-11-10');
});

it('writes nothing of an edit when any part of its payload is refused', function (): void {
    Feature::activate(CampaignBrandExclusivityEnabled::NAME);
    ['agency' => $agency, 'admin' => $admin, 'own' => $own, 'zava' => $zava] = exclusivityAgency();
    $campaign = Campaign::factory()->forAgency($agency->id)->createOne(['brand_id' => $own->id, 'name' => 'Before']);

    // A refusal anywhere in the payload writes neither the name nor the list.
    $this->actingAs($admin)->patchJson(exclusivityUrl($agency, $campaign), [
        'name' => 'After',
        'exclusive_brand_ids' => [$zava->ulid],
        'exclusivity_ends_on' => '2020-01-01',
    ])->assertStatus(422);

    expect($campaign->fresh()?->name)->toBe('Before')
        ->and($campaign->exclusiveBrands()->count())->toBe(0)
        ->and(DB::table('campaign_exclusive_brands')->count())->toBe(0);
});

// ── audit ───────────────────────────────────────────────────────────────────

it('audits who changed the exclusivity: the snapshot carries the brand ids and the date, no names', function (): void {
    Feature::activate(CampaignBrandExclusivityEnabled::NAME);
    ['agency' => $agency, 'admin' => $admin, 'own' => $own, 'zava' => $zava] = exclusivityAgency();
    $campaign = Campaign::factory()->forAgency($agency->id)->createOne(['brand_id' => $own->id]);

    $this->actingAs($admin)->patchJson(exclusivityUrl($agency, $campaign), [
        'exclusive_brand_ids' => [$zava->ulid],
        'exclusivity_ends_on' => '2027-03-31',
    ])->assertOk();

    $row = AuditLog::query()->where('action', 'campaign.updated')->latest('id')->firstOrFail();
    expect($row->before['exclusive_brand_ids'] ?? null)->toBe([])
        ->and($row->before['exclusivity_ends_on'] ?? null)->toBeNull()
        ->and($row->after['exclusive_brand_ids'] ?? null)->toBe([$zava->id])
        ->and($row->after['exclusivity_ends_on'] ?? null)->toBe('2027-03-31')
        ->and(json_encode($row->after))->not->toContain('Zava');

    $this->actingAs($admin)->postJson(exclusivityUrl($agency), exclusivityCreatePayload($own, ['exclusive_brand_ids' => [$zava->ulid]]))->assertCreated();
    $created = AuditLog::query()->where('action', 'campaign.created')->latest('id')->firstOrFail();
    expect($created->after['exclusive_brand_ids'] ?? null)->toBe([$zava->id]);
});

it('does not let another agency edit this campaign\'s exclusivity (tenancy)', function (): void {
    Feature::activate(CampaignBrandExclusivityEnabled::NAME);
    ['agency' => $agency, 'own' => $own, 'zava' => $zava] = exclusivityAgency();
    ['agency' => $otherAgency, 'admin' => $otherAdmin] = exclusivityAgency();
    $campaign = Campaign::factory()->forAgency($agency->id)->createOne(['brand_id' => $own->id]);

    $this->actingAs($otherAdmin)->patchJson(exclusivityUrl($otherAgency, $campaign), ['exclusive_brand_ids' => [$zava->ulid]])
        ->assertStatus(404);

    expect($campaign->exclusiveBrands()->count())->toBe(0);
});
