<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Enums\RelationshipStatus;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Models\CreatorAvailabilityBlock;
use App\Modules\Creators\Services\Availability\AvailabilityExpansionService;
use App\Modules\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Sprint 5 Chunk A — GET /agencies/{agency}/creators/{creator}/availability.
 *
 * Agency read-view of a roster creator's expanded availability (D-a6).
 * `reason` is creator-only and must NEVER appear (§5.35 break-revert: add
 * `reason` to AgencyAvailabilityResource and the no-reason test fails).
 * Scope mirrors the Chunk-5 roster exactly: a relation (any status) must
 * exist between the agency and the creator, else 404 (plan-pause Q2 anchor:
 * drop the relation check and a no-relation read leaks).
 */
function availabilityUrl(Agency $agency, Creator $creator, string $query = ''): string
{
    $base = "/api/v1/agencies/{$agency->ulid}/creators/{$creator->ulid}/availability";

    return $query === '' ? $base : "{$base}?{$query}";
}

/**
 * Roster a fresh creator under the agency and return it.
 */
function rosterCreator(Agency $agency, RelationshipStatus $status = RelationshipStatus::Roster): Creator
{
    $creator = CreatorFactory::new()->createOne();
    AgencyCreatorRelation::factory()->create([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => $status,
    ]);

    return $creator;
}

// ---------------------------------------------------------------------------
// Auth + scope boundary
// ---------------------------------------------------------------------------

it('returns 401 when unauthenticated', function (): void {
    $agency = Agency::factory()->createOne();
    $creator = rosterCreator($agency);

    expect($this->getJson(availabilityUrl($agency, $creator))->status())->toBe(401);
});

it('returns 404 for a non-member (tenancy invisibility)', function (): void {
    $agency = Agency::factory()->createOne();
    $creator = rosterCreator($agency);
    $outsider = User::factory()->agencyAdmin(Agency::factory()->createOne())->createOne();

    expect($this->actingAs($outsider)->getJson(availabilityUrl($agency, $creator))->status())->toBe(404);
});

it('returns 404 when the creator has NO relation with the agency (no-relation boundary)', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    // A creator that exists but is NOT in this agency's roster.
    $stranger = CreatorFactory::new()->createOne();

    expect($this->actingAs($admin)->getJson(availabilityUrl($agency, $stranger))->status())->toBe(404);
});

it('reads availability across any relationship status (mirrors roster scope)', function (string $status): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $creator = rosterCreator($agency, RelationshipStatus::from($status));

    expect($this->actingAs($admin)->getJson(availabilityUrl($agency, $creator))->status())->toBe(200);
})->with(['roster', 'prospect', 'external']);

// ---------------------------------------------------------------------------
// Shape — expanded occurrences, reason omitted (break-revert)
// ---------------------------------------------------------------------------

it('returns expanded availability but OMITS reason (break-revert anchor)', function (): void {
    $agency = Agency::factory()->createOne();
    $staff = User::factory()->agencyStaff($agency)->createOne();
    $creator = rosterCreator($agency);

    CreatorAvailabilityBlock::factory()->for($creator)->hard()->create([
        'starts_at' => '2026-06-11T09:00:00+00:00',
        'ends_at' => '2026-06-11T17:00:00+00:00',
        'is_recurring' => false,
        'reason' => 'CONFIDENTIAL CREATOR REASON',
    ]);

    $response = $this->actingAs($staff)->getJson(availabilityUrl(
        $agency,
        $creator,
        'from=2026-06-01T00:00:00%2B00:00&to=2026-06-30T00:00:00%2B00:00',
    ));

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);

    $attributes = $response->json('data.0.attributes');
    expect($attributes)->toHaveKeys(['starts_at', 'ends_at', 'block_type', 'kind', 'is_recurring'])
        ->and($attributes)->not->toHaveKey('reason');

    // The creator-only reason must not leak anywhere in the payload.
    expect($response->getContent())->not->toContain('reason')
        ->and($response->getContent())->not->toContain('CONFIDENTIAL CREATOR REASON');
});

// ---------------------------------------------------------------------------
// Single expansion source (D-a4): agency view agrees with expansion
// ---------------------------------------------------------------------------

it('the agency view reads the SAME expansion output (single source)', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $creator = rosterCreator($agency);

    CreatorAvailabilityBlock::factory()->for($creator)->hard()->create([
        'starts_at' => '2026-06-11T09:00:00+00:00',
        'ends_at' => '2026-06-11T17:00:00+00:00',
        'is_recurring' => false,
    ]);
    CreatorAvailabilityBlock::factory()->for($creator)->soft()->weeklyRecurring('FREQ=WEEKLY;BYDAY=MO')->create([
        'starts_at' => '2026-06-01T09:00:00+00:00',
        'ends_at' => '2026-06-01T17:00:00+00:00',
    ]);

    $from = CarbonImmutable::parse('2026-06-01T00:00:00+00:00');
    $to = CarbonImmutable::parse('2026-06-30T00:00:00+00:00');

    $expanded = app(AvailabilityExpansionService::class)->expand($creator, $from, $to);
    $expectedStarts = array_map(fn ($o) => $o->startsAt->toIso8601String(), $expanded);

    $response = $this->actingAs($admin)->getJson(availabilityUrl(
        $agency,
        $creator,
        'from=2026-06-01T00:00:00%2B00:00&to=2026-06-30T00:00:00%2B00:00',
    ));

    $response->assertOk();
    expect($response->json('data.*.attributes.starts_at'))->toBe($expectedStarts);
});
