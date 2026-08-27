<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

/**
 * AH-090 (D6) — `?for=select` on the admin agencies index.
 *
 * The AH-085 bug class, caught BEFORE it shipped this time. The automation-rule
 * dialog needs an agency picker; the admin agencies index paginates at 25 by
 * design (it is a data table with real pagination controls). A picker pointed at
 * it would silently stop offering the alphabetical tail the moment the platform
 * has a 26th agency — an option nobody can select, with no error to notice.
 *
 * This is the third time this class has bitten (AH-066's rosters, AH-085's
 * brands, this dialog), which is why docs/02-CONVENTIONS.md §3.5 now states the
 * decision procedure instead of it being re-derived a fourth time. These tests
 * are the half that actually bites, at the layer where the bug lives.
 */
function selectAdmin(): User
{
    return User::factory()->create([
        'type' => UserType::PlatformAdmin,
        'two_factor_confirmed_at' => now(),
    ]);
}

it('§5.34 — every agency is reachable via ?for=select, past the 25-row page boundary', function (): void {
    // page_size (25) + 5 — the exact shape that silently drops rows when a
    // picker reads a paginated index.
    Agency::factory()->count(30)->create();

    $response = $this->actingAs(selectAdmin(), 'web_admin')
        ->getJson('/api/v1/admin/agencies?for=select')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(30)
        // Unpaginated: no pagination meta, unlike the general index.
        ->and($response->json('meta'))->toBeNull();
});

it('the thin projection carries only {id, type, attributes: {name}} — nothing heavier', function (): void {
    Agency::factory()->createOne(['name' => 'Acme Talent']);

    $response = $this->actingAs(selectAdmin(), 'web_admin')
        ->getJson('/api/v1/admin/agencies?for=select')
        ->assertOk();

    /** @var array<string, mixed> $row */
    $row = $response->json('data.0');

    // Not the member count the general index computes with an extra aggregate
    // query, not the suspension columns, not the subscription tier.
    expect(array_keys($row))->toBe(['id', 'type', 'attributes'])
        ->and(array_keys($row['attributes']))->toBe(['name'])
        ->and($row['attributes']['name'])->toBe('Acme Talent')
        ->and($row['type'])->toBe('agencies');
});

it('is ordered by name, matching the general index', function (): void {
    Agency::factory()->createOne(['name' => 'Zebra Talent']);
    Agency::factory()->createOne(['name' => 'Acme Talent']);
    Agency::factory()->createOne(['name' => 'Midway Talent']);

    $response = $this->actingAs(selectAdmin(), 'web_admin')
        ->getJson('/api/v1/admin/agencies?for=select')
        ->assertOk();

    /** @var array<int, array{attributes: array{name: string}}> $rows */
    $rows = $response->json('data');
    $names = array_map(static fn (array $row): string => $row['attributes']['name'], $rows);

    expect($names)->toBe(['Acme Talent', 'Midway Talent', 'Zebra Talent']);
});

it('omits SUSPENDED agencies — offering one would be offering a choice that is refused on save', function (): void {
    Agency::factory()->createOne(['name' => 'Live Agency']);
    Agency::factory()->suspended()->createOne(['name' => 'Suspended Agency']);

    $this->actingAs(selectAdmin(), 'web_admin')
        ->getJson('/api/v1/admin/agencies?for=select')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.attributes.name', 'Live Agency');
});

it('403s a non-platform-admin and 401s the unauthenticated, same as the general index', function (): void {
    $this->getJson('/api/v1/admin/agencies?for=select')->assertUnauthorized();

    $agencyUser = User::factory()->create([
        'type' => UserType::AgencyUser,
        'two_factor_confirmed_at' => now(),
    ]);

    $this->actingAs($agencyUser, 'web_admin')
        ->getJson('/api/v1/admin/agencies?for=select')
        ->assertForbidden();
});

it('BACKWARD COMPAT — omitting ?for=select still paginates at 25 with its full shape', function (): void {
    Agency::factory()->count(30)->create();

    $response = $this->actingAs(selectAdmin(), 'web_admin')
        ->getJson('/api/v1/admin/agencies')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(25)
        ->and($response->json('meta.total'))->toBe(30)
        ->and($response->json('meta.per_page'))->toBe(25)
        ->and($response->json('meta.last_page'))->toBe(2);

    // The full admin shape, not the thin projection — the agencies data table
    // is unaffected by the new mode.
    expect($response->json('data.0.attributes'))->toHaveKey('member_count');
});
