<?php

declare(strict_types=1);

use App\Modules\Agencies\Database\Factories\AgencyFactory;
use App\Modules\Agencies\Database\Factories\AgencyMembershipFactory;
use App\Modules\Agencies\Enums\AgencyRole;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Creators\Enums\RelationshipStatus;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Policies\CreatorPolicy;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| CreatorPolicy independent unit coverage (#40)
|--------------------------------------------------------------------------
|
| Defense-in-depth coverage standing standard #40: every policy method
| ships with independent unit-test coverage from first commit. Break-revert
| verified at chunk close — temporarily flip each method to true/false,
| confirm a test fails, revert.
|
*/

function creatorPolicy(): CreatorPolicy
{
    return new CreatorPolicy;
}

// ---------------------------------------------------------------------------
// viewAny — platform-admin-only listing
// ---------------------------------------------------------------------------

it('viewAny returns true for platform admins', function (): void {
    $admin = User::factory()->platformAdmin()->createOne();

    expect(creatorPolicy()->viewAny($admin))->toBeTrue();
});

it('viewAny returns false for creators', function (): void {
    $creator = User::factory()->creator()->createOne();

    expect(creatorPolicy()->viewAny($creator))->toBeFalse();
});

it('viewAny returns false for agency members', function (): void {
    $member = User::factory()->agencyAdmin()->createOne();

    expect(creatorPolicy()->viewAny($member))->toBeFalse();
});

// ---------------------------------------------------------------------------
// view — owner / agency-member / platform-admin
// ---------------------------------------------------------------------------

it('view returns true for the owning creator user', function (): void {
    $owner = User::factory()->creator()->createOne();
    $creator = Creator::factory()->createOne(['user_id' => $owner->id]);

    expect(creatorPolicy()->view($owner, $creator))->toBeTrue();
});

it('view returns true for platform admins', function (): void {
    $admin = User::factory()->platformAdmin()->createOne();
    $creator = Creator::factory()->createOne();

    expect(creatorPolicy()->view($admin, $creator))->toBeTrue();
});

it('view returns true for an agency member with a non-blacklisted relation', function (): void {
    $agency = AgencyFactory::new()->createOne();
    $member = User::factory()->agencyAdmin($agency)->createOne();
    $creator = Creator::factory()->createOne();

    AgencyCreatorRelation::factory()->createOne([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'is_blacklisted' => false,
    ]);

    expect(creatorPolicy()->view($member, $creator))->toBeTrue();
});

it('view returns false for an agency member when no relation exists', function (): void {
    $agency = AgencyFactory::new()->createOne();
    $member = User::factory()->agencyAdmin($agency)->createOne();
    $creator = Creator::factory()->createOne();

    expect(creatorPolicy()->view($member, $creator))->toBeFalse();
});

it('view returns false for an agency member when the relation is blacklisted', function (): void {
    $agency = AgencyFactory::new()->createOne();
    $member = User::factory()->agencyAdmin($agency)->createOne();
    $creator = Creator::factory()->createOne();

    AgencyCreatorRelation::factory()
        ->blacklisted('Hard ban')
        ->createOne([
            'agency_id' => $agency->id,
            'creator_id' => $creator->id,
        ]);

    expect(creatorPolicy()->view($member, $creator))->toBeFalse();
});

it('view returns false for a different creator (non-owner, non-admin)', function (): void {
    $other = User::factory()->creator()->createOne();
    $creator = Creator::factory()->createOne();

    expect(creatorPolicy()->view($other, $creator))->toBeFalse();
});

// ---------------------------------------------------------------------------
// update — owner only
// ---------------------------------------------------------------------------

it('update returns true for the owning creator user', function (): void {
    $owner = User::factory()->creator()->createOne();
    $creator = Creator::factory()->createOne(['user_id' => $owner->id]);

    expect(creatorPolicy()->update($owner, $creator))->toBeTrue();
});

it('update returns false for a different creator', function (): void {
    $other = User::factory()->creator()->createOne();
    $creator = Creator::factory()->createOne();

    expect(creatorPolicy()->update($other, $creator))->toBeFalse();
});

it('update returns false for platform admins (admins use adminUpdate)', function (): void {
    $admin = User::factory()->platformAdmin()->createOne();
    $creator = Creator::factory()->createOne();

    expect(creatorPolicy()->update($admin, $creator))->toBeFalse();
});

it('update returns false for agency members even with a relation', function (): void {
    $agency = AgencyFactory::new()->createOne();
    $member = User::factory()->agencyAdmin($agency)->createOne();
    $creator = Creator::factory()->createOne();
    AgencyCreatorRelation::factory()->createOne([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
    ]);

    expect(creatorPolicy()->update($member, $creator))->toBeFalse();
});

// ---------------------------------------------------------------------------
// adminUpdate — platform admin only
// ---------------------------------------------------------------------------

it('adminUpdate returns true for platform admins', function (): void {
    $admin = User::factory()->platformAdmin()->createOne();
    $creator = Creator::factory()->createOne();

    expect(creatorPolicy()->adminUpdate($admin, $creator))->toBeTrue();
});

it('adminUpdate returns false for the owning creator user', function (): void {
    $owner = User::factory()->creator()->createOne();
    $creator = Creator::factory()->createOne(['user_id' => $owner->id]);

    expect(creatorPolicy()->adminUpdate($owner, $creator))->toBeFalse();
});

it('adminUpdate returns false for agency members', function (): void {
    $member = User::factory()->agencyAdmin()->createOne();
    $creator = Creator::factory()->createOne();

    expect(creatorPolicy()->adminUpdate($member, $creator))->toBeFalse();
});

// ---------------------------------------------------------------------------
// approve / reject — Sprint 3 Chunk 4 promoted these from Sprint 4 stubs to
// real platform-admin gates. Owners and agency members remain denied; the
// controllers wrapping these methods carry their own business-rule checks
// (e.g. status transitions) on top of the policy.
// ---------------------------------------------------------------------------

it('approve returns true for platform admins and false for everyone else', function (): void {
    $admin = User::factory()->platformAdmin()->createOne();
    $owner = User::factory()->creator()->createOne();
    $member = User::factory()->agencyAdmin()->createOne();
    $creator = Creator::factory()->createOne(['user_id' => $owner->id]);

    expect(creatorPolicy()->approve($admin, $creator))->toBeTrue()
        ->and(creatorPolicy()->approve($owner, $creator))->toBeFalse()
        ->and(creatorPolicy()->approve($member, $creator))->toBeFalse();
});

it('reject returns true for platform admins and false for everyone else', function (): void {
    $admin = User::factory()->platformAdmin()->createOne();
    $owner = User::factory()->creator()->createOne();
    $member = User::factory()->agencyAdmin()->createOne();
    $creator = Creator::factory()->createOne(['user_id' => $owner->id]);

    expect(creatorPolicy()->reject($admin, $creator))->toBeTrue()
        ->and(creatorPolicy()->reject($owner, $creator))->toBeFalse()
        ->and(creatorPolicy()->reject($member, $creator))->toBeFalse();
});

// ---------------------------------------------------------------------------
// canSeeContactDetails (AH-005 / AH-051 D-1) — AGENCY-scoped contact-visibility
// gate. admin OR (active member of THIS agency AND this agency holds a
// non-blacklisted ROSTER relation). Never a user-wide union. AH-051 TIGHTENS
// this to roster-only: pending_request / declined / prospect / ended all fail
// (they previously passed on the looser not-blacklisted-only rule).
// Break-revert: relax canSeeContactDetails back to hasNonBlacklistedRelation
// (any status) → the roster-only matrix below fails → revert.
// ---------------------------------------------------------------------------

it('canSeeContactDetails returns true for an agency member with a non-blacklisted ROSTER relation', function (): void {
    $agency = AgencyFactory::new()->createOne();
    $member = User::factory()->agencyAdmin($agency)->createOne();
    $creator = Creator::factory()->createOne();

    AgencyCreatorRelation::factory()->createOne([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'is_blacklisted' => false,
    ]);

    expect(creatorPolicy()->canSeeContactDetails($member, $creator, $agency))->toBeTrue();
});

it('canSeeContactDetails returns true for platform admins', function (): void {
    $admin = User::factory()->platformAdmin()->createOne();
    $agency = AgencyFactory::new()->createOne();
    $creator = Creator::factory()->createOne();

    expect(creatorPolicy()->canSeeContactDetails($admin, $creator, $agency))->toBeTrue();
});

it('canSeeContactDetails returns false when THIS agency has blacklisted the rostered creator', function (): void {
    $agency = AgencyFactory::new()->createOne();
    $member = User::factory()->agencyAdmin($agency)->createOne();
    $creator = Creator::factory()->createOne();

    AgencyCreatorRelation::factory()
        ->blacklisted('Hard ban')
        ->createOne([
            'agency_id' => $agency->id,
            'creator_id' => $creator->id,
        ]);

    expect(creatorPolicy()->canSeeContactDetails($member, $creator, $agency))->toBeFalse();
});

it('canSeeContactDetails returns false for an agency member when no relation exists', function (): void {
    $agency = AgencyFactory::new()->createOne();
    $member = User::factory()->agencyAdmin($agency)->createOne();
    $creator = Creator::factory()->createOne();

    expect(creatorPolicy()->canSeeContactDetails($member, $creator, $agency))->toBeFalse();
});

it('canSeeContactDetails is AGENCY-scoped: a multi-agency user sees no contact on Agency A even when their Agency B relation is clean', function (): void {
    $agencyA = AgencyFactory::new()->createOne();
    $agencyB = AgencyFactory::new()->createOne();

    // One user, active member of BOTH agencies.
    $member = User::factory()->agencyAdmin($agencyA)->createOne();
    AgencyMembershipFactory::new()->state([
        'agency_id' => $agencyB->id,
        'user_id' => $member->id,
        'role' => AgencyRole::AgencyAdmin,
        'accepted_at' => now(),
    ])->create();

    $creator = Creator::factory()->createOne();

    // Agency A has BLACKLISTED the creator; Agency B has a clean relation.
    AgencyCreatorRelation::factory()
        ->blacklisted('Hard ban')
        ->createOne(['agency_id' => $agencyA->id, 'creator_id' => $creator->id]);
    AgencyCreatorRelation::factory()->createOne([
        'agency_id' => $agencyB->id,
        'creator_id' => $creator->id,
        'is_blacklisted' => false,
    ]);

    // On Agency A's page → withheld (A's own relation is blacklisted), even
    // though the same user has a clean relation via Agency B.
    expect(creatorPolicy()->canSeeContactDetails($member, $creator, $agencyA))->toBeFalse()
        // On Agency B's page → visible. Proves the scope cuts BOTH ways.
        ->and(creatorPolicy()->canSeeContactDetails($member, $creator, $agencyB))->toBeTrue();
});

it('canSeeContactDetails returns false for a member of a different agency (non-member of the target agency)', function (): void {
    $targetAgency = AgencyFactory::new()->createOne();
    $otherAgency = AgencyFactory::new()->createOne();
    $member = User::factory()->agencyAdmin($otherAgency)->createOne();
    $creator = Creator::factory()->createOne();

    // A clean relation exists on the TARGET agency, but the caller does not
    // belong to it — membership is required, not merely a relation.
    AgencyCreatorRelation::factory()->createOne([
        'agency_id' => $targetAgency->id,
        'creator_id' => $creator->id,
        'is_blacklisted' => false,
    ]);

    expect(creatorPolicy()->canSeeContactDetails($member, $creator, $targetAgency))->toBeFalse();
});

it('canSeeContactDetails returns false for the owning creator user (not an agency surface)', function (): void {
    $owner = User::factory()->creator()->createOne();
    $agency = AgencyFactory::new()->createOne();
    $creator = Creator::factory()->createOne(['user_id' => $owner->id]);

    expect(creatorPolicy()->canSeeContactDetails($owner, $creator, $agency))->toBeFalse();
});

it('canSeeContactDetails is ROSTER-ONLY: every non-roster status is withheld (AH-051 D-1 tightening)', function (RelationshipStatus $status): void {
    $agency = AgencyFactory::new()->createOne();
    $member = User::factory()->agencyAdmin($agency)->createOne();
    $creator = Creator::factory()->createOne();

    AgencyCreatorRelation::factory()->createOne([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => $status,
        'is_blacklisted' => false,
    ]);

    expect(creatorPolicy()->canSeeContactDetails($member, $creator, $agency))->toBeFalse();
})->with([
    'pending_request' => [RelationshipStatus::PendingRequest],
    'declined' => [RelationshipStatus::Declined],
    'prospect' => [RelationshipStatus::Prospect],
    'ended' => [RelationshipStatus::Ended],
    'external' => [RelationshipStatus::External],
]);

// ---------------------------------------------------------------------------
// canMessageRelationship (AH-010, D2) — the LOAD-BEARING messaging gate.
// Permits messaging ONLY for: approved creator + roster relation +
// non-blacklisted + (active member of THIS agency OR the owning creator).
// Explicitly excludes prospect / pending_request / declined / external /
// blacklisted / non-approved. STRICTER than canSeeContactDetails on purpose:
// the not-blacklisted-only predicate would let a declined agency DM (spam
// vector). Break-revert: loosen relationPermitsMessaging() to not-blacklisted
// -only → the declined-agency-blocked spec fails → revert.
// ---------------------------------------------------------------------------

it('canMessageRelationship returns true for an active agency member with an approved creator on a non-blacklisted roster relation', function (): void {
    $agency = AgencyFactory::new()->createOne();
    $member = User::factory()->agencyAdmin($agency)->createOne();
    $creator = Creator::factory()->approved()->createOne();

    AgencyCreatorRelation::factory()->createOne([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => RelationshipStatus::Roster,
        'is_blacklisted' => false,
    ]);

    expect(creatorPolicy()->canMessageRelationship($member, $creator, $agency))->toBeTrue();
});

it('canMessageRelationship returns true for the owning creator user on a qualifying relation', function (): void {
    $agency = AgencyFactory::new()->createOne();
    $owner = User::factory()->creator()->createOne();
    $creator = Creator::factory()->approved()->createOne(['user_id' => $owner->id]);

    AgencyCreatorRelation::factory()->createOne([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => RelationshipStatus::Roster,
        'is_blacklisted' => false,
    ]);

    expect(creatorPolicy()->canMessageRelationship($owner, $creator, $agency))->toBeTrue();
});

it('canMessageRelationship BLOCKS a declined relation (break-revert anchor — the spam vector)', function (): void {
    $agency = AgencyFactory::new()->createOne();
    $member = User::factory()->agencyAdmin($agency)->createOne();
    $creator = Creator::factory()->approved()->createOne();

    // A declined relation is NOT blacklisted — so the AH-005 not-blacklisted
    // predicate would (wrongly) allow it. This gate must still block.
    AgencyCreatorRelation::factory()
        ->declined()
        ->createOne(['agency_id' => $agency->id, 'creator_id' => $creator->id]);

    expect(creatorPolicy()->canMessageRelationship($member, $creator, $agency))->toBeFalse();
});

it('canMessageRelationship BLOCKS a prospect relation', function (): void {
    $agency = AgencyFactory::new()->createOne();
    $member = User::factory()->agencyAdmin($agency)->createOne();
    $creator = Creator::factory()->approved()->createOne();

    AgencyCreatorRelation::factory()
        ->prospect()
        ->createOne(['agency_id' => $agency->id, 'creator_id' => $creator->id]);

    expect(creatorPolicy()->canMessageRelationship($member, $creator, $agency))->toBeFalse();
});

it('canMessageRelationship BLOCKS a pending_request relation', function (): void {
    $agency = AgencyFactory::new()->createOne();
    $member = User::factory()->agencyAdmin($agency)->createOne();
    $creator = Creator::factory()->approved()->createOne();

    AgencyCreatorRelation::factory()
        ->pendingRequest()
        ->createOne(['agency_id' => $agency->id, 'creator_id' => $creator->id]);

    expect(creatorPolicy()->canMessageRelationship($member, $creator, $agency))->toBeFalse();
});

it('canMessageRelationship BLOCKS an external relation (unreachable + semantically non-roster)', function (): void {
    $agency = AgencyFactory::new()->createOne();
    $member = User::factory()->agencyAdmin($agency)->createOne();
    $creator = Creator::factory()->approved()->createOne();

    AgencyCreatorRelation::factory()->createOne([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => RelationshipStatus::External,
        'is_blacklisted' => false,
    ]);

    expect(creatorPolicy()->canMessageRelationship($member, $creator, $agency))->toBeFalse();
});

it('canMessageRelationship BLOCKS a blacklisted roster relation', function (): void {
    $agency = AgencyFactory::new()->createOne();
    $member = User::factory()->agencyAdmin($agency)->createOne();
    $creator = Creator::factory()->approved()->createOne();

    AgencyCreatorRelation::factory()
        ->blacklisted('Hard ban')
        ->createOne([
            'agency_id' => $agency->id,
            'creator_id' => $creator->id,
            'relationship_status' => RelationshipStatus::Roster,
        ]);

    expect(creatorPolicy()->canMessageRelationship($member, $creator, $agency))->toBeFalse();
});

it('canMessageRelationship BLOCKS a non-approved creator even on a clean roster relation', function (): void {
    $agency = AgencyFactory::new()->createOne();
    $member = User::factory()->agencyAdmin($agency)->createOne();
    // Default factory creator is `incomplete` (not approved).
    $creator = Creator::factory()->createOne();

    AgencyCreatorRelation::factory()->createOne([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => RelationshipStatus::Roster,
        'is_blacklisted' => false,
    ]);

    expect(creatorPolicy()->canMessageRelationship($member, $creator, $agency))->toBeFalse();
});

it('canMessageRelationship returns false for a platform admin (admins are not party to a 1:1 thread)', function (): void {
    $admin = User::factory()->platformAdmin()->createOne();
    $agency = AgencyFactory::new()->createOne();
    $creator = Creator::factory()->approved()->createOne();

    AgencyCreatorRelation::factory()->createOne([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => RelationshipStatus::Roster,
        'is_blacklisted' => false,
    ]);

    expect(creatorPolicy()->canMessageRelationship($admin, $creator, $agency))->toBeFalse();
});

it('canMessageRelationship returns false for an agency member who does not belong to the qualifying agency', function (): void {
    $qualifyingAgency = AgencyFactory::new()->createOne();
    $otherAgency = AgencyFactory::new()->createOne();
    $member = User::factory()->agencyAdmin($otherAgency)->createOne();
    $creator = Creator::factory()->approved()->createOne();

    // The qualifying roster relation is on an agency the caller does NOT belong
    // to — org membership of THIS agency is required, not merely a relation.
    AgencyCreatorRelation::factory()->createOne([
        'agency_id' => $qualifyingAgency->id,
        'creator_id' => $creator->id,
        'relationship_status' => RelationshipStatus::Roster,
        'is_blacklisted' => false,
    ]);

    expect(creatorPolicy()->canMessageRelationship($member, $creator, $qualifyingAgency))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Membership — NOT `users.type` — is the authority on the agency side.
//
// The public sign-up form is the only sign-up path and always stamps
// `users.type = creator`; accepting an agency invitation adds the membership
// row without flipping it. So a real agency admin normally carries
// `type = creator` (26 of 30 live agency admins did when this was found), and
// `view` / `canSeeContactDetails` / `canMessageRelationship` used to deny all
// of them by checking the type instead of the membership.
//
// Every ALLOW case below is paired with its DENY case, because the entire
// safety of dropping those type checks rests on a membership-LESS user still
// being refused. What refuses them is that activeAgencyIds() is scoped to the
// CALLER — so each deny case deliberately seeds a DIFFERENT user holding a real
// accepted membership in the same agency. Without that row the deny specs pass
// merely because no membership exists anywhere, which proves nothing; the first
// draft of these specs had exactly that hole and the break-revert below caught
// it.
//
// Break-revert (verified): drop the `where('user_id', …)` filter in
// activeAgencyIds() → the three creator-typed-NON-member deny specs go red →
// restore. Note that deleting the `$agencyIds === []` early-return in
// hasNonBlacklistedRelation() does NOT redden anything, because an empty
// `whereIn` already compiles to `0 = 1` — that early-return saves a query, it
// is not the gate.
// ---------------------------------------------------------------------------

it('view returns true for a creator-TYPED agency member (the production shape) with a non-blacklisted relation', function (): void {
    $agency = AgencyFactory::new()->createOne();
    $member = User::factory()->creatorTypedAgencyMember($agency)->createOne();
    $creator = Creator::factory()->createOne();

    AgencyCreatorRelation::factory()->createOne([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'is_blacklisted' => false,
    ]);

    // Guard the premise: the factory really does reproduce the live shape.
    expect($member->type)->toBe(UserType::Creator)
        ->and(creatorPolicy()->view($member, $creator))->toBeTrue();
});

it('view returns false for a creator-typed user with NO membership, even when a clean relation exists', function (): void {
    $agency = AgencyFactory::new()->createOne();
    $outsider = User::factory()->creator()->createOne();
    $creator = Creator::factory()->createOne();

    // Somebody ELSE is a legitimate accepted member of this agency. Without
    // this row the spec would pass merely because the agency has no members at
    // all, which would not prove the membership lookup is scoped to the CALLER.
    User::factory()->creatorTypedAgencyMember($agency)->createOne();

    // The relation is real, but it belongs to an agency this user has no
    // membership in — the empty-membership path must refuse.
    AgencyCreatorRelation::factory()->createOne([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'is_blacklisted' => false,
    ]);

    expect(creatorPolicy()->view($outsider, $creator))->toBeFalse();
});

it('canSeeContactDetails returns true for a creator-TYPED agency member on a non-blacklisted ROSTER relation', function (): void {
    $agency = AgencyFactory::new()->createOne();
    $member = User::factory()->creatorTypedAgencyMember($agency)->createOne();
    $creator = Creator::factory()->createOne();

    AgencyCreatorRelation::factory()->createOne([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => RelationshipStatus::Roster,
        'is_blacklisted' => false,
    ]);

    expect(creatorPolicy()->canSeeContactDetails($member, $creator, $agency))->toBeTrue();
});

it('canSeeContactDetails returns false for a creator-typed user with NO membership in the target agency', function (): void {
    $agency = AgencyFactory::new()->createOne();
    $outsider = User::factory()->creator()->createOne();
    $creator = Creator::factory()->createOne();

    // A legitimate member of the same agency exists — so a pass here could only
    // come from the membership lookup leaking across users.
    User::factory()->creatorTypedAgencyMember($agency)->createOne();

    AgencyCreatorRelation::factory()->createOne([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => RelationshipStatus::Roster,
        'is_blacklisted' => false,
    ]);

    expect(creatorPolicy()->canSeeContactDetails($outsider, $creator, $agency))->toBeFalse();
});

it('canMessageRelationship returns true for a creator-TYPED agency member on a qualifying relation', function (): void {
    $agency = AgencyFactory::new()->createOne();
    $member = User::factory()->creatorTypedAgencyMember($agency)->createOne();
    $creator = Creator::factory()->approved()->createOne();

    AgencyCreatorRelation::factory()->createOne([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => RelationshipStatus::Roster,
        'is_blacklisted' => false,
    ]);

    expect(creatorPolicy()->canMessageRelationship($member, $creator, $agency))->toBeTrue();
});

it('canMessageRelationship returns false for a creator-typed NON-member, even on a qualifying relation', function (): void {
    $agency = AgencyFactory::new()->createOne();
    $outsider = User::factory()->creator()->createOne();
    $creator = Creator::factory()->approved()->createOne();

    // A legitimate member of the same agency exists, so the refusal must come
    // from the caller-scoped membership lookup rather than from an empty table.
    User::factory()->creatorTypedAgencyMember($agency)->createOne();

    AgencyCreatorRelation::factory()->createOne([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => RelationshipStatus::Roster,
        'is_blacklisted' => false,
    ]);

    // Not the owning creator either — an unrelated creator account.
    expect(creatorPolicy()->canMessageRelationship($outsider, $creator, $agency))->toBeFalse();
});

it('canMessageRelationship still refuses a platform admin who DOES hold an agency membership', function (): void {
    $agency = AgencyFactory::new()->createOne();
    $admin = User::factory()->platformAdmin()->createOne();
    $creator = Creator::factory()->approved()->createOne();

    // The membership makes the admin pass the membership check, so the refusal
    // has to come from the explicit platform-admin branch — admins are never
    // party to a 1:1 thread, and that must not rely on them happening to hold
    // no membership row.
    AgencyMembershipFactory::new()->state([
        'agency_id' => $agency->id,
        'user_id' => $admin->id,
        'role' => AgencyRole::AgencyAdmin,
        'accepted_at' => now(),
    ])->create();

    AgencyCreatorRelation::factory()->createOne([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => RelationshipStatus::Roster,
        'is_blacklisted' => false,
    ]);

    expect(creatorPolicy()->canMessageRelationship($admin, $creator, $agency))->toBeFalse();
});

it('viewAny stays platform-admin-only for a creator-typed agency member (membership is not admin rights)', function (): void {
    $member = User::factory()->creatorTypedAgencyMember()->createOne();

    expect(creatorPolicy()->viewAny($member))->toBeFalse();
});
