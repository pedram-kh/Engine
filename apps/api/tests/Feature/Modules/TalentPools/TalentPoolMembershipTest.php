<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Brands\Models\Brand;
use App\Modules\Creators\Models\Creator;
use App\Modules\TalentPools\Models\TalentPool;
use App\Modules\TalentPools\Models\TalentPoolMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

// Helpers poolAdmin() / poolManager() / poolStaff() / makePooledRelation()
// are declared in TalentPoolCrudTest.php and shared suite-wide.

// ---------------------------------------------------------------------------
// Add (POST .../creators)
// ---------------------------------------------------------------------------

it('agency_manager can add a roster creator to a pool', function (): void {
    ['agency' => $agency, 'user' => $user] = poolManager();
    $pool = TalentPool::factory()->forAgency($agency->id)->createOne();
    ['creator' => $creator] = makePooledRelation($agency);

    $this->actingAs($user)
        ->postJson("/api/v1/agencies/{$agency->ulid}/talent-pools/{$pool->ulid}/creators", [
            'creator_id' => $creator->ulid,
        ])
        ->assertCreated()
        ->assertJsonPath('data.attributes.creators_count', 1);

    $this->assertDatabaseHas('talent_pool_creators', [
        'talent_pool_id' => $pool->id,
        'creator_id' => $creator->id,
        'added_by_user_id' => $user->id,
    ]);
});

it('agency_staff cannot add a creator to a pool (403)', function (): void {
    ['agency' => $agency, 'user' => $user] = poolStaff();
    $pool = TalentPool::factory()->forAgency($agency->id)->createOne();
    ['creator' => $creator] = makePooledRelation($agency);

    $this->actingAs($user)
        ->postJson("/api/v1/agencies/{$agency->ulid}/talent-pools/{$pool->ulid}/creators", [
            'creator_id' => $creator->ulid,
        ])
        ->assertForbidden();

    $this->assertDatabaseMissing('talent_pool_creators', [
        'talent_pool_id' => $pool->id,
        'creator_id' => $creator->id,
    ]);
});

it('add requires an AgencyCreatorRelation (a creator with NO relation → 404, break-revert: requireRosterRelation)', function (): void {
    ['agency' => $agency, 'user' => $user] = poolAdmin();
    $pool = TalentPool::factory()->forAgency($agency->id)->createOne();
    // A creator that exists but has NO relation with this agency.
    $stranger = Creator::factory()->createOne();

    $this->actingAs($user)
        ->postJson("/api/v1/agencies/{$agency->ulid}/talent-pools/{$pool->ulid}/creators", [
            'creator_id' => $stranger->ulid,
        ])
        ->assertNotFound();

    $this->assertDatabaseMissing('talent_pool_creators', [
        'talent_pool_id' => $pool->id,
        'creator_id' => $stranger->id,
    ]);
});

it('add is idempotent — adding twice yields one row, not a 500/dup (break-revert: firstOrCreate)', function (): void {
    ['agency' => $agency, 'user' => $user] = poolAdmin();
    $pool = TalentPool::factory()->forAgency($agency->id)->createOne();
    ['creator' => $creator] = makePooledRelation($agency);

    $url = "/api/v1/agencies/{$agency->ulid}/talent-pools/{$pool->ulid}/creators";
    $payload = ['creator_id' => $creator->ulid];

    $this->actingAs($user)->postJson($url, $payload)->assertCreated();
    // The second add is a 200 no-op (already a member), not a 500 / duplicate.
    $this->actingAs($user)->postJson($url, $payload)->assertOk();

    expect(TalentPoolMembership::query()
        ->where('talent_pool_id', $pool->id)
        ->where('creator_id', $creator->id)
        ->count())->toBe(1);
});

it('brand-scope adds NO eligibility constraint — a creator with no brand link can join a brand-scoped pool (D-2b-4)', function (): void {
    ['agency' => $agency, 'user' => $user] = poolAdmin();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();
    $pool = TalentPool::factory()->forAgency($agency->id)->forBrand($brand->id)->createOne();
    // The creator has a relation with the agency but no link to the brand —
    // there is no inclusionary brand↔creator table in P1, so this must work.
    ['creator' => $creator] = makePooledRelation($agency);

    $this->actingAs($user)
        ->postJson("/api/v1/agencies/{$agency->ulid}/talent-pools/{$pool->ulid}/creators", [
            'creator_id' => $creator->ulid,
        ])
        ->assertCreated();
});

it('add composes the agency-owns-pool check — a pool from another agency → 404 (break-revert: assertBelongsToAgency)', function (): void {
    ['agency' => $agency, 'user' => $user] = poolAdmin();
    $otherAgency = Agency::factory()->createOne();
    $foreignPool = TalentPool::factory()->forAgency($otherAgency->id)->createOne();
    ['creator' => $creator] = makePooledRelation($agency);

    $this->actingAs($user)
        ->postJson("/api/v1/agencies/{$agency->ulid}/talent-pools/{$foreignPool->ulid}/creators", [
            'creator_id' => $creator->ulid,
        ])
        ->assertNotFound();
});

it('add emits talent_pool.creator_added audit log', function (): void {
    ['agency' => $agency, 'user' => $user] = poolAdmin();
    $pool = TalentPool::factory()->forAgency($agency->id)->createOne();
    ['creator' => $creator] = makePooledRelation($agency);

    $this->actingAs($user)
        ->postJson("/api/v1/agencies/{$agency->ulid}/talent-pools/{$pool->ulid}/creators", [
            'creator_id' => $creator->ulid,
        ])
        ->assertCreated();

    $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::TalentPoolCreatorAdded->value]);
});

// ---------------------------------------------------------------------------
// Remove (DELETE .../creators/{creator})
// ---------------------------------------------------------------------------

it('agency_manager can remove a creator from a pool', function (): void {
    ['agency' => $agency, 'user' => $user] = poolManager();
    $pool = TalentPool::factory()->forAgency($agency->id)->createOne();
    ['creator' => $creator] = makePooledRelation($agency);
    $pool->creators()->attach($creator->id);

    $this->actingAs($user)
        ->deleteJson("/api/v1/agencies/{$agency->ulid}/talent-pools/{$pool->ulid}/creators/{$creator->ulid}")
        ->assertOk()
        ->assertJsonPath('data.attributes.creators_count', 0);

    $this->assertDatabaseMissing('talent_pool_creators', [
        'talent_pool_id' => $pool->id,
        'creator_id' => $creator->id,
    ]);
});

it('agency_staff cannot remove a creator from a pool (403)', function (): void {
    ['agency' => $agency, 'user' => $user] = poolStaff();
    $pool = TalentPool::factory()->forAgency($agency->id)->createOne();
    ['creator' => $creator] = makePooledRelation($agency);
    $pool->creators()->attach($creator->id);

    $this->actingAs($user)
        ->deleteJson("/api/v1/agencies/{$agency->ulid}/talent-pools/{$pool->ulid}/creators/{$creator->ulid}")
        ->assertForbidden();

    $this->assertDatabaseHas('talent_pool_creators', [
        'talent_pool_id' => $pool->id,
        'creator_id' => $creator->id,
    ]);
});

it('remove emits talent_pool.creator_removed audit log', function (): void {
    ['agency' => $agency, 'user' => $user] = poolAdmin();
    $pool = TalentPool::factory()->forAgency($agency->id)->createOne();
    ['creator' => $creator] = makePooledRelation($agency);
    $pool->creators()->attach($creator->id);

    $this->actingAs($user)
        ->deleteJson("/api/v1/agencies/{$agency->ulid}/talent-pools/{$pool->ulid}/creators/{$creator->ulid}")
        ->assertOk();

    $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::TalentPoolCreatorRemoved->value]);
});

// ---------------------------------------------------------------------------
// Members list (GET .../creators) — pool detail page
// ---------------------------------------------------------------------------

it('lists pool members (paginated) for any agency member', function (): void {
    ['agency' => $agency, 'user' => $user] = poolStaff();
    $pool = TalentPool::factory()->forAgency($agency->id)->createOne();
    ['creator' => $creator] = makePooledRelation($agency);
    $pool->creators()->attach($creator->id);

    $this->actingAs($user)
        ->getJson("/api/v1/agencies/{$agency->ulid}/talent-pools/{$pool->ulid}/creators")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $creator->ulid)
        ->assertJsonPath('data.0.type', 'talent_pool_members');
});

// ---------------------------------------------------------------------------
// Picker fetch (GET creators/{creator}/talent-pools)
// ---------------------------------------------------------------------------

it('picker lists the agency pools with an is_member flag for the creator', function (): void {
    ['agency' => $agency, 'user' => $user] = poolAdmin();
    ['creator' => $creator] = makePooledRelation($agency);

    $memberPool = TalentPool::factory()->forAgency($agency->id)->createOne(['name' => 'A In']);
    $otherPool = TalentPool::factory()->forAgency($agency->id)->createOne(['name' => 'B Out']);
    $memberPool->creators()->attach($creator->id);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/agencies/{$agency->ulid}/creators/{$creator->ulid}/talent-pools")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->json();

    $byName = collect((array) $response['data'])->keyBy('attributes.name');
    expect($byName['A In']['attributes']['is_member'])->toBeTrue()
        ->and($byName['B Out']['attributes']['is_member'])->toBeFalse();
});

it('picker 404s for a creator with no relation to the agency (requireRosterRelation)', function (): void {
    ['agency' => $agency, 'user' => $user] = poolAdmin();
    $stranger = Creator::factory()->createOne();
    TalentPool::factory()->forAgency($agency->id)->create();

    $this->actingAs($user)
        ->getJson("/api/v1/agencies/{$agency->ulid}/creators/{$stranger->ulid}/talent-pools")
        ->assertNotFound();
});
