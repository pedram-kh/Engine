<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Brands\Models\Brand;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use App\Modules\TalentPools\Models\TalentPool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/** @return array{agency: Agency, user: User} */
function poolAdmin(): array
{
    $agency = Agency::factory()->createOne();
    $user = User::factory()->agencyAdmin($agency)->createOne();

    return compact('agency', 'user');
}

/** @return array{agency: Agency, user: User} */
function poolManager(): array
{
    $agency = Agency::factory()->createOne();
    $user = User::factory()->agencyManager($agency)->createOne();

    return compact('agency', 'user');
}

/** @return array{agency: Agency, user: User} */
function poolStaff(): array
{
    $agency = Agency::factory()->createOne();
    $user = User::factory()->agencyStaff($agency)->createOne();

    return compact('agency', 'user');
}

/**
 * A creator with an AgencyCreatorRelation to the given agency (roster status)
 * — the relation that makes a creator poolable (D-2b-5). Returns the creator +
 * its relation. Shared across the talent-pool feature tests.
 *
 * @return array{creator: Creator, relation: AgencyCreatorRelation}
 */
function makePooledRelation(Agency $agency): array
{
    $creator = Creator::factory()->createOne();
    $relation = AgencyCreatorRelation::factory()->create([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
    ]);

    return compact('creator', 'relation');
}

// ---------------------------------------------------------------------------
// Index
// ---------------------------------------------------------------------------

it('any role can list active pools', function (): void {
    ['agency' => $agency, 'user' => $user] = poolStaff();
    TalentPool::factory()->count(2)->forAgency($agency->id)->create();

    $this->actingAs($user)
        ->getJson("/api/v1/agencies/{$agency->ulid}/talent-pools")
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('list shows creators_count, not member rows (D-2b-7)', function (): void {
    ['agency' => $agency, 'user' => $user] = poolAdmin();
    $pool = TalentPool::factory()->forAgency($agency->id)->createOne();
    $relation = makePooledRelation($agency);
    $pool->creators()->attach($relation['creator']->id);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/agencies/{$agency->ulid}/talent-pools")
        ->assertOk()
        ->assertJsonPath('data.0.attributes.creators_count', 1)
        ->json();

    expect($response['data'][0]['attributes'])->not->toHaveKey('creators')
        ->and($response['data'][0]['attributes'])->not->toHaveKey('members');
});

it('archived pools are excluded from default list', function (): void {
    ['agency' => $agency, 'user' => $user] = poolAdmin();
    TalentPool::factory()->forAgency($agency->id)->create(['name' => 'Active Pool']);
    $archived = TalentPool::factory()->forAgency($agency->id)->createOne(['name' => 'Archived Pool']);
    $archived->delete();

    $this->actingAs($user)
        ->getJson("/api/v1/agencies/{$agency->ulid}/talent-pools")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.attributes.name', 'Active Pool');
});

it('returns archived pools when ?status=archived is passed', function (): void {
    ['agency' => $agency, 'user' => $user] = poolAdmin();
    TalentPool::factory()->forAgency($agency->id)->create(['name' => 'Active Pool']);
    $archived = TalentPool::factory()->forAgency($agency->id)->createOne(['name' => 'Archived Pool']);
    $archived->delete();

    $this->actingAs($user)
        ->getJson("/api/v1/agencies/{$agency->ulid}/talent-pools?status=archived")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.attributes.name', 'Archived Pool');
});

it('cross-tenant list returns 404', function (): void {
    ['agency' => $agency, 'user' => $user] = poolAdmin();
    $otherAgency = Agency::factory()->createOne();
    TalentPool::factory()->forAgency($otherAgency->id)->create();

    $this->actingAs($user)
        ->getJson("/api/v1/agencies/{$otherAgency->ulid}/talent-pools")
        ->assertNotFound();
});

it('unauthenticated list returns 401', function (): void {
    $agency = Agency::factory()->createOne();

    $this->getJson("/api/v1/agencies/{$agency->ulid}/talent-pools")
        ->assertUnauthorized();
});

// ---------------------------------------------------------------------------
// Store
// ---------------------------------------------------------------------------

it('agency_admin can create a pool', function (): void {
    ['agency' => $agency, 'user' => $user] = poolAdmin();

    $this->actingAs($user)
        ->postJson("/api/v1/agencies/{$agency->ulid}/talent-pools", [
            'name' => 'Acme Q3',
            'description' => 'For the Acme work.',
        ])
        ->assertCreated()
        ->assertJsonPath('data.attributes.name', 'Acme Q3')
        ->assertJsonPath('data.attributes.creators_count', 0);

    $this->assertDatabaseHas('talent_pools', [
        'name' => 'Acme Q3',
        'agency_id' => $agency->id,
        'created_by_user_id' => $user->id,
    ]);
});

it('agency_manager can create a pool', function (): void {
    ['agency' => $agency, 'user' => $user] = poolManager();

    $this->actingAs($user)
        ->postJson("/api/v1/agencies/{$agency->ulid}/talent-pools", ['name' => 'Manager Pool'])
        ->assertCreated();
});

it('agency_staff cannot create a pool', function (): void {
    ['agency' => $agency, 'user' => $user] = poolStaff();

    $this->actingAs($user)
        ->postJson("/api/v1/agencies/{$agency->ulid}/talent-pools", ['name' => 'Staff Pool'])
        ->assertForbidden();
});

it('pool creation emits talent_pool.created audit log', function (): void {
    ['agency' => $agency, 'user' => $user] = poolAdmin();

    $this->actingAs($user)
        ->postJson("/api/v1/agencies/{$agency->ulid}/talent-pools", ['name' => 'Audit Pool'])
        ->assertCreated();

    $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::TalentPoolCreated->value]);
});

it('pool name must be unique within agency (break-revert: unique_talent_pools_agency_name)', function (): void {
    ['agency' => $agency, 'user' => $user] = poolAdmin();
    TalentPool::factory()->forAgency($agency->id)->create(['name' => 'Taken Name']);

    $this->actingAs($user)
        ->postJson("/api/v1/agencies/{$agency->ulid}/talent-pools", ['name' => 'Taken Name'])
        ->assertUnprocessable();
});

it('same pool name is allowed across different agencies', function (): void {
    ['agency' => $agency, 'user' => $user] = poolAdmin();
    $otherAgency = Agency::factory()->createOne();
    TalentPool::factory()->forAgency($otherAgency->id)->create(['name' => 'Shared Name']);

    $this->actingAs($user)
        ->postJson("/api/v1/agencies/{$agency->ulid}/talent-pools", ['name' => 'Shared Name'])
        ->assertCreated();
});

it('an agency-wide pool (null brand) and a brand-scoped pool both create', function (): void {
    ['agency' => $agency, 'user' => $user] = poolAdmin();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();

    $this->actingAs($user)
        ->postJson("/api/v1/agencies/{$agency->ulid}/talent-pools", ['name' => 'Agency-wide'])
        ->assertCreated()
        ->assertJsonPath('data.attributes.brand_id', null);

    $this->actingAs($user)
        ->postJson("/api/v1/agencies/{$agency->ulid}/talent-pools", [
            'name' => 'Brand-scoped',
            'brand_id' => $brand->ulid,
        ])
        ->assertCreated()
        ->assertJsonPath('data.attributes.brand_id', $brand->ulid);
});

it('rejects a brand_id belonging to another agency', function (): void {
    ['agency' => $agency, 'user' => $user] = poolAdmin();
    $otherAgency = Agency::factory()->createOne();
    $foreignBrand = Brand::factory()->forAgency($otherAgency->id)->createOne();

    $this->actingAs($user)
        ->postJson("/api/v1/agencies/{$agency->ulid}/talent-pools", [
            'name' => 'Sneaky Pool',
            'brand_id' => $foreignBrand->ulid,
        ])
        ->assertUnprocessable();
});

it('pool creation validates required name', function (): void {
    ['agency' => $agency, 'user' => $user] = poolAdmin();

    $this->actingAs($user)
        ->postJson("/api/v1/agencies/{$agency->ulid}/talent-pools", [])
        ->assertUnprocessable();
});

// ---------------------------------------------------------------------------
// Show
// ---------------------------------------------------------------------------

it('any role can view a pool and the integer id is never exposed', function (): void {
    ['agency' => $agency, 'user' => $user] = poolStaff();
    $pool = TalentPool::factory()->forAgency($agency->id)->createOne();

    $response = $this->actingAs($user)
        ->getJson("/api/v1/agencies/{$agency->ulid}/talent-pools/{$pool->ulid}")
        ->assertOk()
        ->assertJsonPath('data.id', $pool->ulid)
        ->json();

    expect($response['data']['attributes'])->not->toHaveKey('id');
});

it('cross-tenant pool show returns 404 (break-revert: assertBelongsToAgency)', function (): void {
    ['agency' => $agency, 'user' => $user] = poolAdmin();
    $otherAgency = Agency::factory()->createOne();
    $pool = TalentPool::factory()->forAgency($otherAgency->id)->createOne();

    $this->actingAs($user)
        ->getJson("/api/v1/agencies/{$agency->ulid}/talent-pools/{$pool->ulid}")
        ->assertNotFound();
});

// ---------------------------------------------------------------------------
// Update
// ---------------------------------------------------------------------------

it('agency_manager can update a pool', function (): void {
    ['agency' => $agency, 'user' => $user] = poolManager();
    $pool = TalentPool::factory()->forAgency($agency->id)->createOne();

    $this->actingAs($user)
        ->patchJson("/api/v1/agencies/{$agency->ulid}/talent-pools/{$pool->ulid}", [
            'name' => 'Renamed Pool',
        ])
        ->assertOk()
        ->assertJsonPath('data.attributes.name', 'Renamed Pool');
});

it('agency_staff cannot update a pool', function (): void {
    ['agency' => $agency, 'user' => $user] = poolStaff();
    $pool = TalentPool::factory()->forAgency($agency->id)->createOne();

    $this->actingAs($user)
        ->patchJson("/api/v1/agencies/{$agency->ulid}/talent-pools/{$pool->ulid}", ['name' => 'X'])
        ->assertForbidden();
});

it('update emits talent_pool.updated audit log', function (): void {
    ['agency' => $agency, 'user' => $user] = poolAdmin();
    $pool = TalentPool::factory()->forAgency($agency->id)->createOne();

    $this->actingAs($user)
        ->patchJson("/api/v1/agencies/{$agency->ulid}/talent-pools/{$pool->ulid}", ['name' => 'New Name'])
        ->assertOk();

    $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::TalentPoolUpdated->value]);
});

it('re-saving the same name is not a false uniqueness 422', function (): void {
    ['agency' => $agency, 'user' => $user] = poolAdmin();
    $pool = TalentPool::factory()->forAgency($agency->id)->createOne(['name' => 'Stable Name']);

    $this->actingAs($user)
        ->patchJson("/api/v1/agencies/{$agency->ulid}/talent-pools/{$pool->ulid}", [
            'name' => 'Stable Name',
            'description' => 'Updated description',
        ])
        ->assertOk();
});

// ---------------------------------------------------------------------------
// Archive (DELETE) + Restore
// ---------------------------------------------------------------------------

it('agency_manager can archive a pool (soft-delete)', function (): void {
    ['agency' => $agency, 'user' => $user] = poolManager();
    $pool = TalentPool::factory()->forAgency($agency->id)->createOne();

    $this->actingAs($user)
        ->deleteJson("/api/v1/agencies/{$agency->ulid}/talent-pools/{$pool->ulid}")
        ->assertOk()
        ->assertJsonPath('data.attributes.is_archived', true);

    $this->assertSoftDeleted('talent_pools', ['id' => $pool->id]);
});

it('agency_staff cannot archive a pool', function (): void {
    ['agency' => $agency, 'user' => $user] = poolStaff();
    $pool = TalentPool::factory()->forAgency($agency->id)->createOne();

    $this->actingAs($user)
        ->deleteJson("/api/v1/agencies/{$agency->ulid}/talent-pools/{$pool->ulid}")
        ->assertForbidden();
});

it('archive preserves the membership rows (recoverable, D-2b-3)', function (): void {
    ['agency' => $agency, 'user' => $user] = poolAdmin();
    $pool = TalentPool::factory()->forAgency($agency->id)->createOne();
    $relation = makePooledRelation($agency);
    $pool->creators()->attach($relation['creator']->id);

    $this->actingAs($user)
        ->deleteJson("/api/v1/agencies/{$agency->ulid}/talent-pools/{$pool->ulid}")
        ->assertOk();

    // The pivot row survives a soft-delete (cascade fires only on hard delete).
    $this->assertDatabaseHas('talent_pool_creators', [
        'talent_pool_id' => $pool->id,
        'creator_id' => $relation['creator']->id,
    ]);
});

it('agency_admin can restore an archived pool', function (): void {
    ['agency' => $agency, 'user' => $user] = poolAdmin();
    $pool = TalentPool::factory()->forAgency($agency->id)->createOne();
    $pool->delete();

    $this->actingAs($user)
        ->postJson("/api/v1/agencies/{$agency->ulid}/talent-pools/{$pool->ulid}/restore")
        ->assertOk()
        ->assertJsonPath('data.attributes.is_archived', false);

    $this->assertDatabaseHas('talent_pools', ['id' => $pool->id, 'deleted_at' => null]);
});

it('agency_staff cannot restore a pool', function (): void {
    ['agency' => $agency, 'user' => $user] = poolStaff();
    $pool = TalentPool::factory()->forAgency($agency->id)->createOne();
    $pool->delete();

    $this->actingAs($user)
        ->postJson("/api/v1/agencies/{$agency->ulid}/talent-pools/{$pool->ulid}/restore")
        ->assertForbidden();
});

it('restoring an already-active pool is an idempotent no-op (no audit)', function (): void {
    ['agency' => $agency, 'user' => $user] = poolAdmin();
    $pool = TalentPool::factory()->forAgency($agency->id)->createOne();

    $this->actingAs($user)
        ->postJson("/api/v1/agencies/{$agency->ulid}/talent-pools/{$pool->ulid}/restore")
        ->assertOk();

    $this->assertDatabaseMissing('audit_logs', ['action' => AuditAction::TalentPoolRestored->value]);
});

it('agency_admin can permanently delete is not exposed — destroy is archive only', function (): void {
    // The DELETE verb maps to archive (soft-delete); there is no hard-delete
    // route. This pins that archiving leaves the row recoverable.
    ['agency' => $agency, 'user' => $user] = poolAdmin();
    $pool = TalentPool::factory()->forAgency($agency->id)->createOne();

    $this->actingAs($user)
        ->deleteJson("/api/v1/agencies/{$agency->ulid}/talent-pools/{$pool->ulid}")
        ->assertOk();

    expect(TalentPool::withTrashed()->find($pool->id))->not->toBeNull();
});
