<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Brands\Enums\BrandColor;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** AH-119 A4 — the brand colour is a palette KEY. */
function colorAdmin(): array
{
    $agency = Agency::factory()->createOne();

    return [$agency, User::factory()->agencyAdmin($agency)->createOne()];
}

function colorPayload(array $o = []): array
{
    return array_merge([
        'name' => 'Acme', 'slug' => 'acme', 'description' => 'd', 'industry' => 'fashion', 'website_url' => 'https://a.example.com',
    ], $o);
}

it('has exactly the eight palette keys in the ruled order', function (): void {
    expect(BrandColor::values())->toBe(['violet', 'green', 'amber', 'blue', 'pink', 'teal', 'red', 'slate']);
});

it('assigns the next palette key to a brand created without one, wrapping after eight', function (): void {
    [$agency, $admin] = colorAdmin();

    $colors = [];
    for ($i = 0; $i < 9; $i++) {
        $colors[] = $this->actingAs($admin)->postJson("/api/v1/agencies/{$agency->ulid}/brands", colorPayload(['slug' => "b{$i}", 'name' => "B{$i}"]))
            ->assertCreated()->json('data.attributes.color');
    }

    expect($colors)->toBe([...BrandColor::values(), 'violet']);
});

it('keeps an explicit colour on create, and changes it on edit', function (): void {
    [$agency, $admin] = colorAdmin();

    $this->actingAs($admin)->postJson("/api/v1/agencies/{$agency->ulid}/brands", colorPayload(['color' => 'red']))
        ->assertCreated()->assertJsonPath('data.attributes.color', 'red');

    // A brand that meets the floor (a logo is uploaded separately, so a created one cannot be edited yet).
    $brand = Brand::factory()->forAgency($agency->id)->createOne(['color' => 'red']);
    $this->actingAs($admin)->patchJson("/api/v1/agencies/{$agency->ulid}/brands/{$brand->ulid}", ['color' => 'teal'])
        ->assertOk()->assertJsonPath('data.attributes.color', 'teal');
    expect($brand->fresh()?->color)->toBe('teal');
});

it('rejects a colour that is not a palette key (422), hex included', function (): void {
    [$agency, $admin] = colorAdmin();

    foreach (['#ff0000', 'orange', 'VIOLET'] as $bad) {
        $this->actingAs($admin)->postJson("/api/v1/agencies/{$agency->ulid}/brands", colorPayload(['color' => $bad, 'slug' => 'x'.md5($bad)]))
            ->assertStatus(422);
    }
    $brand = Brand::factory()->forAgency($agency->id)->createOne();
    $this->actingAs($admin)->patchJson("/api/v1/agencies/{$agency->ulid}/brands/{$brand->ulid}", ['color' => '#abc'])->assertStatus(422);
});

it('leaves existing brands null (no backfill) and reports null', function (): void {
    [$agency, $admin] = colorAdmin();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();

    expect($brand->fresh()?->color)->toBeNull();
    $this->actingAs($admin)->getJson("/api/v1/agencies/{$agency->ulid}/brands/{$brand->ulid}")
        ->assertOk()->assertJsonPath('data.attributes.color', null);
});

it('carries the colour in the campaign resource\'s embedded brand', function (): void {
    [$agency, $admin] = colorAdmin();
    $brand = Brand::factory()->forAgency($agency->id)->createOne(['color' => 'blue']);
    $campaign = Campaign::factory()->createOne(['agency_id' => $agency->id, 'brand_id' => $brand->id]);

    $this->actingAs($admin)->getJson("/api/v1/agencies/{$agency->ulid}/campaigns/{$campaign->ulid}")
        ->assertOk()->assertJsonPath('data.relationships.brand.data.color', 'blue');
});

it('audits an edit as brand.updated with no new payload key', function (): void {
    [$agency, $admin] = colorAdmin();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();

    $this->actingAs($admin)->patchJson("/api/v1/agencies/{$agency->ulid}/brands/{$brand->ulid}", ['color' => 'pink'])->assertOk();

    $row = AuditLog::query()->where('action', AuditAction::BrandUpdated->value)->latest('id')->firstOrFail();
    expect(array_keys($row->metadata ?? []))->toBe([]);
});
