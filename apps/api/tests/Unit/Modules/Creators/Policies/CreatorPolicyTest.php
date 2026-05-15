<?php

declare(strict_types=1);

use App\Modules\Agencies\Database\Factories\AgencyFactory;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Policies\CreatorPolicy;
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
