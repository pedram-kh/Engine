<?php

declare(strict_types=1);

use App\Modules\Agencies\Enums\BlacklistScope;
use App\Modules\Agencies\Enums\BlacklistType;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Agencies\Models\BrandCreatorBlacklist;
use App\Modules\Creators\Enums\ApplicationStatus;
use App\Modules\Creators\Enums\RelationshipStatus;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Sprint 7 — ⚠ Part B: making blacklisting EFFECTIVE (the load-bearing, net-new
 * integration — reviewed separately from Part A).
 *
 *   B1  Discovery exclusion (calling-agency-scoped, hard-only).
 *   B2  Connection-request gate (hard blocks send; soft does not).
 *   B3  Scope-aware KPI counts (agency-wide drops; brand-scoped does not).
 *   B4  Cross-agency isolation of blacklist facts + the reason (the privacy pin).
 */
function discoverList(Agency $agency): string
{
    return "/api/v1/agencies/{$agency->ulid}/creators/discover";
}

function hardBlacklist(Agency $agency, Creator $creator, string $reason = 'private reason'): void
{
    AgencyCreatorRelation::factory()->create([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => RelationshipStatus::Roster,
        'is_blacklisted' => true,
        'blacklist_scope' => BlacklistScope::Agency,
        'blacklist_type' => BlacklistType::Hard,
        'blacklist_reason' => $reason,
        'blacklisted_at' => now(),
    ]);
}

function discoveredUlids(array $json): array
{
    return array_map(fn (array $row): string => $row['id'], $json['data'] ?? []);
}

// ===========================================================================
// B1 + B4 — discovery exclusion is calling-agency-scoped + hard-only
// ===========================================================================

it('drops a HARD agency-wide-blacklisted creator from the blacklisting agency discovery', function (): void {
    $agencyA = Agency::factory()->createOne();
    $adminA = User::factory()->agencyAdmin($agencyA)->createOne();
    $creator = Creator::factory()->approved()->createOne(['display_name' => 'Shared Sam']);
    hardBlacklist($agencyA, $creator);

    $json = $this->actingAs($adminA)->getJson(discoverList($agencyA))->assertOk()->json();

    expect(discoveredUlids($json))->not->toContain($creator->ulid);
});

it('STILL shows that creator to ANOTHER agency (the per-agency isolation pin — break-revert)', function (): void {
    // Agency A hard-blacklists a SHARED creator. Agency B must still discover
    // them. Break-revert: un-scope the whereNotExists agency_id leg → the
    // creator vanishes for B too (a P0 cross-agency violation).
    $agencyA = Agency::factory()->createOne();
    $agencyB = Agency::factory()->createOne();
    $adminB = User::factory()->agencyAdmin($agencyB)->createOne();

    $creator = Creator::factory()->approved()->createOne(['display_name' => 'Shared Sam']);
    hardBlacklist($agencyA, $creator, 'A-only secret reason');

    $response = $this->actingAs($adminB)->getJson(discoverList($agencyB))->assertOk();
    $json = $response->json();

    expect(discoveredUlids($json))->toContain($creator->ulid);

    // B4 — no blacklist facts, no A's reason anywhere in B's payload.
    $body = $response->getContent();
    expect($body)->not->toContain('A-only secret reason')
        ->and($body)->not->toContain('is_blacklisted')
        ->and($body)->not->toContain('blacklist_reason');
});

it('does NOT exclude a SOFT agency-wide blacklist from discovery (soft = warn only, D-1)', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $creator = Creator::factory()->approved()->createOne();
    AgencyCreatorRelation::factory()->create([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => RelationshipStatus::Roster,
        'is_blacklisted' => true,
        'blacklist_scope' => BlacklistScope::Agency,
        'blacklist_type' => BlacklistType::Soft,
        'blacklist_reason' => 'soft warning',
        'blacklisted_at' => now(),
    ]);

    $json = $this->actingAs($admin)->getJson(discoverList($agency))->assertOk()->json();

    expect(discoveredUlids($json))->toContain($creator->ulid);
});

it('does NOT let a brand-scoped blacklist affect discovery (agency-level, no brand context)', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $creator = Creator::factory()->approved()->createOne();
    BrandCreatorBlacklist::factory()->create([
        'creator_id' => $creator->id,
        'blacklist_type' => BlacklistType::Hard,
    ]);

    $json = $this->actingAs($admin)->getJson(discoverList($agency))->assertOk()->json();

    expect(discoveredUlids($json))->toContain($creator->ulid);
});

// ===========================================================================
// B2 — connection-request gate (hard blocks; soft does not)
// ===========================================================================

it('blocks a connection request to a HARD agency-wide-blacklisted creator (typed 422)', function (): void {
    Mail::fake();
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $creator = Creator::factory()->approved()->createOne();
    AgencyCreatorRelation::factory()->blacklisted()->create([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => RelationshipStatus::Roster,
    ]);

    $url = "/api/v1/agencies/{$agency->ulid}/creators/discover/{$creator->ulid}/connection-request";
    $this->actingAs($admin)->postJson($url)
        ->assertStatus(422)
        ->assertJsonPath('meta.code', 'connection.blacklisted');

    Mail::assertNothingQueued();
});

it('does NOT block a SOFT-blacklisted creator from a connection request (warn only)', function (): void {
    Mail::fake();
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $creator = Creator::factory()->approved()->createOne();
    AgencyCreatorRelation::factory()->create([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => RelationshipStatus::Declined,
        'is_blacklisted' => true,
        'blacklist_scope' => BlacklistScope::Agency,
        'blacklist_type' => BlacklistType::Soft,
        'blacklist_reason' => 'soft warning',
        'blacklisted_at' => now(),
    ]);

    $url = "/api/v1/agencies/{$agency->ulid}/creators/discover/{$creator->ulid}/connection-request";
    // declined → pending_request re-request is allowed despite the soft flag.
    $this->actingAs($admin)->postJson($url)
        ->assertOk()
        ->assertJsonPath('data.attributes.relationship_status', 'pending_request');
});

// ===========================================================================
// B3 — scope-aware KPI counts
// ===========================================================================

it('drops an AGENCY-WIDE-blacklisted creator from the roster count', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    // Two clean roster creators.
    foreach (range(1, 2) as $_) {
        AgencyCreatorRelation::factory()->create([
            'agency_id' => $agency->id,
            'creator_id' => Creator::factory()->approved()->createOne()->id,
            'relationship_status' => RelationshipStatus::Roster,
        ]);
    }
    // One agency-wide blacklisted roster creator.
    AgencyCreatorRelation::factory()->blacklisted()->create([
        'agency_id' => $agency->id,
        'creator_id' => Creator::factory()->approved()->createOne()->id,
        'relationship_status' => RelationshipStatus::Roster,
    ]);

    $count = $this->actingAs($admin)
        ->getJson("/api/v1/agencies/{$agency->ulid}/dashboard/summary")
        ->assertOk()
        ->json('data.creators_in_roster');

    expect($count)->toBe(2);
});

it('does NOT drop a BRAND-scoped-blacklisted creator from the roster count (break-revert the boolean)', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $creator = Creator::factory()->approved()->createOne();
    AgencyCreatorRelation::factory()->create([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => RelationshipStatus::Roster,
    ]);
    // A brand-scoped blacklist (table row, relation untouched — D-2).
    BrandCreatorBlacklist::factory()->create(['creator_id' => $creator->id]);

    $count = $this->actingAs($admin)
        ->getJson("/api/v1/agencies/{$agency->ulid}/dashboard/summary")
        ->assertOk()
        ->json('data.creators_in_roster');

    // Brand-scoped is NOT an agency-wide exclusion — the creator stays counted.
    expect($count)->toBe(1);
});

it('drops an AGENCY-WIDE-blacklisted creator from the pending-applications count', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $pendingClean = Creator::factory()->createOne(['application_status' => ApplicationStatus::Pending]);
    AgencyCreatorRelation::factory()->create([
        'agency_id' => $agency->id,
        'creator_id' => $pendingClean->id,
        'relationship_status' => RelationshipStatus::Roster,
    ]);

    $pendingBlacklisted = Creator::factory()->createOne(['application_status' => ApplicationStatus::Pending]);
    AgencyCreatorRelation::factory()->blacklisted()->create([
        'agency_id' => $agency->id,
        'creator_id' => $pendingBlacklisted->id,
        'relationship_status' => RelationshipStatus::Roster,
    ]);

    $count = $this->actingAs($admin)
        ->getJson("/api/v1/agencies/{$agency->ulid}/dashboard/summary")
        ->assertOk()
        ->json('data.pending_creator_applications');

    expect($count)->toBe(1);
});
