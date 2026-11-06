<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Agencies\Models\AgencyMembership;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Services\BulkInviteService;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

require_once dirname(__DIR__, 3).'/Support/MessageInboxFixtures.php';

/**
 * AH-119 A3 — the relationship owner: the defaults at the creation sites, the reassign endpoint, and
 * the Mine filter. Effective owner = coalesce(owner_user_id, invited_by_user_id); no backfill.
 */
function ownerUrl($agency, $creator): string
{
    return "/api/v1/agencies/{$agency->ulid}/creators/{$creator->ulid}/owner";
}

// ── defaults ────────────────────────────────────────────────────────────────

it('makes the inviter the owner of a bulk-invited relationship', function (): void {
    Mail::fake();
    ['agency' => $agency, 'manager' => $manager] = inboxWorld();

    app(BulkInviteService::class)->inviteOne($agency, $manager, 'new.creator@example.com');

    $relation = AgencyCreatorRelation::query()->where('agency_id', $agency->id)->sole();
    expect($relation->owner_user_id)->toBe($manager->id)->and($relation->invited_by_user_id)->toBe($manager->id);
});

it('makes the requesting member the owner of a net-new connection request, and on a re-request', function (): void {
    Mail::fake();
    ['agency' => $agency, 'admin' => $admin, 'manager' => $manager] = inboxWorld();
    $creator = CreatorFactory::new()->approved()->createOne(['user_id' => User::factory()->createOne()->id]);
    $url = "/api/v1/agencies/{$agency->ulid}/creators/discover/{$creator->ulid}/connection-request";

    $this->actingAs($admin)->postJson($url)->assertSuccessful();
    $relation = AgencyCreatorRelation::query()->where('creator_id', $creator->id)->sole();
    expect($relation->owner_user_id)->toBe($admin->id);

    $relation->forceFill(['relationship_status' => 'declined'])->save();
    $this->actingAs($manager)->postJson($url)->assertSuccessful();
    expect($relation->fresh()?->owner_user_id)->toBe($manager->id);
});

it('leaves the owner unset where nobody on the agency chose (the effective owner stays the inviter, or none)', function (): void {
    ['agency' => $agency, 'admin' => $admin] = inboxWorld();
    $creator = inboxCreator();
    $relation = inboxRelation($agency, $creator, inviter: $admin);

    expect($relation->owner_user_id)->toBeNull()->and($relation->effectiveOwnerId())->toBe($admin->id);
    expect(inboxRelation($agency, inboxCreator())->effectiveOwnerId())->toBeNull();
});

// ── reassign ────────────────────────────────────────────────────────────────

it('lets an admin and a manager reassign the owner to an active member, writing ids-only audit', function (): void {
    ['agency' => $agency, 'admin' => $admin, 'manager' => $manager, 'staff' => $staff] = inboxWorld();
    $creator = inboxCreator();
    inboxRelation($agency, $creator, owner: $admin);

    $this->actingAs($admin)->patchJson(ownerUrl($agency, $creator), ['owner_user_id' => $staff->ulid])
        ->assertOk()->assertJsonPath('data.attributes.owner.id', $staff->ulid);
    $this->actingAs($manager)->patchJson(ownerUrl($agency, $creator), ['owner_user_id' => $manager->ulid])->assertOk();

    $rows = AuditLog::query()->where('action', AuditAction::AgencyCreatorRelationOwnerChanged->value)->orderBy('id')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows->map(fn ($r): mixed => $r->metadata)->all())->toBe([
            ['from_user' => $admin->id, 'to_user' => $staff->id],
            ['from_user' => $staff->id, 'to_user' => $manager->id],
        ]);
    // The relation's generic `.updated` audit row must NOT fire for the owner column.
    expect(AuditLog::query()->where('action', AuditAction::AgencyCreatorRelationUpdated->value)->count())->toBe(0);
});

it('is a no-op when the owner is unchanged: 200 and no audit row', function (): void {
    ['agency' => $agency, 'admin' => $admin] = inboxWorld();
    $creator = inboxCreator();
    inboxRelation($agency, $creator, owner: $admin);

    $this->actingAs($admin)->patchJson(ownerUrl($agency, $creator), ['owner_user_id' => $admin->ulid])->assertOk();

    expect(AuditLog::query()->where('action', AuditAction::AgencyCreatorRelationOwnerChanged->value)->count())->toBe(0);
});

it('refuses staff and creators (403), a non-member target (422), a foreign relation (404), a missing flag (404)', function (): void {
    ['agency' => $agency, 'admin' => $admin, 'staff' => $staff] = inboxWorld();
    ['agency' => $other, 'admin' => $otherAdmin] = inboxWorld();
    $creator = inboxCreator();
    $relation = inboxRelation($agency, $creator, owner: $admin);

    $this->actingAs($staff)->patchJson(ownerUrl($agency, $creator), ['owner_user_id' => $staff->ulid])->assertForbidden();
    $this->actingAs($creator->user)->patchJson(ownerUrl($agency, $creator), ['owner_user_id' => $admin->ulid])->assertStatus(404);

    $this->actingAs($admin)->patchJson(ownerUrl($agency, $creator), ['owner_user_id' => $otherAdmin->ulid])
        ->assertStatus(422)->assertJsonPath('errors.0.code', 'owner.not_a_member');
    $this->actingAs($admin)->patchJson(ownerUrl($agency, $creator), ['owner_user_id' => 'no-such-user'])->assertStatus(422);

    // A creator this agency has no relation with: 404, and nothing written.
    $stranger = inboxCreator();
    $this->actingAs($admin)->patchJson(ownerUrl($agency, $stranger), ['owner_user_id' => $admin->ulid])->assertNotFound();
    // Another agency's admin cannot reach this agency's relation.
    $this->actingAs($otherAdmin)->patchJson(ownerUrl($agency, $creator), ['owner_user_id' => $otherAdmin->ulid])->assertNotFound();

    expect($relation->fresh()?->owner_user_id)->toBe($admin->id);

    inboxWorld(flag: false);
    $this->actingAs($admin)->patchJson(ownerUrl($agency, $creator), ['owner_user_id' => $admin->ulid])->assertNotFound();
});

it('refuses a member who was removed from the agency (soft-deleted) as the new owner', function (): void {
    ['agency' => $agency, 'admin' => $admin, 'staff' => $staff] = inboxWorld();
    $creator = inboxCreator();
    inboxRelation($agency, $creator, owner: $admin);
    AgencyMembership::query()->where('user_id', $staff->id)->delete();

    $this->actingAs($admin)->patchJson(ownerUrl($agency, $creator), ['owner_user_id' => $staff->ulid])
        ->assertStatus(422)->assertJsonPath('errors.0.code', 'owner.not_a_member');
});

// ── Mine ────────────────────────────────────────────────────────────────────

it('Mine shows the viewer\'s own threads, All shows everything, unowned shows under All only', function (): void {
    ['agency' => $agency, 'admin' => $admin, 'manager' => $manager] = inboxWorld();
    $mine = inboxDirect($agency, inboxCreator(), owner: $admin);
    $theirs = inboxDirect($agency, inboxCreator(), owner: $manager);
    $unowned = inboxDirect($agency, inboxCreator());
    $inherited = inboxAssignment($agency, owner: $admin);          // assignment rows take their creator's owner
    $viaInviter = inboxCreator();
    inboxRelation($agency, $viaInviter, inviter: $admin);           // effective owner from invited_by
    $viaInviterThread = inboxDirect($agency, $viaInviter);

    $me = inboxList($this->actingAs($admin)->getJson(inboxUrl($agency, 'owner=me')), 'data')->pluck('id');
    $all = inboxList($this->actingAs($admin)->getJson(inboxUrl($agency, 'owner=all')), 'data')->pluck('id');

    expect($me->sort()->values()->all())->toBe(collect([$mine['thread']->ulid, $inherited['thread']->ulid, $viaInviterThread['thread']->ulid])->sort()->values()->all())
        ->and($all)->toHaveCount(5)
        ->and($all->contains($unowned['thread']->ulid))->toBeTrue()
        ->and($me->contains($unowned['thread']->ulid))->toBeFalse()
        ->and($me->contains($theirs['thread']->ulid))->toBeFalse();
});

it('shows the owner (id and name) on the row, null when unowned', function (): void {
    ['agency' => $agency, 'admin' => $admin] = inboxWorld();
    $owned = inboxDirect($agency, inboxCreator(), owner: $admin);
    $unowned = inboxDirect($agency, inboxCreator());

    $rows = inboxList($this->actingAs($admin)->getJson(inboxUrl($agency)), 'data')->keyBy('id');
    expect($rows[$owned['thread']->ulid]['attributes']['owner'])->toBe(['id' => $admin->ulid, 'name' => $admin->name])
        ->and($rows[$unowned['thread']->ulid]['attributes']['owner'])->toBeNull();
});
