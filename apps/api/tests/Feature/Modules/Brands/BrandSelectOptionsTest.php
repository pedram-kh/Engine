<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Brands\Models\Brand;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

/**
 * AH-085 — a production bug: the New-campaign Brand select (and every other
 * brand `<select>` picker) silently truncated to the first 25 brands,
 * alphabetically, because `BrandController::index` hardcoded `paginate(25)`
 * and ignored the `?per_page=100` every picker actually sent. This file pins
 * the fix — `?for=select` returns EVERY matching brand, unpaginated, in the
 * thin `{id, name}` projection — and proves the general paginated index is
 * byte-identical for callers that do not opt in (the Brands admin table).
 */
function makeBrandSelectAdmin(): array
{
    $agency = Agency::factory()->createOne();
    $user = User::factory()->agencyAdmin($agency)->createOne();

    return [$agency, $user];
}

it('§5.34 — every brand is reachable via ?for=select, past the old 25-row page boundary', function (): void {
    [$agency, $user] = makeBrandSelectAdmin();

    // page_size (25) + 5 — the exact shape that silently dropped brands
    // sorting after the 25th name under the old hardcoded paginate(25).
    Brand::factory()->count(30)->forAgency($agency->id)->create();

    $response = $this->actingAs($user)
        ->getJson("/api/v1/agencies/{$agency->ulid}/brands?for=select")
        ->assertOk();

    expect($response->json('data'))->toHaveCount(30);
    // Unpaginated: no meta/links envelope, unlike the general index.
    expect($response->json('meta'))->toBeNull();
    expect($response->json('links'))->toBeNull();
});

it('the thin projection carries only {id, type, attributes: {name}} — nothing heavier', function (): void {
    [$agency, $user] = makeBrandSelectAdmin();
    Brand::factory()->forAgency($agency->id)->create(['name' => 'Acme Corp']);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/agencies/{$agency->ulid}/brands?for=select")
        ->assertOk();

    $row = $response->json('data.0');

    expect(array_keys($row))->toBe(['id', 'type', 'attributes'])
        ->and(array_keys($row['attributes']))->toBe(['name'])
        ->and($row['attributes']['name'])->toBe('Acme Corp')
        ->and($row['type'])->toBe('brands');
});

it('is ordered by name, matching the general index', function (): void {
    [$agency, $user] = makeBrandSelectAdmin();
    Brand::factory()->forAgency($agency->id)->create(['name' => 'Zebra Co']);
    Brand::factory()->forAgency($agency->id)->create(['name' => 'Acme Corp']);
    Brand::factory()->forAgency($agency->id)->create(['name' => 'Midway Ltd']);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/agencies/{$agency->ulid}/brands?for=select")
        ->assertOk();

    /** @var array<int, array{attributes: array{name: string}}> $rows */
    $rows = $response->json('data');
    $names = array_map(static fn (array $row): string => $row['attributes']['name'], $rows);

    expect($names)->toBe(['Acme Corp', 'Midway Ltd', 'Zebra Co']);
});

it('respects ?status — active (default), archived, and all — exactly like the general index', function (): void {
    [$agency, $user] = makeBrandSelectAdmin();
    Brand::factory()->forAgency($agency->id)->create(['name' => 'Active Brand']);
    Brand::factory()->archived()->forAgency($agency->id)->create(['name' => 'Archived Brand']);

    $this->actingAs($user)
        ->getJson("/api/v1/agencies/{$agency->ulid}/brands?for=select")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.attributes.name', 'Active Brand');

    $this->actingAs($user)
        ->getJson("/api/v1/agencies/{$agency->ulid}/brands?for=select&status=archived")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.attributes.name', 'Archived Brand');

    $this->actingAs($user)
        ->getJson("/api/v1/agencies/{$agency->ulid}/brands?for=select&status=all")
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('never leaks another agency\'s brands through ?for=select', function (): void {
    [$agency, $user] = makeBrandSelectAdmin();
    $otherAgency = Agency::factory()->createOne();
    Brand::factory()->forAgency($agency->id)->create(['name' => 'Mine']);
    Brand::factory()->forAgency($otherAgency->id)->create(['name' => 'Not Mine']);

    $this->actingAs($user)
        ->getJson("/api/v1/agencies/{$agency->ulid}/brands?for=select")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.attributes.name', 'Mine');
});

it('a staff member (any membership) can use ?for=select — same viewAny gate as the general index', function (): void {
    $agency = Agency::factory()->createOne();
    $user = User::factory()->agencyStaff($agency)->createOne();
    Brand::factory()->forAgency($agency->id)->create();

    $this->actingAs($user)
        ->getJson("/api/v1/agencies/{$agency->ulid}/brands?for=select")
        ->assertOk();
});

it('unauthenticated ?for=select returns 401, same as the general index', function (): void {
    $agency = Agency::factory()->createOne();

    $this->getJson("/api/v1/agencies/{$agency->ulid}/brands?for=select")
        ->assertUnauthorized();
});

it('BACKWARD COMPAT — omitting ?for=select still paginates at 25, unchanged (the Brands admin table)', function (): void {
    [$agency, $user] = makeBrandSelectAdmin();
    Brand::factory()->count(30)->forAgency($agency->id)->create();

    $response = $this->actingAs($user)
        ->getJson("/api/v1/agencies/{$agency->ulid}/brands")
        ->assertOk();

    expect($response->json('data'))->toHaveCount(25)
        ->and($response->json('meta.total'))->toBe(30)
        ->and($response->json('meta.per_page'))->toBe(25)
        ->and($response->json('meta.last_page'))->toBe(2);
    // The full BrandResource shape, not the thin projection.
    expect($response->json('data.0'))->toHaveKey('attributes.logo_url');
});
