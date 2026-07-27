<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * AH-053 D6 — the brand completeness floor.
 *
 * Two halves, both pinned here:
 *   - the REFUSAL: no write may leave a brand below the floor;
 *   - the UNTOUCHABILITY: an incomplete brand that nobody edits is not
 *     affected in any way — it reads, lists, archives, restores and carries
 *     campaigns exactly as before (§5.34). The gate is a new refusal on write,
 *     never a new write.
 */

/** @return array{agency: Agency, user: User} */
function floorAdmin(): array
{
    $agency = Agency::factory()->createOne();
    $user = User::factory()->agencyAdmin($agency)->createOne();

    return compact('agency', 'user');
}

function brandUrl(Agency $agency, Brand $brand): string
{
    return "/api/v1/agencies/{$agency->ulid}/brands/{$brand->ulid}";
}

// ── The refusal ─────────────────────────────────────────────────────────────

it('hard-blocks the next edit of an existing incomplete brand, naming every missing field', function (): void {
    ['agency' => $agency, 'user' => $user] = floorAdmin();
    $brand = Brand::factory()->forAgency($agency->id)->incomplete()->createOne();

    $this->actingAs($user)
        ->patchJson(brandUrl($agency, $brand), ['name' => 'Renamed'])
        ->assertUnprocessable()
        ->assertEnvelopeValidationErrors(['description', 'industry', 'website_url', 'logo_path']);

    // The refused write changed nothing.
    expect($brand->fresh()?->name)->toBe($brand->name);
});

it('accepts the edit once the payload completes the floor in the same request', function (): void {
    ['agency' => $agency, 'user' => $user] = floorAdmin();
    // Missing everything except the logo, which no payload can supply (D7).
    $brand = Brand::factory()->forAgency($agency->id)->createOne([
        'description' => null,
        'industry' => null,
        'website_url' => null,
    ]);

    $this->actingAs($user)
        ->patchJson(brandUrl($agency, $brand), [
            'name' => 'Completed Brand',
            'description' => 'Monthly deliverables: 4 Reels.',
            'industry' => 'beauty',
            'website_url' => 'https://completed.example.com',
        ])
        ->assertOk()
        ->assertJsonPath('data.attributes.name', 'Completed Brand');
});

it('refuses to EMPTY a floor field on an already-complete brand (the other direction)', function (string $field): void {
    ['agency' => $agency, 'user' => $user] = floorAdmin();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();

    $this->actingAs($user)
        ->patchJson(brandUrl($agency, $brand), [$field => null])
        ->assertUnprocessable()
        ->assertEnvelopeValidationErrors([$field]);

    expect($brand->fresh()?->{$field})->not->toBeNull();
})->with(['description', 'industry', 'website_url']);

it('treats a whitespace-only floor value as empty', function (): void {
    ['agency' => $agency, 'user' => $user] = floorAdmin();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();

    $this->actingAs($user)
        ->patchJson(brandUrl($agency, $brand), ['industry' => '   '])
        ->assertUnprocessable()
        ->assertEnvelopeValidationErrors(['industry']);
});

it('blocks on a missing logo alone, and unblocks once one is uploaded (D7 interaction)', function (): void {
    ['agency' => $agency, 'user' => $user] = floorAdmin();
    $brand = Brand::factory()->forAgency($agency->id)->missingFloorField('logo_path')->createOne();

    // Every text field is present; only the logo is missing, and no payload
    // field can supply it — the upload endpoint owns that column.
    $this->actingAs($user)
        ->patchJson(brandUrl($agency, $brand), ['name' => 'Logo-less'])
        ->assertUnprocessable()
        ->assertEnvelopeValidationErrors(['logo_path']);

    $brand->forceFill(['logo_path' => 'agencies/a/brands/b/logo/c.webp'])->save();

    $this->actingAs($user)
        ->patchJson(brandUrl($agency, $brand), ['name' => 'Logo-less'])
        ->assertOk();
});

it('preserves omitted fields — the gate did not turn PATCH into PUT (§5.34)', function (): void {
    ['agency' => $agency, 'user' => $user] = floorAdmin();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();
    $before = $brand->only(['description', 'industry', 'website_url', 'default_currency', 'default_language']);

    $this->actingAs($user)
        ->patchJson(brandUrl($agency, $brand), ['name' => 'Only the name'])
        ->assertOk();

    expect($brand->fresh()?->only(array_keys($before)))->toBe($before);
});

// ── Untouchability at rest (§5.34) ──────────────────────────────────────────

it('leaves an incomplete brand fully readable and listable', function (): void {
    ['agency' => $agency, 'user' => $user] = floorAdmin();
    $brand = Brand::factory()->forAgency($agency->id)->incomplete()->createOne();

    $this->actingAs($user)->getJson(brandUrl($agency, $brand))->assertOk();
    $this->actingAs($user)
        ->getJson("/api/v1/agencies/{$agency->ulid}/brands")
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('lets an incomplete brand carry a new campaign', function (): void {
    ['agency' => $agency, 'user' => $user] = floorAdmin();
    $brand = Brand::factory()->forAgency($agency->id)->incomplete()->createOne();

    $this->actingAs($user)
        ->postJson("/api/v1/agencies/{$agency->ulid}/campaigns", [
            'brand_id' => $brand->ulid,
            'name' => 'Campaign on an incomplete brand',
            'budget_minor_units' => 100_000,
            'budget_currency' => 'EUR',
        ])
        ->assertCreated();

    expect(Campaign::query()->where('brand_id', $brand->id)->exists())->toBeTrue();
});

it('archives an incomplete brand without gating — archiving is lifecycle, not an edit', function (): void {
    ['agency' => $agency, 'user' => $user] = floorAdmin();
    $brand = Brand::factory()->forAgency($agency->id)->incomplete()->createOne();

    $this->actingAs($user)
        ->deleteJson(brandUrl($agency, $brand))
        ->assertOk();

    expect($brand->fresh()?->isArchived())->toBeTrue();
});

it('restores an incomplete archived brand without gating, and gates only on the NEXT real edit', function (): void {
    ['agency' => $agency, 'user' => $user] = floorAdmin();
    $brand = Brand::factory()->forAgency($agency->id)->incomplete()->archived()->createOne();

    // Restore takes no payload and never reaches UpdateBrandRequest.
    $this->actingAs($user)
        ->postJson(brandUrl($agency, $brand).'/restore')
        ->assertOk();

    expect($brand->fresh()?->isArchived())->toBeFalse();

    // The edit that follows is what meets the wall.
    $this->actingAs($user)
        ->patchJson(brandUrl($agency, $brand), ['name' => 'Back in play'])
        ->assertUnprocessable()
        ->assertEnvelopeValidationErrors(['description', 'industry', 'website_url', 'logo_path']);
});

// ── Create (D6) ─────────────────────────────────────────────────────────────

it('requires every floor field except the logo at create', function (): void {
    ['agency' => $agency, 'user' => $user] = floorAdmin();

    $this->actingAs($user)
        ->postJson("/api/v1/agencies/{$agency->ulid}/brands", ['name' => 'Bare'])
        ->assertUnprocessable()
        ->assertEnvelopeValidationErrors(['slug', 'description', 'industry', 'website_url'])
        // The logo is uploaded after the row exists — it cannot be a create error.
        ->assertJsonMissing(['field' => 'logo_path']);
});

it('still accepts default_currency and default_language from an API client (D8 — the form dropped them, the contract did not)', function (): void {
    ['agency' => $agency, 'user' => $user] = floorAdmin();

    $this->actingAs($user)
        ->postJson("/api/v1/agencies/{$agency->ulid}/brands", [
            'name' => 'Currency Brand',
            'slug' => 'currency-brand',
            'description' => 'Deliverables.',
            'industry' => 'tech',
            'website_url' => 'https://currency.example.com',
            'default_currency' => 'GBP',
            'default_language' => 'ga',
        ])
        ->assertCreated()
        ->assertJsonPath('data.attributes.default_currency', 'GBP')
        ->assertJsonPath('data.attributes.default_language', 'ga');
});

it('keeps stored default_currency and default_language when an edit omits them (preserve-by-omission)', function (): void {
    ['agency' => $agency, 'user' => $user] = floorAdmin();
    $brand = Brand::factory()->forAgency($agency->id)->createOne([
        'default_currency' => 'GBP',
        'default_language' => 'ga',
    ]);

    $this->actingAs($user)
        ->patchJson(brandUrl($agency, $brand), ['name' => 'Renamed'])
        ->assertOk()
        ->assertJsonPath('data.attributes.default_currency', 'GBP')
        ->assertJsonPath('data.attributes.default_language', 'ga');
});
