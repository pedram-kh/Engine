<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Enums\RelationshipStatus;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Sprint 6.6b — the CREATOR half of the two-sided connection lifecycle (D-8).
 *
 *   GET    /api/v1/creators/me/connection-requests
 *   POST   /api/v1/creators/me/connection-requests/{relation}/accept
 *   POST   /api/v1/creators/me/connection-requests/{relation}/decline
 *
 * Pins: creator-self scoping (a creator can't accept another's request —
 * resolved from $request->user()->creator), the fail-closed pending_request
 * guard (D-2), the accept → roster / decline → declined transitions, the
 * cross-agency list (all pending requests regardless of any ambient context),
 * and the GET list shape 6.6c consumes.
 */

/**
 * @return array{0: User, 1: Creator}
 */
function creatorActor(array $attributes = []): array
{
    $user = User::factory()->create($attributes);
    $creator = CreatorFactory::new()->createOne(['user_id' => $user->id]);

    return [$user, $creator];
}

function pendingRequestFor(Agency $agency, Creator $creator): AgencyCreatorRelation
{
    return AgencyCreatorRelation::factory()->pendingRequest()->create([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
    ]);
}

// ---------------------------------------------------------------------------
// List — the creator's own pending requests (cross-agency)
// ---------------------------------------------------------------------------

it('returns 401 when unauthenticated', function (): void {
    expect($this->getJson('/api/v1/creators/me/connection-requests')->status())->toBe(401);
});

it('lists the creator\'s OWN pending requests across all agencies', function (): void {
    [$user, $creator] = creatorActor();

    $agencyA = Agency::factory()->createOne(['name' => 'Alpha']);
    $agencyB = Agency::factory()->createOne(['name' => 'Bravo']);
    pendingRequestFor($agencyA, $creator);
    pendingRequestFor($agencyB, $creator);

    // A non-pending relation (a third agency, on roster) + another creator's
    // request must NOT appear — only the creator's OWN pending requests do.
    $agencyC = Agency::factory()->createOne(['name' => 'Charlie']);
    AgencyCreatorRelation::factory()->create([
        'agency_id' => $agencyC->id,
        'creator_id' => $creator->id,
        'relationship_status' => RelationshipStatus::Roster,
    ]);
    $other = CreatorFactory::new()->createOne();
    pendingRequestFor($agencyB, $other);

    $response = $this->actingAs($user)->getJson('/api/v1/creators/me/connection-requests');

    $response->assertOk();
    $names = collect((array) $response->json('data'))->pluck('attributes.agency_name')->all();
    expect($names)->toHaveCount(2)
        ->and($names)->toEqualCanonicalizing(['Alpha', 'Bravo']);
});

// ---------------------------------------------------------------------------
// Accept — pending_request → roster
// ---------------------------------------------------------------------------

it('accepts a pending request (pending_request → roster)', function (): void {
    [$user, $creator] = creatorActor();
    $agency = Agency::factory()->createOne();
    $relation = pendingRequestFor($agency, $creator);

    $response = $this->actingAs($user)
        ->postJson("/api/v1/creators/me/connection-requests/{$relation->ulid}/accept");

    $response->assertOk()
        ->assertJsonPath('data.attributes.relationship_status', 'roster')
        ->assertJsonPath('meta.code', 'connection.accepted');

    expect($relation->refresh()->relationship_status)->toBe(RelationshipStatus::Roster);
});

it('declines a pending request (pending_request → declined; row retained)', function (): void {
    [$user, $creator] = creatorActor();
    $agency = Agency::factory()->createOne();
    $relation = pendingRequestFor($agency, $creator);

    $response = $this->actingAs($user)
        ->postJson("/api/v1/creators/me/connection-requests/{$relation->ulid}/decline");

    $response->assertOk()
        ->assertJsonPath('data.attributes.relationship_status', 'declined')
        ->assertJsonPath('meta.code', 'connection.declined');

    expect($relation->refresh()->relationship_status)->toBe(RelationshipStatus::Declined);
});

// ---------------------------------------------------------------------------
// Fail-closed guard (D-2) — only a pending_request may transition
// ---------------------------------------------------------------------------

it('rejects accepting a non-pending relation (break-revert the fail-closed guard)', function (string $status): void {
    [$user, $creator] = creatorActor();
    $agency = Agency::factory()->createOne();
    $relation = AgencyCreatorRelation::factory()->create([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => RelationshipStatus::from($status),
    ]);

    $response = $this->actingAs($user)
        ->postJson("/api/v1/creators/me/connection-requests/{$relation->ulid}/accept");

    $response->assertStatus(422)->assertJsonPath('errors.0.code', 'connection.not_pending');
    expect($relation->refresh()->relationship_status->value)->toBe($status);
})->with(['roster', 'declined', 'prospect', 'external']);

// ---------------------------------------------------------------------------
// Creator-self scoping — a creator can NOT act on another creator's request
// ---------------------------------------------------------------------------

it('404s when accepting another creator\'s request (creator-self-scoped — break-revert)', function (): void {
    [$user, $creator] = creatorActor();
    $agency = Agency::factory()->createOne();

    // The pending request belongs to a DIFFERENT creator.
    $other = CreatorFactory::new()->createOne();
    $foreignRelation = pendingRequestFor($agency, $other);

    $response = $this->actingAs($user)
        ->postJson("/api/v1/creators/me/connection-requests/{$foreignRelation->ulid}/accept");

    $response->assertNotFound()->assertJsonPath('errors.0.code', 'connection.not_found');
    // The foreign request is untouched.
    expect($foreignRelation->refresh()->relationship_status)->toBe(RelationshipStatus::PendingRequest);
});
