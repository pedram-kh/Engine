<?php

declare(strict_types=1);

use App\Modules\Agencies\Mail\ConnectionRequestMail;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Creators\Enums\RelationshipStatus;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Sprint 6.6b — the AGENCY half of the two-sided connection lifecycle (D-7).
 *
 *   POST /agencies/{agency}/creators/discover/{creator}/connection-request
 *
 * Pins the W1 send path + the fail-closed state machine (D-1/D-2), the
 * declined → pending_request re-request (D-4, NOT a silent no-op), the
 * idempotent no-ops for pending_request/roster (the already_invited
 * precedent), the admin/manager authz floor (staff 403), and the queued
 * ConnectionRequestMail in the creator's locale (D-9).
 */
function requestUrl(Agency $agency, Creator $creator): string
{
    return "/api/v1/agencies/{$agency->ulid}/creators/discover/{$creator->ulid}/connection-request";
}

function discoverableTarget(array $attributes = []): Creator
{
    return Creator::factory()->approved()->createOne($attributes);
}

function relationFor(Agency $agency, Creator $creator): ?AgencyCreatorRelation
{
    return AgencyCreatorRelation::withoutGlobalScopes()
        ->where('agency_id', $agency->id)
        ->where('creator_id', $creator->id)
        ->first();
}

// ---------------------------------------------------------------------------
// Auth + tenancy + authz (D-7)
// ---------------------------------------------------------------------------

it('returns 401 when unauthenticated', function (): void {
    $agency = Agency::factory()->createOne();
    $creator = discoverableTarget();

    expect($this->postJson(requestUrl($agency, $creator))->status())->toBe(401);
});

it('returns 404 for a non-member (tenancy invisibility)', function (): void {
    $agency = Agency::factory()->createOne();
    $creator = discoverableTarget();
    $outsider = User::factory()->agencyAdmin(Agency::factory()->createOne())->createOne();

    expect($this->actingAs($outsider)->postJson(requestUrl($agency, $creator))->status())->toBe(404);
});

it('lets an admin send a request (admin/manager floor)', function (): void {
    Mail::fake();
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $creator = discoverableTarget();

    $response = $this->actingAs($admin)->postJson(requestUrl($agency, $creator));

    $response->assertCreated()
        ->assertJsonPath('data.attributes.relationship_status', 'pending_request')
        ->assertJsonPath('meta.code', 'connection.requested');
});

it('lets a manager send a request', function (): void {
    Mail::fake();
    $agency = Agency::factory()->createOne();
    $manager = User::factory()->agencyManager($agency)->createOne();
    $creator = discoverableTarget();

    $this->actingAs($manager)->postJson(requestUrl($agency, $creator))->assertCreated();
});

it('forbids staff from sending a request (403 — break-revert the sendRequest gate)', function (): void {
    Mail::fake();
    $agency = Agency::factory()->createOne();
    $staff = User::factory()->agencyStaff($agency)->createOne();
    $creator = discoverableTarget();

    $this->actingAs($staff)->postJson(requestUrl($agency, $creator))->assertForbidden();

    expect(relationFor($agency, $creator))->toBeNull();
    Mail::assertNothingQueued();
});

// ---------------------------------------------------------------------------
// Fail-closed discoverable gate
// ---------------------------------------------------------------------------

it('404s when the target is not discoverable / not approved (fail-closed, not probeable)', function (): void {
    Mail::fake();
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $optedOut = Creator::factory()->approved()->notDiscoverable()->createOne();
    $pending = Creator::factory()->submitted()->createOne();

    expect($this->actingAs($admin)->postJson(requestUrl($agency, $optedOut))->status())->toBe(404);
    expect($this->actingAs($admin)->postJson(requestUrl($agency, $pending))->status())->toBe(404);
    Mail::assertNothingQueued();
});

// ---------------------------------------------------------------------------
// W1 — net-new send (none → pending_request)
// ---------------------------------------------------------------------------

it('creates a pending_request with NO magic-link token/expiry and stamps the inviter', function (): void {
    Mail::fake();
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $creator = discoverableTarget();

    $this->actingAs($admin)->postJson(requestUrl($agency, $creator))->assertCreated();

    $relation = relationFor($agency, $creator);
    expect($relation)->not->toBeNull();
    /** @var AgencyCreatorRelation $relation */
    expect($relation->relationship_status)->toBe(RelationshipStatus::PendingRequest)
        ->and($relation->invitation_token_hash)->toBeNull()
        ->and($relation->invitation_expires_at)->toBeNull()
        ->and($relation->invited_by_user_id)->toBe($admin->id)
        ->and($relation->invitation_sent_at)->not->toBeNull()
        ->and($relation->notification_sent_at)->not->toBeNull();
});

// ---------------------------------------------------------------------------
// D-4 — declined → pending_request re-request (explicit, NOT a silent no-op)
// ---------------------------------------------------------------------------

it('re-requests a previously declined creator (declined → pending_request — break-revert: the status MUST flip)', function (): void {
    Mail::fake();
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $creator = discoverableTarget();
    AgencyCreatorRelation::factory()->declined()->create([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
    ]);

    $response = $this->actingAs($admin)->postJson(requestUrl($agency, $creator));

    $response->assertOk()
        ->assertJsonPath('data.attributes.relationship_status', 'pending_request')
        ->assertJsonPath('meta.code', 'connection.re_requested');

    // The status actually flipped — not swallowed as a no-op.
    $relation = relationFor($agency, $creator);
    expect($relation)->not->toBeNull();
    /** @var AgencyCreatorRelation $relation */
    expect($relation->relationship_status)->toBe(RelationshipStatus::PendingRequest);
    Mail::assertQueued(ConnectionRequestMail::class, 1);
});

// ---------------------------------------------------------------------------
// Idempotency — pending_request / roster no-op surfacing the existing state
// ---------------------------------------------------------------------------

it('is an idempotent no-op when already pending (no duplicate, no second mail)', function (): void {
    Mail::fake();
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $creator = discoverableTarget();
    AgencyCreatorRelation::factory()->pendingRequest()->create([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
    ]);

    $response = $this->actingAs($admin)->postJson(requestUrl($agency, $creator));

    $response->assertOk()
        ->assertJsonPath('data.attributes.relationship_status', 'pending_request')
        ->assertJsonPath('meta.code', 'connection.already_requested');

    expect(AgencyCreatorRelation::withoutGlobalScopes()->where('creator_id', $creator->id)->count())->toBe(1);
    Mail::assertNothingQueued();
});

it('is an idempotent no-op when already on roster (surfaces "already connected")', function (): void {
    Mail::fake();
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $creator = discoverableTarget();
    AgencyCreatorRelation::factory()->create([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => RelationshipStatus::Roster,
    ]);

    $response = $this->actingAs($admin)->postJson(requestUrl($agency, $creator));

    $response->assertOk()
        ->assertJsonPath('data.attributes.relationship_status', 'roster')
        ->assertJsonPath('meta.code', 'connection.already_connected');
    Mail::assertNothingQueued();
});

// ---------------------------------------------------------------------------
// Email (D-9) — queued ConnectionRequestMail in the creator's locale
// ---------------------------------------------------------------------------

it('queues a ConnectionRequestMail to the creator in their locale', function (): void {
    Mail::fake();
    $agency = Agency::factory()->createOne(['name' => 'Acme Talent']);
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $creatorUser = User::factory()->create(['preferred_language' => 'pt']);
    $creator = discoverableTarget(['user_id' => $creatorUser->id]);

    $this->actingAs($admin)->postJson(requestUrl($agency, $creator))->assertCreated();

    Mail::assertQueued(ConnectionRequestMail::class, function (ConnectionRequestMail $mail) use ($creatorUser): bool {
        return $mail->hasTo($creatorUser->email)
            && $mail->locale === 'pt'
            && $mail->agencyName === 'Acme Talent';
    });
});
