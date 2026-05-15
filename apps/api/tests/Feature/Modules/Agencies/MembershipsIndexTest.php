<?php

declare(strict_types=1);

use App\Modules\Agencies\Enums\AgencyRole;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

/**
 * Sprint 3 Chunk 4 sub-step 3 — GET /api/v1/agencies/{agency}/members.
 *
 * Paginated members listing. Any agency member may list; the admin
 * gate lives in the UI (Manage actions hidden for non-admins). The
 * tenancy.agency middleware on the route group enforces membership,
 * so non-member users get 403.
 */

// ---------------------------------------------------------------------------
// Auth + tenancy boundary
// ---------------------------------------------------------------------------

it('returns 401 when no user is authenticated', function (): void {
    $agency = Agency::factory()->createOne();

    $response = $this->getJson("/api/v1/agencies/{$agency->ulid}/members");

    expect($response->status())->toBe(401);
});

it('returns 404 when the authenticated user is not a member of the agency (tenancy.agency invisibility)', function (): void {
    // tenancy.agency middleware does a tenant-scope route-binding, so an
    // agency the user is NOT a member of is invisible to them (404 rather
    // than 403). This is the Sprint 2 invitation pattern's contract — it
    // does not leak agency existence to non-members.
    $agency = Agency::factory()->createOne();
    $otherAgency = Agency::factory()->createOne();
    $outsider = User::factory()->agencyAdmin($otherAgency)->createOne();

    $response = $this->actingAs($outsider)
        ->getJson("/api/v1/agencies/{$agency->ulid}/members");

    expect($response->status())->toBe(404);
});

it('returns 200 when an agency_staff member lists members (all roles can list)', function (): void {
    $agency = Agency::factory()->createOne();
    $staff = User::factory()->agencyStaff($agency)->createOne();

    $response = $this->actingAs($staff)
        ->getJson("/api/v1/agencies/{$agency->ulid}/members");

    expect($response->status())->toBe(200);
});

// ---------------------------------------------------------------------------
// Happy path + shape
// ---------------------------------------------------------------------------

it('lists agency members with paginated envelope and the expected attribute keys', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne([
        'name' => 'Admin User',
        'email' => 'admin@example.com',
    ]);
    User::factory()->agencyStaff($agency)->createOne();
    User::factory()->agencyManager($agency)->createOne();

    $response = $this->actingAs($admin)
        ->getJson("/api/v1/agencies/{$agency->ulid}/members");

    expect($response->status())->toBe(200);
    expect($response->json('data'))->toHaveCount(3);
    expect($response->json('meta.total'))->toBe(3);

    $first = $response->json('data.0.attributes');
    expect(array_keys($first))->toEqualCanonicalizing([
        'user_id', 'name', 'email', 'role', 'status', 'created_at', 'last_active_at',
    ]);
});

// ---------------------------------------------------------------------------
// Filter by role
// ---------------------------------------------------------------------------

it('filters members by role', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    User::factory()->agencyStaff($agency)->createOne();
    User::factory()->agencyStaff($agency)->createOne();

    $response = $this->actingAs($admin)
        ->getJson("/api/v1/agencies/{$agency->ulid}/members?role=".AgencyRole::AgencyStaff->value);

    expect($response->status())->toBe(200);
    expect($response->json('data'))->toHaveCount(2);
    foreach ($response->json('data') as $row) {
        expect($row['attributes']['role'])->toBe(AgencyRole::AgencyStaff->value);
    }
});

// ---------------------------------------------------------------------------
// Search
// ---------------------------------------------------------------------------

it('searches members by email substring (case-insensitive)', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne([
        'email' => 'alice@example.com',
    ]);
    User::factory()->agencyStaff($agency)->createOne([
        'email' => 'bob@example.com',
    ]);

    $response = $this->actingAs($admin)
        ->getJson("/api/v1/agencies/{$agency->ulid}/members?search=ALICE");

    expect($response->status())->toBe(200);
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.attributes.email'))->toBe('alice@example.com');
});

it('searches members by name substring', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne([
        'name' => 'Alice Alpha',
    ]);
    User::factory()->agencyStaff($agency)->createOne([
        'name' => 'Bob Beta',
    ]);

    $response = $this->actingAs($admin)
        ->getJson("/api/v1/agencies/{$agency->ulid}/members?search=alpha");

    expect($response->status())->toBe(200);
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.attributes.name'))->toBe('Alice Alpha');
});

// ---------------------------------------------------------------------------
// Sort
// ---------------------------------------------------------------------------

it('sorts members by name asc when ?sort=name', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne([
        'name' => 'Carol',
    ]);
    User::factory()->agencyStaff($agency)->createOne(['name' => 'Alice']);
    User::factory()->agencyStaff($agency)->createOne(['name' => 'Bob']);

    $response = $this->actingAs($admin)
        ->getJson("/api/v1/agencies/{$agency->ulid}/members?sort=name");

    expect($response->status())->toBe(200);
    $names = array_map(fn ($row) => $row['attributes']['name'], $response->json('data'));
    expect($names)->toBe(['Alice', 'Bob', 'Carol']);
});

it('default sort is -created_at (newest first)', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $response = $this->actingAs($admin)
        ->getJson("/api/v1/agencies/{$agency->ulid}/members");

    $first = $response->json('data.0.attributes.created_at');
    $last = $response->json('data.'.(count($response->json('data')) - 1).'.attributes.created_at');
    expect($first >= $last)->toBeTrue();
});

// ---------------------------------------------------------------------------
// Empty state + tenant isolation
// ---------------------------------------------------------------------------

it('does not leak members from other agencies', function (): void {
    $agency = Agency::factory()->createOne();
    $other = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    User::factory()->agencyStaff($other)->createOne();
    User::factory()->agencyStaff($other)->createOne();

    $response = $this->actingAs($admin)
        ->getJson("/api/v1/agencies/{$agency->ulid}/members");

    expect($response->status())->toBe(200);
    expect($response->json('data'))->toHaveCount(1); // just the admin
});
