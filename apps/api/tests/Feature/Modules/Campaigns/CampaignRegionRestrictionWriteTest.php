<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Creators\Features\CampaignRegionRestrictionEnabled;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * AH-118 A3 — the agency-side write of the "only creators in the listed regions
 * can apply" switch: create and edit, the flag-OFF 422, the merged-state
 * regions rule, the resource and the audit snapshot.
 */

/**
 * @return array{agency: Agency, admin: User, brand: Brand}
 */
function regionRuleAgency(): array
{
    $agency = Agency::factory()->createOne();

    return [
        'agency' => $agency,
        'admin' => User::factory()->agencyAdmin($agency)->createOne(),
        'brand' => Brand::factory()->forAgency($agency->id)->createOne(),
    ];
}

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function regionRuleCreatePayload(Brand $brand, array $extra = []): array
{
    return [
        'brand_id' => $brand->ulid,
        'name' => 'Regional UGC',
        'budget_minor_units' => 500_000,
        'budget_currency' => 'EUR',
        ...$extra,
    ];
}

// ── flag OFF: a write that turns the switch ON is a 422 ─────────────────────

it('refuses to turn the switch on at create while the flag is OFF (422, domain code)', function (): void {
    ['agency' => $agency, 'admin' => $admin, 'brand' => $brand] = regionRuleAgency();

    $this->actingAs($admin)->postJson(
        "/api/v1/agencies/{$agency->ulid}/campaigns",
        regionRuleCreatePayload($brand, ['listing_regions' => ['IE'], 'restrict_to_listing_regions' => true]),
    )->assertStatus(422)->assertJsonPath('errors.0.code', 'campaign.region_restriction_disabled');

    expect(Campaign::query()->where('agency_id', $agency->id)->count())->toBe(0);
});

it('refuses to turn the switch on at edit while the flag is OFF, and writes nothing', function (): void {
    ['agency' => $agency, 'admin' => $admin] = regionRuleAgency();
    $campaign = Campaign::factory()->forAgency($agency->id)->jobReady()->createOne();

    $this->actingAs($admin)->patchJson(
        "/api/v1/agencies/{$agency->ulid}/campaigns/{$campaign->ulid}",
        ['restrict_to_listing_regions' => true, 'name' => 'Renamed'],
    )->assertStatus(422)->assertJsonPath('errors.0.code', 'campaign.region_restriction_disabled');

    $fresh = $campaign->fresh();
    expect($fresh?->restrict_to_listing_regions)->toBeFalse()
        ->and($fresh?->name)->not->toBe('Renamed');
});

it('still lets the agency turn the switch OFF, and leave it untouched, while the flag is OFF', function (): void {
    ['agency' => $agency, 'admin' => $admin] = regionRuleAgency();
    $campaign = Campaign::factory()->forAgency($agency->id)->jobReady()->regionRestricted()->createOne();
    $url = "/api/v1/agencies/{$agency->ulid}/campaigns/{$campaign->ulid}";

    // Omitted: untouched, and the stored value is reported honestly.
    $this->actingAs($admin)->patchJson($url, ['name' => 'Renamed'])->assertOk()
        ->assertJsonPath('data.attributes.restrict_to_listing_regions', true);

    $this->actingAs($admin)->patchJson($url, ['restrict_to_listing_regions' => false])->assertOk()
        ->assertJsonPath('data.attributes.restrict_to_listing_regions', false);
});

// ── flag ON ─────────────────────────────────────────────────────────────────

it('stores the switch on create when the flag is ON, and reports it', function (): void {
    Feature::activate(CampaignRegionRestrictionEnabled::NAME);
    ['agency' => $agency, 'admin' => $admin, 'brand' => $brand] = regionRuleAgency();

    $this->actingAs($admin)->postJson(
        "/api/v1/agencies/{$agency->ulid}/campaigns",
        regionRuleCreatePayload($brand, ['listing_regions' => ['ie', 'fr'], 'restrict_to_listing_regions' => true]),
    )->assertCreated()
        ->assertJsonPath('data.attributes.restrict_to_listing_regions', true)
        ->assertJsonPath('data.attributes.listing_regions', ['IE', 'FR']);

    expect(Campaign::query()->where('agency_id', $agency->id)->firstOrFail()->restrict_to_listing_regions)->toBeTrue();
});

it('defaults the switch to false when the payload does not name it', function (): void {
    Feature::activate(CampaignRegionRestrictionEnabled::NAME);
    ['agency' => $agency, 'admin' => $admin, 'brand' => $brand] = regionRuleAgency();

    $this->actingAs($admin)->postJson("/api/v1/agencies/{$agency->ulid}/campaigns", regionRuleCreatePayload($brand))
        ->assertCreated()->assertJsonPath('data.attributes.restrict_to_listing_regions', false);
});

it('rejects the switch with no regions at create, and on an edit that would leave none (merged state)', function (): void {
    Feature::activate(CampaignRegionRestrictionEnabled::NAME);
    ['agency' => $agency, 'admin' => $admin, 'brand' => $brand] = regionRuleAgency();

    $this->actingAs($admin)->postJson(
        "/api/v1/agencies/{$agency->ulid}/campaigns",
        regionRuleCreatePayload($brand, ['restrict_to_listing_regions' => true]),
    )->assertStatus(422)->assertJsonPath('errors.0.meta.field', 'listing_regions');

    $campaign = Campaign::factory()->forAgency($agency->id)->jobReady()->regionRestricted()->createOne();
    $url = "/api/v1/agencies/{$agency->ulid}/campaigns/{$campaign->ulid}";

    // Clearing the regions of a restricted campaign is refused …
    $this->actingAs($admin)->patchJson($url, ['listing_regions' => []])
        ->assertStatus(422)->assertJsonPath('errors.0.meta.field', 'listing_regions');

    // … turning the switch on over a campaign that has none is refused …
    $bare = Campaign::factory()->forAgency($agency->id)->createOne(['listing_regions' => null]);
    $this->actingAs($admin)->patchJson("/api/v1/agencies/{$agency->ulid}/campaigns/{$bare->ulid}", ['restrict_to_listing_regions' => true])
        ->assertStatus(422)->assertJsonPath('errors.0.meta.field', 'listing_regions');

    // … and one request that supplies both is fine.
    $this->actingAs($admin)->patchJson(
        "/api/v1/agencies/{$agency->ulid}/campaigns/{$bare->ulid}",
        ['restrict_to_listing_regions' => true, 'listing_regions' => ['IE']],
    )->assertOk();

    expect($campaign->fresh()?->listing_regions)->toBe(['IE', 'FR']);
});

it('lets the agency turn the switch off with no regions at all', function (): void {
    Feature::activate(CampaignRegionRestrictionEnabled::NAME);
    ['agency' => $agency, 'admin' => $admin] = regionRuleAgency();
    $campaign = Campaign::factory()->forAgency($agency->id)->jobReady()->regionRestricted()->createOne();

    $this->actingAs($admin)->patchJson(
        "/api/v1/agencies/{$agency->ulid}/campaigns/{$campaign->ulid}",
        ['restrict_to_listing_regions' => false, 'listing_regions' => []],
    )->assertOk()->assertJsonPath('data.attributes.restrict_to_listing_regions', false);
});

it('puts the switch in the campaign.updated audit snapshot, before and after', function (): void {
    Feature::activate(CampaignRegionRestrictionEnabled::NAME);
    ['agency' => $agency, 'admin' => $admin] = regionRuleAgency();
    $campaign = Campaign::factory()->forAgency($agency->id)->jobReady()->createOne();

    $this->actingAs($admin)->patchJson(
        "/api/v1/agencies/{$agency->ulid}/campaigns/{$campaign->ulid}",
        ['restrict_to_listing_regions' => true],
    )->assertOk();

    $row = AuditLog::query()->where('action', 'campaign.updated')->latest('id')->firstOrFail();
    expect($row->before['restrict_to_listing_regions'] ?? null)->toBeFalse()
        ->and($row->after['restrict_to_listing_regions'] ?? null)->toBeTrue();
});
