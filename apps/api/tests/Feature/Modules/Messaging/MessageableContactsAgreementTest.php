<?php

declare(strict_types=1);

use App\Modules\Agencies\Database\Factories\AgencyFactory;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Enums\RelationshipStatus;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Policies\CreatorPolicy;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Services\MessageableContactsFinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| AH-012 (D3) — the predicate-AGREEMENT proof
|--------------------------------------------------------------------------
|
| The set-valued MessageableContactsFinder and the single-pair messaging gate
| (CreatorPolicy::canMessageRelationship) MUST agree, contact-for-contact:
|   - every contact the set-finder returns passes the single-pair gate;
|   - every contact the gate rejects is absent from the set.
|
| They share ONE source of truth — AgencyCreatorRelation::scopePermitsMessaging
| (roster + non-blacklisted) — plus the creator-`approved` leg applied the same
| way on both sides. This test pins them together.
|
| Break-revert (the spine's set-side anchor): drop the roster constraint from
| AgencyCreatorRelation::scopePermitsMessaging() → a non-roster relation leaks
| into the set the single-pair gate still rejects → the agency-side agreement
| assertion below fails → revert.
|
*/

function msgGatePolicy(): CreatorPolicy
{
    return new CreatorPolicy;
}

function contactsFinder(): MessageableContactsFinder
{
    return new MessageableContactsFinder;
}

/**
 * Make a creator + a relation to the agency in a given shape.
 */
function agreementRelatedCreator(Agency $agency, RelationshipStatus $status, bool $approved, bool $blacklisted): Creator
{
    $factory = CreatorFactory::new();
    if ($approved) {
        $factory = $factory->approved();
    }
    $creator = $factory->createOne();

    AgencyCreatorRelation::factory()->createOne([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => $status,
        'is_blacklisted' => $blacklisted,
    ]);

    return $creator;
}

it('agency set-finder agrees with the single-pair gate across the full status matrix', function (): void {
    $agency = AgencyFactory::new()->createOne();
    $member = User::factory()->agencyAdmin($agency)->createOne();

    // The matrix: every relationship_status × approved? × blacklisted?. Only
    // (roster, approved, not-blacklisted) should be messageable.
    $statuses = [
        RelationshipStatus::Roster,
        RelationshipStatus::Prospect,
        RelationshipStatus::External,
        RelationshipStatus::PendingRequest,
        RelationshipStatus::Declined,
        // AH-051 (D-3) — the severed state must never be messageable.
        RelationshipStatus::Ended,
    ];

    /** @var list<Creator> $creators */
    $creators = [];
    foreach ($statuses as $status) {
        foreach ([true, false] as $approved) {
            foreach ([true, false] as $blacklisted) {
                $creators[] = agreementRelatedCreator($agency, $status, $approved, $blacklisted);
            }
        }
    }

    // The set the finder returns (unpaginated-enough: 100/page over a ~20 set).
    $setUlids = collect(contactsFinder()->creatorsForAgency($agency, null, 100, 1)->items())
        ->map(static fn (AgencyCreatorRelation $r): ?string => $r->creator?->ulid)
        ->filter()
        ->all();

    // Agreement, contact-for-contact: in-set IFF the single-pair gate passes.
    foreach ($creators as $creator) {
        $gatePasses = msgGatePolicy()->canMessageRelationship($member, $creator, $agency);
        $inSet = in_array($creator->ulid, $setUlids, true);

        expect($inSet)->toBe(
            $gatePasses,
            "agency set/gate disagree for creator {$creator->ulid}",
        );
    }

    // Sanity: the set is non-empty (the one qualifying row exists).
    expect($setUlids)->not->toBeEmpty();
});

it('creator set-finder agrees with the single-pair gate across the agency matrix', function (): void {
    $creatorUser = User::factory()->createOne();
    $creator = CreatorFactory::new()->approved()->createOne(['user_id' => $creatorUser->id]);

    $statuses = [
        RelationshipStatus::Roster,
        RelationshipStatus::Prospect,
        RelationshipStatus::External,
        RelationshipStatus::PendingRequest,
        RelationshipStatus::Declined,
        // AH-051 (D-3) — the severed state must never be messageable.
        RelationshipStatus::Ended,
    ];

    /** @var list<Agency> $agencies */
    $agencies = [];
    foreach ($statuses as $status) {
        foreach ([true, false] as $blacklisted) {
            $agency = AgencyFactory::new()->createOne();
            AgencyCreatorRelation::factory()->createOne([
                'agency_id' => $agency->id,
                'creator_id' => $creator->id,
                'relationship_status' => $status,
                'is_blacklisted' => $blacklisted,
            ]);
            $agencies[] = $agency;
        }
    }

    $setUlids = contactsFinder()->agenciesForCreator($creator)
        ->map(static fn (Agency $a): string => $a->ulid)
        ->all();

    foreach ($agencies as $agency) {
        $gatePasses = msgGatePolicy()->canMessageRelationship($creatorUser, $creator, $agency);
        $inSet = in_array($agency->ulid, $setUlids, true);

        expect($inSet)->toBe(
            $gatePasses,
            "creator set/gate disagree for agency {$agency->ulid}",
        );
    }

    expect($setUlids)->not->toBeEmpty();
});

it('an unapproved creator can message no agency — empty set AND a false gate everywhere', function (): void {
    $creatorUser = User::factory()->createOne();
    // Default factory creator is `incomplete` (NOT approved).
    $creator = CreatorFactory::new()->createOne(['user_id' => $creatorUser->id]);

    $agency = AgencyFactory::new()->createOne();
    AgencyCreatorRelation::factory()->createOne([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => RelationshipStatus::Roster,
        'is_blacklisted' => false,
    ]);

    expect(contactsFinder()->agenciesForCreator($creator)->all())->toBe([]);
    expect(msgGatePolicy()->canMessageRelationship($creatorUser, $creator, $agency))->toBeFalse();
});
