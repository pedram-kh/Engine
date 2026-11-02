<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Brands\Models\Brand;
use App\Modules\Creators\Enums\RelationshipStatus;
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
// Add — the AH-051 `ended` relation guard
// ---------------------------------------------------------------------------

it('add REFUSES a severed relation — an `ended` creator cannot be re-pooled (422 pool.relation_ended)', function (): void {
    ['agency' => $agency, 'user' => $user] = poolAdmin();
    $pool = TalentPool::factory()->forAgency($agency->id)->createOne();
    ['creator' => $creator, 'relation' => $relation] = makePooledRelation($agency);
    $relation->update(['relationship_status' => RelationshipStatus::Ended]);

    $this->actingAs($user)
        ->postJson("/api/v1/agencies/{$agency->ulid}/talent-pools/{$pool->ulid}/creators", [
            'creator_id' => $creator->ulid,
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'pool.relation_ended');

    // The refusal is real, not cosmetic: no row was written.
    $this->assertDatabaseMissing('talent_pool_creators', [
        'talent_pool_id' => $pool->id,
        'creator_id' => $creator->id,
    ]);
});

it('the `ended` refusal is a 422, NOT the 404 a missing relation gets — the row exists and the agency can see it', function (): void {
    ['agency' => $agency, 'user' => $user] = poolAdmin();
    $pool = TalentPool::factory()->forAgency($agency->id)->createOne();
    ['creator' => $creator, 'relation' => $relation] = makePooledRelation($agency);
    $relation->update(['relationship_status' => RelationshipStatus::Ended]);

    // A severed relation is visible to the agency under its own `ended` roster
    // filter, so 404 would be a lie; the distinct code lets the SPA say why.
    $this->actingAs($user)
        ->postJson("/api/v1/agencies/{$agency->ulid}/talent-pools/{$pool->ulid}/creators", [
            'creator_id' => $creator->ulid,
        ])
        ->assertStatus(422);
});

it('ONLY `ended` is refused — every other status stays poolable (no over-block)', function (string $status): void {
    ['agency' => $agency, 'user' => $user] = poolAdmin();
    $pool = TalentPool::factory()->forAgency($agency->id)->createOne();
    ['creator' => $creator, 'relation' => $relation] = makePooledRelation($agency);
    $relation->update(['relationship_status' => $status]);

    $this->actingAs($user)
        ->postJson("/api/v1/agencies/{$agency->ulid}/talent-pools/{$pool->ulid}/creators", [
            'creator_id' => $creator->ulid,
        ])
        ->assertCreated();

    $this->assertDatabaseHas('talent_pool_creators', [
        'talent_pool_id' => $pool->id,
        'creator_id' => $creator->id,
    ]);
})->with([
    'roster' => RelationshipStatus::Roster->value,
    'external' => RelationshipStatus::External->value,
    'prospect' => RelationshipStatus::Prospect->value,
    'pending_request' => RelationshipStatus::PendingRequest->value,
    'declined' => RelationshipStatus::Declined->value,
]);

it('a re-connected creator becomes poolable again — `ended` is a state, not a tombstone', function (): void {
    ['agency' => $agency, 'user' => $user] = poolAdmin();
    $pool = TalentPool::factory()->forAgency($agency->id)->createOne();
    ['creator' => $creator, 'relation' => $relation] = makePooledRelation($agency);
    $url = "/api/v1/agencies/{$agency->ulid}/talent-pools/{$pool->ulid}/creators";
    $payload = ['creator_id' => $creator->ulid];

    $relation->update(['relationship_status' => RelationshipStatus::Ended]);
    $this->actingAs($user)->postJson($url, $payload)->assertStatus(422);

    // The relation is re-requestable (D-3); once back on the roster the pool
    // action must return with it.
    $relation->update(['relationship_status' => RelationshipStatus::Roster]);
    $this->actingAs($user)->postJson($url, $payload)->assertCreated();
});

// ---------------------------------------------------------------------------
// Remove (DELETE .../creators/{creator})
// ---------------------------------------------------------------------------

it('remove STAYS open for an `ended` relation — severed rows must always be cleanable', function (): void {
    ['agency' => $agency, 'user' => $user] = poolAdmin();
    $pool = TalentPool::factory()->forAgency($agency->id)->createOne();
    ['creator' => $creator, 'relation' => $relation] = makePooledRelation($agency);
    $pool->creators()->attach($creator->id);
    $relation->update(['relationship_status' => RelationshipStatus::Ended]);

    // Gating removal too would strand rows the add path now refuses to recreate.
    $this->actingAs($user)
        ->deleteJson("/api/v1/agencies/{$agency->ulid}/talent-pools/{$pool->ulid}/creators/{$creator->ulid}")
        ->assertOk();

    $this->assertDatabaseMissing('talent_pool_creators', [
        'talent_pool_id' => $pool->id,
        'creator_id' => $creator->id,
    ]);
});

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
// Member-list blacklist badge (D-3/D-4) — status + type ON the member resource,
// scoped to the pool-owning agency. Warn-don't-remove: a blacklisted creator
// STAYS a member; the badge is the visibility, not a removal.
// ---------------------------------------------------------------------------

it('emits is_blacklisted + blacklist_type for a blacklisted member (D-3)', function (): void {
    ['agency' => $agency, 'user' => $user] = poolStaff();
    $pool = TalentPool::factory()->forAgency($agency->id)->createOne();

    $creator = Creator::factory()->createOne();
    AgencyCreatorRelation::factory()->blacklisted()->create([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
    ]);
    $pool->creators()->attach($creator->id);

    $this->actingAs($user)
        ->getJson("/api/v1/agencies/{$agency->ulid}/talent-pools/{$pool->ulid}/creators")
        ->assertOk()
        ->assertJsonPath('data.0.attributes.is_blacklisted', true)
        ->assertJsonPath('data.0.attributes.blacklist_type', 'hard');
});

it('emits a soft blacklist type distinctly from hard', function (): void {
    ['agency' => $agency, 'user' => $user] = poolStaff();
    $pool = TalentPool::factory()->forAgency($agency->id)->createOne();

    $creator = Creator::factory()->createOne();
    AgencyCreatorRelation::factory()->blacklisted()->create([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'blacklist_type' => 'soft',
    ]);
    $pool->creators()->attach($creator->id);

    $this->actingAs($user)
        ->getJson("/api/v1/agencies/{$agency->ulid}/talent-pools/{$pool->ulid}/creators")
        ->assertOk()
        ->assertJsonPath('data.0.attributes.is_blacklisted', true)
        ->assertJsonPath('data.0.attributes.blacklist_type', 'soft');
});

it('emits is_blacklisted=false + null type for a clean member', function (): void {
    ['agency' => $agency, 'user' => $user] = poolStaff();
    $pool = TalentPool::factory()->forAgency($agency->id)->createOne();
    ['creator' => $creator] = makePooledRelation($agency);
    $pool->creators()->attach($creator->id);

    $this->actingAs($user)
        ->getJson("/api/v1/agencies/{$agency->ulid}/talent-pools/{$pool->ulid}/creators")
        ->assertOk()
        ->assertJsonPath('data.0.attributes.is_blacklisted', false)
        ->assertJsonPath('data.0.attributes.blacklist_type', null);
});

it('does NOT emit the blacklist_reason (2a parity, D-3)', function (): void {
    ['agency' => $agency, 'user' => $user] = poolStaff();
    $pool = TalentPool::factory()->forAgency($agency->id)->createOne();

    $creator = Creator::factory()->createOne();
    AgencyCreatorRelation::factory()->blacklisted('Secret private reason')->create([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
    ]);
    $pool->creators()->attach($creator->id);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/agencies/{$agency->ulid}/talent-pools/{$pool->ulid}/creators")
        ->assertOk()
        ->json('data.0.attributes');

    expect($response)->not->toHaveKey('blacklist_reason')
        ->and($response)->not->toHaveKey('blacklist_scope');
});

it('the blacklist join is SCOPED to the pool-owning agency — agency A blacklist is INVISIBLE in agency B pool (D-4, break-revert: the privacy pin)', function (): void {
    // Agency B owns the pool + the user listing it.
    ['agency' => $agencyB, 'user' => $userB] = poolStaff();
    $pool = TalentPool::factory()->forAgency($agencyB->id)->createOne();

    // A creator HARD-blacklisted by a DIFFERENT agency A. The creator is a
    // member of B's pool but has no blacklist (and here no relation) with B.
    $agencyA = Agency::factory()->createOne();
    $creator = Creator::factory()->createOne();
    AgencyCreatorRelation::factory()->blacklisted()->create([
        'agency_id' => $agencyA->id,
        'creator_id' => $creator->id,
    ]);
    $pool->creators()->attach($creator->id);

    // B's member list must show NO blacklist — the subqueries are scoped to
    // agency_id = pool.agency_id (= B), so A's blacklist never surfaces here.
    // Break-revert: un-scope the join → A's hard blacklist leaks → is_blacklisted true.
    $this->actingAs($userB)
        ->getJson("/api/v1/agencies/{$agencyB->ulid}/talent-pools/{$pool->ulid}/creators")
        ->assertOk()
        ->assertJsonPath('data.0.id', $creator->ulid)
        ->assertJsonPath('data.0.attributes.is_blacklisted', false)
        ->assertJsonPath('data.0.attributes.blacklist_type', null);
});

// ---------------------------------------------------------------------------
// Members list filters (AH-116, item 4) — the shared creator-column trait on
// the pool detail's members list. Country and language have UI; category, age
// and `q` ride in with the trait and are pinned so the surface cannot drift.
// ---------------------------------------------------------------------------

/**
 * A creator with a relation to the agency, attached to the pool, with the given
 * creators-column attributes.
 *
 * @param  array<string, mixed>  $attributes
 */
function pooledMember(Agency $agency, TalentPool $pool, array $attributes = []): Creator
{
    $creator = Creator::factory()->createOne($attributes);
    AgencyCreatorRelation::factory()->create([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
    ]);
    $pool->creators()->attach($creator->id);

    return $creator;
}

function poolMembersUrl(Agency $agency, TalentPool $pool, string $query = ''): string
{
    return "/api/v1/agencies/{$agency->ulid}/talent-pools/{$pool->ulid}/creators".($query === '' ? '' : "?{$query}");
}

it('narrows the members by country (data and meta.total)', function (): void {
    ['agency' => $agency, 'user' => $user] = poolStaff();
    $pool = TalentPool::factory()->forAgency($agency->id)->createOne();
    $pt = pooledMember($agency, $pool, ['country_code' => 'PT']);
    pooledMember($agency, $pool, ['country_code' => 'ES']);
    pooledMember($agency, $pool, ['country_code' => 'ES']);

    $response = $this->actingAs($user)->getJson(poolMembersUrl($agency, $pool, 'country=PT'))->assertOk();

    expect($response->json('meta.total'))->toBe(1)
        ->and($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.id'))->toBe($pt->ulid);
});

it('narrows the members by language', function (): void {
    ['agency' => $agency, 'user' => $user] = poolStaff();
    $pool = TalentPool::factory()->forAgency($agency->id)->createOne();
    $it = pooledMember($agency, $pool, ['primary_language' => 'it']);
    pooledMember($agency, $pool, ['primary_language' => 'en']);

    $response = $this->actingAs($user)->getJson(poolMembersUrl($agency, $pool, 'language=it'))->assertOk();

    expect($response->json('meta.total'))->toBe(1)
        ->and($response->json('data.0.id'))->toBe($it->ulid);
});

it('ANDs country and language together', function (): void {
    ['agency' => $agency, 'user' => $user] = poolStaff();
    $pool = TalentPool::factory()->forAgency($agency->id)->createOne();
    $both = pooledMember($agency, $pool, ['country_code' => 'IT', 'primary_language' => 'it']);
    pooledMember($agency, $pool, ['country_code' => 'IT', 'primary_language' => 'en']);
    pooledMember($agency, $pool, ['country_code' => 'ES', 'primary_language' => 'it']);

    $response = $this->actingAs($user)->getJson(poolMembersUrl($agency, $pool, 'country=IT&language=it'))->assertOk();

    expect($response->json('meta.total'))->toBe(1)
        ->and($response->json('data.0.id'))->toBe($both->ulid);
});

it('never returns a creator who matches the filter but belongs to a DIFFERENT pool (cross-pool negative)', function (): void {
    ['agency' => $agency, 'user' => $user] = poolStaff();
    $pool = TalentPool::factory()->forAgency($agency->id)->createOne();
    $otherPool = TalentPool::factory()->forAgency($agency->id)->createOne();
    $mine = pooledMember($agency, $pool, ['country_code' => 'PT']);
    pooledMember($agency, $otherPool, ['country_code' => 'PT']);

    $response = $this->actingAs($user)->getJson(poolMembersUrl($agency, $pool, 'country=PT'))->assertOk();

    expect($response->json('meta.total'))->toBe(1)
        ->and($response->json('data.0.id'))->toBe($mine->ulid);
});

it('keeps another agency\'s pool a 404 under a filter, and does not leak a shared creator across agencies (cross-agency negative)', function (): void {
    ['agency' => $agency, 'user' => $user] = poolStaff();
    $foreignAgency = Agency::factory()->createOne();
    $foreignPool = TalentPool::factory()->forAgency($foreignAgency->id)->createOne();
    pooledMember($foreignAgency, $foreignPool, ['country_code' => 'PT']);

    $this->actingAs($user)
        ->getJson("/api/v1/agencies/{$agency->ulid}/talent-pools/{$foreignPool->ulid}/creators?country=PT")
        ->assertNotFound();

    // A creator in both agencies' pools shows only in the pool asked for.
    $pool = TalentPool::factory()->forAgency($agency->id)->createOne();
    $shared = pooledMember($agency, $pool, ['country_code' => 'PT']);
    $foreignPool->creators()->attach($shared->id);

    $response = $this->actingAs($user)->getJson(poolMembersUrl($agency, $pool, 'country=PT'))->assertOk();
    expect($response->json('meta.total'))->toBe(1)
        ->and($response->json('data.0.id'))->toBe($shared->ulid);
});

it('keeps the blacklist badge on a filtered row, scoped to the pool-owning agency', function (): void {
    ['agency' => $agency, 'user' => $user] = poolStaff();
    $pool = TalentPool::factory()->forAgency($agency->id)->createOne();

    $creator = Creator::factory()->createOne(['country_code' => 'PT']);
    AgencyCreatorRelation::factory()->blacklisted()->create([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
    ]);
    $pool->creators()->attach($creator->id);
    // Another agency also blacklists a second creator: it must not show here.
    $clean = Creator::factory()->createOne(['country_code' => 'PT']);
    AgencyCreatorRelation::factory()->blacklisted()->create([
        'agency_id' => Agency::factory()->createOne()->id,
        'creator_id' => $clean->id,
    ]);
    AgencyCreatorRelation::factory()->create(['agency_id' => $agency->id, 'creator_id' => $clean->id]);
    $pool->creators()->attach($clean->id);

    $response = $this->actingAs($user)->getJson(poolMembersUrl($agency, $pool, 'country=PT'))->assertOk();

    expect($response->json('meta.total'))->toBe(2);

    $badges = [];
    foreach ($response->json('data') as $row) {
        $badges[(string) $row['id']] = $row['attributes'];
    }

    expect($badges[$creator->ulid]['is_blacklisted'])->toBeTrue()
        ->and($badges[$creator->ulid]['blacklist_type'])->toBe('hard')
        ->and($badges[$clean->ulid]['is_blacklisted'])->toBeFalse();
});

it('accepts age, category and q through the shared trait (the all-or-nothing surface, pinned)', function (): void {
    ['agency' => $agency, 'user' => $user] = poolStaff();
    $pool = TalentPool::factory()->forAgency($agency->id)->createOne();
    $young = pooledMember($agency, $pool, [
        'display_name' => 'Young Yara',
        'categories' => ['fitness'],
        'date_of_birth' => now()->subYears(22)->toDateString(),
    ]);
    pooledMember($agency, $pool, [
        'display_name' => 'Older Otto',
        'categories' => ['gaming'],
        'date_of_birth' => now()->subYears(48)->toDateString(),
    ]);

    $age = $this->actingAs($user)->getJson(poolMembersUrl($agency, $pool, 'age_min=20&age_max=30'))->assertOk();
    $category = $this->actingAs($user)->getJson(poolMembersUrl($agency, $pool, 'category=fitness'))->assertOk();
    $search = $this->actingAs($user)->getJson(poolMembersUrl($agency, $pool, 'q=yara'))->assertOk();

    foreach ([$age, $category, $search] as $response) {
        expect($response->json('meta.total'))->toBe(1)
            ->and($response->json('data.0.id'))->toBe($young->ulid);
    }
});

it('returns an empty list for an unknown filter value and ignores an empty one', function (): void {
    ['agency' => $agency, 'user' => $user] = poolStaff();
    $pool = TalentPool::factory()->forAgency($agency->id)->createOne();
    pooledMember($agency, $pool, ['country_code' => 'PT']);
    pooledMember($agency, $pool, ['country_code' => 'ES']);

    $unknown = $this->actingAs($user)->getJson(poolMembersUrl($agency, $pool, 'country=ZZ'))->assertOk();
    $empty = $this->actingAs($user)->getJson(poolMembersUrl($agency, $pool, 'country=&language='))->assertOk();

    expect($unknown->json('meta.total'))->toBe(0)
        ->and($unknown->json('data'))->toBe([])
        ->and($empty->json('meta.total'))->toBe(2);
});

it('paginates a filtered list: 26 matches give 25 on page 1, one on page 2, total 26', function (): void {
    ['agency' => $agency, 'user' => $user] = poolStaff();
    $pool = TalentPool::factory()->forAgency($agency->id)->createOne();
    for ($i = 0; $i < 26; $i++) {
        pooledMember($agency, $pool, ['country_code' => 'PT']);
    }
    pooledMember($agency, $pool, ['country_code' => 'ES']);

    $page1 = $this->actingAs($user)->getJson(poolMembersUrl($agency, $pool, 'country=PT'))->assertOk();
    $page2 = $this->actingAs($user)->getJson(poolMembersUrl($agency, $pool, 'country=PT&page=2'))->assertOk();

    expect($page1->json('meta.total'))->toBe(26)
        ->and($page1->json('data'))->toHaveCount(25)
        ->and($page2->json('data'))->toHaveCount(1);
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
