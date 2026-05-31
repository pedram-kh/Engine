<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Creators\Enums\ApplicationStatus;
use App\Modules\Creators\Enums\RelationshipStatus;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

/**
 * Sprint 4 Chunk 1 (1b) — GET /api/v1/agencies/{agency}/dashboard/summary.
 *
 * One agency-scoped payload with the four workspace-home KPIs. Two real
 * (roster count, pending-applications count — the D-c1-7 denominator), two
 * stable `null` placeholders (campaigns, payments). Blacklist (boolean-only)
 * is excluded from BOTH real counts per the chunk-1 plan-pause decision.
 */

/**
 * Create a roster/relationship row for the given agency + a fresh creator,
 * returning the creator so the caller can tweak its application_status.
 *
 * @param  array<string, mixed>  $relationAttributes
 * @param  array<string, mixed>  $creatorAttributes
 */
function makeRelatedCreator(
    Agency $agency,
    array $relationAttributes = [],
    array $creatorAttributes = [],
): Creator {
    $creator = Creator::factory()->create($creatorAttributes);

    AgencyCreatorRelation::factory()->create(array_merge([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => RelationshipStatus::Roster,
        'is_blacklisted' => false,
    ], $relationAttributes));

    return $creator;
}

// ---------------------------------------------------------------------------
// Auth + tenancy boundary
// ---------------------------------------------------------------------------

it('returns 401 when no user is authenticated', function (): void {
    $agency = Agency::factory()->createOne();

    $response = $this->getJson("/api/v1/agencies/{$agency->ulid}/dashboard/summary");

    expect($response->status())->toBe(401);
});

it('returns 404 when the authenticated user is not a member (tenancy.agency invisibility)', function (): void {
    $agency = Agency::factory()->createOne();
    $otherAgency = Agency::factory()->createOne();
    $outsider = User::factory()->agencyAdmin($otherAgency)->createOne();

    $response = $this->actingAs($outsider)
        ->getJson("/api/v1/agencies/{$agency->ulid}/dashboard/summary");

    expect($response->status())->toBe(404);
});

it('returns 200 for any agency member (no admin / MFA gate)', function (): void {
    $agency = Agency::factory()->createOne();
    $staff = User::factory()->agencyStaff($agency)->createOne();

    $response = $this->actingAs($staff)
        ->getJson("/api/v1/agencies/{$agency->ulid}/dashboard/summary");

    expect($response->status())->toBe(200);
});

// ---------------------------------------------------------------------------
// Payload shape + placeholders
// ---------------------------------------------------------------------------

it('returns the four KPI keys with placeholders as null and counts as ints', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $response = $this->actingAs($admin)
        ->getJson("/api/v1/agencies/{$agency->ulid}/dashboard/summary");

    expect($response->status())->toBe(200);

    $data = $response->json('data');
    expect(array_keys($data))->toEqualCanonicalizing([
        'creators_in_roster',
        'pending_creator_applications',
        'active_campaigns',
        'payments_due',
    ]);
    expect($data['active_campaigns'])->toBeNull();
    expect($data['payments_due'])->toBeNull();
    expect($data['creators_in_roster'])->toBeInt()->toBe(0);
    expect($data['pending_creator_applications'])->toBeInt()->toBe(0);
});

it('pins the four-key null-placeholder contract (placeholders PRESENT and explicitly null, not absent)', function (): void {
    // The SPA renders the muted "—" off a null value. A future refactor that
    // DROPS the campaigns/payments keys (instead of nulling them) would
    // silently break the placeholder cards. `array_key_exists` distinguishes
    // "present and null" from "absent" (a plain null-check cannot).
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $response = $this->actingAs($admin)
        ->getJson("/api/v1/agencies/{$agency->ulid}/dashboard/summary");

    $response->assertStatus(200);
    $data = $response->json('data');

    foreach (['creators_in_roster', 'pending_creator_applications', 'active_campaigns', 'payments_due'] as $key) {
        expect(array_key_exists($key, $data))->toBeTrue("expected key '{$key}' to be present in the summary payload");
    }

    // Placeholders must be present-and-null, not absent.
    expect(array_key_exists('active_campaigns', $data))->toBeTrue();
    expect($data['active_campaigns'])->toBeNull();
    expect(array_key_exists('payments_due', $data))->toBeTrue();
    expect($data['payments_due'])->toBeNull();
});

// ---------------------------------------------------------------------------
// creators_in_roster
// ---------------------------------------------------------------------------

it('counts roster relations and excludes non-roster relationship statuses', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    makeRelatedCreator($agency, ['relationship_status' => RelationshipStatus::Roster]);
    makeRelatedCreator($agency, ['relationship_status' => RelationshipStatus::Roster]);
    makeRelatedCreator($agency, ['relationship_status' => RelationshipStatus::External]);
    makeRelatedCreator($agency, ['relationship_status' => RelationshipStatus::Prospect]);

    $response = $this->actingAs($admin)
        ->getJson("/api/v1/agencies/{$agency->ulid}/dashboard/summary");

    expect($response->json('data.creators_in_roster'))->toBe(2);
});

it('excludes blacklisted relations from the roster count', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    makeRelatedCreator($agency, ['relationship_status' => RelationshipStatus::Roster]);
    makeRelatedCreator($agency, [
        'relationship_status' => RelationshipStatus::Roster,
        'is_blacklisted' => true,
    ]);

    $response = $this->actingAs($admin)
        ->getJson("/api/v1/agencies/{$agency->ulid}/dashboard/summary");

    expect($response->json('data.creators_in_roster'))->toBe(1);
});

it('excludes soft-deleted creators from the roster count', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    makeRelatedCreator($agency, ['relationship_status' => RelationshipStatus::Roster]);
    $deleted = makeRelatedCreator($agency, ['relationship_status' => RelationshipStatus::Roster]);
    $deleted->delete();

    $response = $this->actingAs($admin)
        ->getJson("/api/v1/agencies/{$agency->ulid}/dashboard/summary");

    expect($response->json('data.creators_in_roster'))->toBe(1);
});

// ---------------------------------------------------------------------------
// pending_creator_applications (the D-c1-7 denominator)
// ---------------------------------------------------------------------------

it('counts creators with a relation to the agency whose application_status is pending, any relationship status', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    // Pending creators with a relation (any relationship_status) → counted.
    makeRelatedCreator(
        $agency,
        ['relationship_status' => RelationshipStatus::Roster],
        ['application_status' => ApplicationStatus::Pending],
    );
    makeRelatedCreator(
        $agency,
        ['relationship_status' => RelationshipStatus::External],
        ['application_status' => ApplicationStatus::Pending],
    );
    // Related but not pending → not counted.
    makeRelatedCreator(
        $agency,
        ['relationship_status' => RelationshipStatus::Roster],
        ['application_status' => ApplicationStatus::Approved],
    );

    $response = $this->actingAs($admin)
        ->getJson("/api/v1/agencies/{$agency->ulid}/dashboard/summary");

    expect($response->json('data.pending_creator_applications'))->toBe(2);
});

it('excludes self-signup pending creators with no relation to the agency', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    // Pending, but no agency_creator_relation → not this agency's application.
    Creator::factory()->create(['application_status' => ApplicationStatus::Pending]);

    $response = $this->actingAs($admin)
        ->getJson("/api/v1/agencies/{$agency->ulid}/dashboard/summary");

    expect($response->json('data.pending_creator_applications'))->toBe(0);
});

it('excludes blacklisted relations from the pending count', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    makeRelatedCreator(
        $agency,
        ['relationship_status' => RelationshipStatus::Roster, 'is_blacklisted' => true],
        ['application_status' => ApplicationStatus::Pending],
    );

    $response = $this->actingAs($admin)
        ->getJson("/api/v1/agencies/{$agency->ulid}/dashboard/summary");

    expect($response->json('data.pending_creator_applications'))->toBe(0);
});

it('excludes soft-deleted creators from the pending count', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $deleted = makeRelatedCreator(
        $agency,
        ['relationship_status' => RelationshipStatus::Roster],
        ['application_status' => ApplicationStatus::Pending],
    );
    $deleted->delete();

    $response = $this->actingAs($admin)
        ->getJson("/api/v1/agencies/{$agency->ulid}/dashboard/summary");

    expect($response->json('data.pending_creator_applications'))->toBe(0);
});

// ---------------------------------------------------------------------------
// Tenancy isolation
// ---------------------------------------------------------------------------

it('never counts another agency\'s roster or pending applications', function (): void {
    $agency = Agency::factory()->createOne();
    $other = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    // Agency B data — must be invisible to agency A's summary.
    makeRelatedCreator($other, ['relationship_status' => RelationshipStatus::Roster]);
    makeRelatedCreator(
        $other,
        ['relationship_status' => RelationshipStatus::Roster],
        ['application_status' => ApplicationStatus::Pending],
    );

    // Agency A data — one roster (also pending).
    makeRelatedCreator(
        $agency,
        ['relationship_status' => RelationshipStatus::Roster],
        ['application_status' => ApplicationStatus::Pending],
    );

    $response = $this->actingAs($admin)
        ->getJson("/api/v1/agencies/{$agency->ulid}/dashboard/summary");

    expect($response->json('data.creators_in_roster'))->toBe(1);
    expect($response->json('data.pending_creator_applications'))->toBe(1);
});
