<?php

declare(strict_types=1);

use App\Modules\Agencies\Mail\AdminConnectedMail;
use App\Modules\Agencies\Mail\ConnectionRequestMail;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Enums\RelationshipStatus;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| AH-051 (D-4/D-5/D-8/D-10) — admin-initiated connections (Doors 1 & 2).
|--------------------------------------------------------------------------
*/

function connAdmin(): User
{
    return User::factory()->create([
        'type' => UserType::PlatformAdmin,
        'two_factor_confirmed_at' => now(),
    ]);
}

function connCreator(bool $approved = true, string $lang = 'pt'): Creator
{
    $user = User::factory()->create(['type' => UserType::Creator, 'preferred_language' => $lang]);
    $factory = CreatorFactory::new();
    if ($approved) {
        $factory = $factory->approved();
    }

    return $factory->createOne(['user_id' => $user->id]);
}

function connUrl(Creator $creator): string
{
    return "/api/v1/admin/creators/{$creator->ulid}/connections";
}

function connRelation(Agency $agency, Creator $creator): ?AgencyCreatorRelation
{
    return AgencyCreatorRelation::query()->withoutGlobalScopes()
        ->where('agency_id', $agency->id)->where('creator_id', $creator->id)->first();
}

/** Non-null variant for the property-access sites (keeps PHPStan honest). */
function connRelationOrFail(Agency $agency, Creator $creator): AgencyCreatorRelation
{
    return AgencyCreatorRelation::query()->withoutGlobalScopes()
        ->where('agency_id', $agency->id)->where('creator_id', $creator->id)->firstOrFail();
}

// ---------------------------------------------------------------------------
// Auth
// ---------------------------------------------------------------------------

it('401s unauthenticated and 403s a non-admin', function (): void {
    $creator = connCreator();
    $agency = Agency::factory()->createOne();

    $this->postJson(connUrl($creator), ['agency_id' => $agency->ulid, 'mode' => 'request'])
        ->assertStatus(401);

    $agencyUser = User::factory()->create(['type' => UserType::AgencyUser, 'two_factor_confirmed_at' => now()]);
    $this->actingAs($agencyUser, 'web_admin')
        ->postJson(connUrl($creator), ['agency_id' => $agency->ulid, 'mode' => 'request'])
        ->assertStatus(403);
});

// ---------------------------------------------------------------------------
// Door 1 — admin send-request
// ---------------------------------------------------------------------------

it('Door 1 net-new: creates a pending_request, stamps admin provenance, fires ConnectionRequestMail + audit', function (): void {
    Mail::fake();
    $admin = connAdmin();
    $creator = connCreator();
    $agency = Agency::factory()->createOne();

    $response = $this->actingAs($admin, 'web_admin')
        ->postJson(connUrl($creator), ['agency_id' => $agency->ulid, 'mode' => 'request']);

    $response->assertCreated()
        ->assertJsonPath('data.attributes.relationship_status', 'pending_request')
        ->assertJsonPath('meta.code', 'connection.requested');

    $relation = connRelationOrFail($agency, $creator);
    expect($relation->relationship_status)->toBe(RelationshipStatus::PendingRequest)
        ->and($relation->invited_by_user_id)->toBe($admin->id); // D-8 provenance

    Mail::assertQueued(ConnectionRequestMail::class, 1);
    Mail::assertNotQueued(AdminConnectedMail::class);
    expect(AuditLog::query()->where('action', AuditAction::AgencyCreatorRelationAdminRequested->value)->count())->toBe(1);
});

it('Door 1 re-requests from declined AND ended (status flips to pending)', function (string $status): void {
    Mail::fake();
    $admin = connAdmin();
    $creator = connCreator();
    $agency = Agency::factory()->createOne();
    AgencyCreatorRelation::factory()->create([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => RelationshipStatus::from($status),
    ]);

    $this->actingAs($admin, 'web_admin')
        ->postJson(connUrl($creator), ['agency_id' => $agency->ulid, 'mode' => 'request'])
        ->assertOk()
        ->assertJsonPath('data.attributes.relationship_status', 'pending_request')
        ->assertJsonPath('meta.code', 'connection.re_requested');

    expect(connRelationOrFail($agency, $creator)->relationship_status)->toBe(RelationshipStatus::PendingRequest);
    Mail::assertQueued(ConnectionRequestMail::class, 1);
})->with(['declined', 'ended']);

it('Door 1 is an idempotent no-op on non-entry statuses (no dup, no mail)', function (string $status): void {
    Mail::fake();
    $admin = connAdmin();
    $creator = connCreator();
    $agency = Agency::factory()->createOne();
    AgencyCreatorRelation::factory()->create([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => RelationshipStatus::from($status),
    ]);

    $this->actingAs($admin, 'web_admin')
        ->postJson(connUrl($creator), ['agency_id' => $agency->ulid, 'mode' => 'request'])
        ->assertOk();

    expect(AgencyCreatorRelation::query()->withoutGlobalScopes()->where('creator_id', $creator->id)->count())->toBe(1);
    Mail::assertNothingQueued();
})->with(['pending_request', 'roster', 'prospect', 'external']);

it('Door 1 refuses a hard-blacklisted relation with a mode-distinct 422 (no mail)', function (): void {
    Mail::fake();
    $admin = connAdmin();
    $creator = connCreator();
    $agency = Agency::factory()->createOne();
    AgencyCreatorRelation::factory()->blacklisted('Hard ban')->create([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => RelationshipStatus::Declined,
    ]);

    $this->actingAs($admin, 'web_admin')
        ->postJson(connUrl($creator), ['agency_id' => $agency->ulid, 'mode' => 'request'])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'connection.request_blacklisted');

    Mail::assertNothingQueued();
});

// ---------------------------------------------------------------------------
// Door 2 — admin direct-connect
// ---------------------------------------------------------------------------

it('Door 2 net-new: directly connects to roster, dual-emits (in-app + mail in creator locale), audits with reason + provenance', function (): void {
    Mail::fake();
    $admin = connAdmin();
    $creator = connCreator(lang: 'it');
    $agency = Agency::factory()->createOne(['name' => 'Northwind Talent']);

    $response = $this->actingAs($admin, 'web_admin')
        ->postJson(connUrl($creator), [
            'agency_id' => $agency->ulid,
            'mode' => 'direct',
            'reason' => 'Signed an offline representation agreement on 2026-07-01.',
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.attributes.relationship_status', 'roster')
        ->assertJsonPath('meta.code', 'connection.direct_connected');

    $relation = connRelationOrFail($agency, $creator);
    expect($relation->relationship_status)->toBe(RelationshipStatus::Roster)
        ->and($relation->invited_by_user_id)->toBe($admin->id);

    // Dual-emit — mail (creator locale) + in-app.
    $creatorUser = $creator->user;
    Mail::assertQueued(AdminConnectedMail::class, function (AdminConnectedMail $mail) use ($creatorUser): bool {
        return $creatorUser !== null
            && $mail->hasTo($creatorUser->email)
            && $mail->locale === 'it'
            && $mail->agencyName === 'Northwind Talent';
    });
    expect(Notification::query()
        ->where('recipient_user_id', $creator->user_id)
        ->where('type', NotificationType::RelationAdminConnected->value)
        ->count())->toBe(1);

    // Audit with the reason (reason-required verb).
    $audit = AuditLog::query()->where('action', AuditAction::AgencyCreatorRelationAdminConnected->value)->firstOrFail();
    expect($audit->actor_id)->toBe($admin->id)
        ->and($audit->reason)->toBe('Signed an offline representation agreement on 2026-07-01.');
});

it('Door 2 elevates any non-roster status to roster (supersedes a pending ask)', function (string $status): void {
    Mail::fake();
    $admin = connAdmin();
    $creator = connCreator();
    $agency = Agency::factory()->createOne();
    AgencyCreatorRelation::factory()->create([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => RelationshipStatus::from($status),
    ]);

    $this->actingAs($admin, 'web_admin')
        ->postJson(connUrl($creator), [
            'agency_id' => $agency->ulid,
            'mode' => 'direct',
            'reason' => 'Offline agreement supersedes the pending state.',
        ])
        ->assertOk()
        ->assertJsonPath('data.attributes.relationship_status', 'roster');

    expect(connRelationOrFail($agency, $creator)->relationship_status)->toBe(RelationshipStatus::Roster);
})->with(['pending_request', 'declined', 'ended', 'prospect', 'external']);

it('Door 2 is an idempotent no-op when already rostered (no second notification/mail)', function (): void {
    Mail::fake();
    $admin = connAdmin();
    $creator = connCreator();
    $agency = Agency::factory()->createOne();
    AgencyCreatorRelation::factory()->create([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => RelationshipStatus::Roster,
    ]);

    $this->actingAs($admin, 'web_admin')
        ->postJson(connUrl($creator), [
            'agency_id' => $agency->ulid,
            'mode' => 'direct',
            'reason' => 'Attempting to re-connect an already-connected pair.',
        ])
        ->assertOk()
        ->assertJsonPath('meta.code', 'connection.already_connected');

    Mail::assertNothingQueued();
    expect(Notification::query()->where('recipient_user_id', $creator->user_id)->count())->toBe(0);
});

it('Door 2 refuses a hard-blacklisted relation with a mode-distinct 422', function (): void {
    Mail::fake();
    $admin = connAdmin();
    $creator = connCreator();
    $agency = Agency::factory()->createOne();
    AgencyCreatorRelation::factory()->blacklisted('Hard ban')->create([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => RelationshipStatus::PendingRequest,
    ]);

    $this->actingAs($admin, 'web_admin')
        ->postJson(connUrl($creator), [
            'agency_id' => $agency->ulid,
            'mode' => 'direct',
            'reason' => 'Trying to direct-connect a hard-blacklisted pair.',
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'connection.direct_blacklisted');
});

it('Door 2 requires a reason (min:10)', function (): void {
    $admin = connAdmin();
    $creator = connCreator();
    $agency = Agency::factory()->createOne();

    $this->actingAs($admin, 'web_admin')
        ->postJson(connUrl($creator), ['agency_id' => $agency->ulid, 'mode' => 'direct'])
        ->assertStatus(422);

    expect(connRelation($agency, $creator))->toBeNull();
});

// ---------------------------------------------------------------------------
// Shared guards
// ---------------------------------------------------------------------------

it('both doors refuse a non-approved creator (422 creator_not_approved)', function (string $mode): void {
    $admin = connAdmin();
    $creator = connCreator(approved: false);
    $agency = Agency::factory()->createOne();

    $this->actingAs($admin, 'web_admin')
        ->postJson(connUrl($creator), [
            'agency_id' => $agency->ulid,
            'mode' => $mode,
            'reason' => 'A sufficiently long offline-agreement reason.',
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'connection.creator_not_approved');

    expect(connRelation($agency, $creator))->toBeNull();
})->with(['request', 'direct']);

it('bypasses is_discoverable — an approved but non-discoverable creator can be connected (admin is not cold outreach)', function (): void {
    Mail::fake();
    $admin = connAdmin();
    $user = User::factory()->create(['type' => UserType::Creator]);
    $creator = CreatorFactory::new()->approved()->createOne([
        'user_id' => $user->id,
        'is_discoverable' => false,
    ]);
    $agency = Agency::factory()->createOne();

    $this->actingAs($admin, 'web_admin')
        ->postJson(connUrl($creator), ['agency_id' => $agency->ulid, 'mode' => 'request'])
        ->assertCreated();

    expect(connRelationOrFail($agency, $creator)->relationship_status)->toBe(RelationshipStatus::PendingRequest);
});

it('404s an unknown agency identifier', function (): void {
    $admin = connAdmin();
    $creator = connCreator();

    $this->actingAs($admin, 'web_admin')
        ->postJson(connUrl($creator), ['agency_id' => 'nonexistent', 'mode' => 'request'])
        ->assertStatus(404)
        ->assertJsonPath('errors.0.code', 'connection.agency_not_found');
});

// ---------------------------------------------------------------------------
// Index (D-9 read)
// ---------------------------------------------------------------------------

it('lists the creator relations across agencies with agency, status, and since', function (): void {
    $admin = connAdmin();
    $creator = connCreator();
    $agencyA = Agency::factory()->createOne(['name' => 'Alpha']);
    $agencyB = Agency::factory()->createOne(['name' => 'Bravo']);
    AgencyCreatorRelation::factory()->create(['agency_id' => $agencyA->id, 'creator_id' => $creator->id, 'relationship_status' => RelationshipStatus::Roster]);
    AgencyCreatorRelation::factory()->create(['agency_id' => $agencyB->id, 'creator_id' => $creator->id, 'relationship_status' => RelationshipStatus::Ended]);

    $response = $this->actingAs($admin, 'web_admin')->getJson(connUrl($creator));

    $response->assertOk();
    $names = collect((array) $response->json('data'))->pluck('attributes.agency_name')->all();
    expect($names)->toEqualCanonicalizing(['Alpha', 'Bravo']);
    $statuses = collect((array) $response->json('data'))->pluck('attributes.relationship_status')->all();
    expect($statuses)->toEqualCanonicalizing(['roster', 'ended']);
});
