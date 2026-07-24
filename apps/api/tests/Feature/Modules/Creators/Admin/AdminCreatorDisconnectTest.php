<?php

declare(strict_types=1);

use App\Modules\Agencies\Mail\RelationDisconnectedMail;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Enums\RelationshipStatus;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Policies\CreatorPolicy;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Models\Notification;
use App\Modules\TalentPools\Models\TalentPool;
use App\Modules\TalentPools\Models\TalentPoolMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| AH-051 (D-6) — admin disconnect (the platform's first termination path).
|--------------------------------------------------------------------------
*/

function discAdmin(): User
{
    return User::factory()->create([
        'type' => UserType::PlatformAdmin,
        'two_factor_confirmed_at' => now(),
    ]);
}

function discCreator(): Creator
{
    $user = User::factory()->create(['type' => UserType::Creator, 'preferred_language' => 'pt']);

    return CreatorFactory::new()->approved()->createOne(['user_id' => $user->id]);
}

function discUrl(Creator $creator, Agency $agency): string
{
    return "/api/v1/admin/creators/{$creator->ulid}/connections/{$agency->ulid}/disconnect";
}

function rosterRelation(Agency $agency, Creator $creator): AgencyCreatorRelation
{
    return AgencyCreatorRelation::factory()->create([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => RelationshipStatus::Roster,
    ]);
}

function reloadRelation(Agency $agency, Creator $creator): ?AgencyCreatorRelation
{
    return AgencyCreatorRelation::query()->withoutGlobalScopes()
        ->where('agency_id', $agency->id)->where('creator_id', $creator->id)->first();
}

/** Non-null variant for the property-access sites (keeps PHPStan honest). */
function reloadRelationOrFail(Agency $agency, Creator $creator): AgencyCreatorRelation
{
    return AgencyCreatorRelation::query()->withoutGlobalScopes()
        ->where('agency_id', $agency->id)->where('creator_id', $creator->id)->firstOrFail();
}

// ---------------------------------------------------------------------------
// Happy path — roster → ended, transactional teardown, dual-emit
// ---------------------------------------------------------------------------

it('disconnects a rostered relation (roster → ended), empties the pair pools, audits with reason', function (): void {
    Mail::fake();
    $admin = discAdmin();
    $creator = discCreator();
    $agency = Agency::factory()->createOne();
    rosterRelation($agency, $creator);

    // Pool membership for this pair, in this agency's pool.
    $pool = TalentPool::factory()->forAgency($agency->id)->createOne();
    TalentPoolMembership::factory()->createOne(['talent_pool_id' => $pool->id, 'creator_id' => $creator->id]);

    $response = $this->actingAs($admin, 'web_admin')
        ->postJson(discUrl($creator, $agency), ['reason' => 'Creator requested to leave the agency roster.']);

    $response->assertOk()
        ->assertJsonPath('data.attributes.relationship_status', 'ended')
        ->assertJsonPath('meta.code', 'connection.disconnected');

    expect(reloadRelationOrFail($agency, $creator)->relationship_status)->toBe(RelationshipStatus::Ended);

    // Pools emptied for the pair.
    expect(TalentPoolMembership::query()->where('creator_id', $creator->id)->count())->toBe(0);

    // Audit with reason.
    $audit = AuditLog::query()->where('action', AuditAction::AgencyCreatorRelationDisconnected->value)->firstOrFail();
    expect($audit->actor_id)->toBe($admin->id)
        ->and($audit->reason)->toBe('Creator requested to leave the agency roster.');
});

it('notifies BOTH parties — the creator + every active agency member (in-app + mail)', function (): void {
    Mail::fake();
    $admin = discAdmin();
    $creator = discCreator();
    $agency = Agency::factory()->createOne();
    $member = User::factory()->agencyAdmin($agency)->createOne();
    rosterRelation($agency, $creator);

    $this->actingAs($admin, 'web_admin')
        ->postJson(discUrl($creator, $agency), ['reason' => 'Offline agreement ended between the parties.'])
        ->assertOk();

    // In-app to both the creator user and the agency member.
    expect(Notification::query()
        ->where('type', NotificationType::RelationDisconnected->value)
        ->where('recipient_user_id', $creator->user_id)->count())->toBe(1)
        ->and(Notification::query()
            ->where('type', NotificationType::RelationDisconnected->value)
            ->where('recipient_user_id', $member->id)->count())->toBe(1);

    // Mail to both.
    $creatorUser = $creator->user;
    Mail::assertQueued(RelationDisconnectedMail::class, 2);
    Mail::assertQueued(RelationDisconnectedMail::class, fn (RelationDisconnectedMail $m): bool => $creatorUser !== null && $m->hasTo($creatorUser->email));
    Mail::assertQueued(RelationDisconnectedMail::class, fn (RelationDisconnectedMail $m): bool => $m->hasTo($member->email));
});

// ---------------------------------------------------------------------------
// §5.34 negatives / invariants
// ---------------------------------------------------------------------------

it('422s disconnecting a non-roster relation — nothing to disconnect', function (string $status): void {
    $admin = discAdmin();
    $creator = discCreator();
    $agency = Agency::factory()->createOne();
    AgencyCreatorRelation::factory()->create([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => RelationshipStatus::from($status),
    ]);

    $this->actingAs($admin, 'web_admin')
        ->postJson(discUrl($creator, $agency), ['reason' => 'Trying to disconnect a non-roster relation.'])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'connection.not_disconnectable');

    expect(reloadRelationOrFail($agency, $creator)->relationship_status->value)->toBe($status);
})->with(['pending_request', 'declined', 'ended', 'prospect', 'external']);

it('422s a SECOND disconnect (the first left it ended, not roster)', function (): void {
    Mail::fake();
    $admin = discAdmin();
    $creator = discCreator();
    $agency = Agency::factory()->createOne();
    rosterRelation($agency, $creator);

    $this->actingAs($admin, 'web_admin')
        ->postJson(discUrl($creator, $agency), ['reason' => 'First disconnect — the legitimate one.'])
        ->assertOk();

    $this->actingAs($admin, 'web_admin')
        ->postJson(discUrl($creator, $agency), ['reason' => 'Second disconnect should be rejected.'])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'connection.not_disconnectable');
});

it('leaves in-flight campaign assignments UNTOUCHED (commercial work survives)', function (): void {
    Mail::fake();
    $admin = discAdmin();
    $creator = discCreator();
    $agency = Agency::factory()->createOne();
    rosterRelation($agency, $creator);

    $assignment = CampaignAssignment::factory()->createOne([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
    ]);

    $this->actingAs($admin, 'web_admin')
        ->postJson(discUrl($creator, $agency), ['reason' => 'Disconnect while a campaign is in flight.'])
        ->assertOk();

    expect(CampaignAssignment::query()->withoutGlobalScopes()->whereKey($assignment->id)->exists())->toBeTrue();
});

it('closes the messaging gate — canMessageRelationship is false post-disconnect', function (): void {
    Mail::fake();
    $admin = discAdmin();
    $creator = discCreator();
    $agency = Agency::factory()->createOne();
    $member = User::factory()->agencyAdmin($agency)->createOne();
    rosterRelation($agency, $creator);

    $policy = new CreatorPolicy;
    expect($policy->canMessageRelationship($member, $creator, $agency))->toBeTrue();

    $this->actingAs($admin, 'web_admin')
        ->postJson(discUrl($creator, $agency), ['reason' => 'Disconnecting to prove the messaging gate closes.'])
        ->assertOk();

    expect($policy->canMessageRelationship($member->refresh(), $creator->refresh(), $agency))->toBeFalse();
});

it('over-reach break-revert seam: only THIS agency\'s pool memberships are deleted', function (): void {
    Mail::fake();
    $admin = discAdmin();
    $creator = discCreator();

    $agencyA = Agency::factory()->createOne();
    $agencyB = Agency::factory()->createOne();
    rosterRelation($agencyA, $creator);
    rosterRelation($agencyB, $creator);

    $poolA = TalentPool::factory()->forAgency($agencyA->id)->createOne();
    $poolB = TalentPool::factory()->forAgency($agencyB->id)->createOne();
    TalentPoolMembership::factory()->createOne(['talent_pool_id' => $poolA->id, 'creator_id' => $creator->id]);
    $membershipB = TalentPoolMembership::factory()->createOne(['talent_pool_id' => $poolB->id, 'creator_id' => $creator->id]);

    // Disconnect ONLY from agency A.
    $this->actingAs($admin, 'web_admin')
        ->postJson(discUrl($creator, $agencyA), ['reason' => 'Disconnecting from agency A only.'])
        ->assertOk();

    // Agency A's membership gone; agency B's survives (the over-reach guard).
    expect(TalentPoolMembership::query()->where('talent_pool_id', $poolA->id)->count())->toBe(0)
        ->and(TalentPoolMembership::query()->whereKey($membershipB->id)->exists())->toBeTrue();
    // Agency B's relation is untouched (still roster).
    expect(reloadRelationOrFail($agencyB, $creator)->relationship_status)->toBe(RelationshipStatus::Roster);
});

it('403s a non-admin and requires a reason', function (): void {
    $creator = discCreator();
    $agency = Agency::factory()->createOne();
    rosterRelation($agency, $creator);

    // Non-admin.
    $agencyUser = User::factory()->create(['type' => UserType::AgencyUser, 'two_factor_confirmed_at' => now()]);
    $this->actingAs($agencyUser, 'web_admin')
        ->postJson(discUrl($creator, $agency), ['reason' => 'A sufficiently long reason string.'])
        ->assertStatus(403);

    // Missing reason.
    $admin = discAdmin();
    $this->actingAs($admin, 'web_admin')
        ->postJson(discUrl($creator, $agency), [])
        ->assertStatus(422);
});
