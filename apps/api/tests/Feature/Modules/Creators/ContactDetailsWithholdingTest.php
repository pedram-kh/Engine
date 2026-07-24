<?php

declare(strict_types=1);

use App\Modules\Agencies\Http\Resources\CreatorDiscoveryResource;
use App\Modules\Agencies\Http\Resources\CreatorPublicProfileResource;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Http\Resources\CampaignAssignmentResource;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Enums\RelationshipStatus;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use App\Modules\TalentPools\Http\Resources\TalentPoolMemberResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| AH-005 — contact-details withholding spine (D4, "assert don't assume")
|--------------------------------------------------------------------------
|
| The four contact columns (phone / whatsapp / address_street /
| address_postal_code) are surfaced ONLY on the gated roster-detail surface
| (see AgencyCreatorDetailTest) and the owner/admin CreatorResource (see
| CreatorResourceTest). Every OTHER creator-bearing surface must withhold
| them. Each assertion below would FAIL if a contact key were added to that
| surface — they are real guards, not absent tests.
|
| One of them (CreatorPublicProfileResource) carries the withholding
| break-revert artifact: add `phone` to that resource → this discover-absence
| spec fails → revert.
|
*/

/** The four contact keys that must never appear on a withheld surface. */
const AH005_CONTACT_KEYS = ['phone', 'whatsapp', 'address_street', 'address_postal_code'];

/** The four contact VALUES (from CreatorFactory::withContact) that must not leak. */
const AH005_CONTACT_VALUES = ['+1 555 0100', '+1 555 0142', '12 Market Street', 'D02 XY45'];

function expectNoContactKeys(array $attributes): void
{
    // NB: assert against the KEY SET, not `toHaveKey($key, $message)` —
    // expect()->toHaveKey()'s second argument is an expected VALUE, not a
    // failure message, which would silently neuter the guard (caught by the
    // withholding break-revert).
    $keys = array_keys($attributes);
    foreach (AH005_CONTACT_KEYS as $key) {
        expect($keys)->not->toContain($key);
    }
}

function expectNoContactValues(string $body): void
{
    foreach (AH005_CONTACT_VALUES as $value) {
        expect($body)->not->toContain($value);
    }
}

// ---------------------------------------------------------------------------
// 1. Discover DETAIL — CreatorPublicProfileResource (break-revert surface)
// ---------------------------------------------------------------------------

it('CreatorPublicProfileResource (discover detail) withholds the contact details', function (): void {
    $creator = CreatorFactory::new()->withContact()->approved()->createOne();
    $creator->loadMissing(['socialAccounts', 'portfolioItems']);

    $attributes = (new CreatorPublicProfileResource($creator))->toArray(Request::create('/'))['attributes'];

    expectNoContactKeys($attributes);
});

// ---------------------------------------------------------------------------
// 2. Discover LIST card — CreatorDiscoveryResource
// ---------------------------------------------------------------------------

it('CreatorDiscoveryResource (discover list card) withholds the contact details', function (): void {
    $creator = CreatorFactory::new()->withContact()->approved()->createOne();

    $attributes = (new CreatorDiscoveryResource($creator))->toArray(Request::create('/'))['attributes'];

    expectNoContactKeys($attributes);
});

// ---------------------------------------------------------------------------
// 3. Talent pool member row — TalentPoolMemberResource
// ---------------------------------------------------------------------------

it('TalentPoolMemberResource withholds the contact details', function (): void {
    $creator = CreatorFactory::new()->withContact()->createOne();

    $attributes = (new TalentPoolMemberResource($creator))->toArray(Request::create('/'))['attributes'];

    expectNoContactKeys($attributes);
});

// ---------------------------------------------------------------------------
// 4. Campaign assignment row — CampaignAssignmentResource
// ---------------------------------------------------------------------------

it('CampaignAssignmentResource withholds the contact details (creator block is id + display_name only)', function (): void {
    $creator = CreatorFactory::new()->withContact()->createOne();
    $assignment = CampaignAssignment::factory()->createOne(['creator_id' => $creator->id]);
    $assignment->setRelation('creator', $creator);

    $attributes = (new CampaignAssignmentResource($assignment))->toArray(Request::create('/'))['attributes'];

    expect($attributes['creator'])->not->toBeNull();
    expectNoContactKeys($attributes['creator']);
});

// ---------------------------------------------------------------------------
// 5. Roster LIST row — AgencyCreatorController::toRow (endpoint-level)
// ---------------------------------------------------------------------------

it('the roster list row withholds the contact details', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $creator = CreatorFactory::new()->withContact()->createOne();
    AgencyCreatorRelation::factory()->create([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => RelationshipStatus::Roster,
        'is_blacklisted' => false,
    ]);

    $response = $this->actingAs($admin)->getJson("/api/v1/agencies/{$agency->ulid}/creators");

    $response->assertOk();
    $row = $response->json('data.0.attributes');
    expectNoContactKeys($row);
    expectNoContactValues($response->getContent());
});

// ---------------------------------------------------------------------------
// 6. Messaging thread list — AgencyMessageController (endpoint-level)
// ---------------------------------------------------------------------------

it('the agency message-thread list withholds the contact details', function (): void {
    $agency = Agency::factory()->createOne();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();
    $campaign = Campaign::factory()->createOne(['agency_id' => $agency->id, 'brand_id' => $brand->id]);
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $creatorUser = User::factory()->createOne();
    $creator = CreatorFactory::new()->withContact()->createOne(['user_id' => $creatorUser->id]);

    $assignment = CampaignAssignment::factory()->createOne([
        'agency_id' => $agency->id,
        'campaign_id' => $campaign->id,
        'brand_id' => $brand->id,
        'creator_id' => $creator->id,
        'invited_by_user_id' => $admin->id,
    ]);

    // A creator message provisions the thread the agency list reads.
    $this->actingAs($creatorUser)
        ->postJson("/api/v1/creators/me/assignments/{$assignment->ulid}/messages", ['body' => 'hi'])
        ->assertCreated();

    $response = $this->actingAs($admin)
        ->getJson("/api/v1/agencies/{$agency->ulid}/campaigns/{$campaign->ulid}/message-threads");

    $response->assertOk();
    expectNoContactValues($response->getContent());
});

// ---------------------------------------------------------------------------
// 7. Roster DETAIL contact gate — AH-051 D-1: the contact block is exposed ONLY
//    to a rostered (connected) non-blacklisted agency. Every non-roster status
//    (pending_request / declined / prospect / ended) is WITHHELD — this is the
//    gate that TIGHTENS in AH-051 (pending_request previously saw contact).
//    Break-revert: relax CreatorPolicy::canSeeContactDetails back to
//    hasNonBlacklistedRelation (any status) → the pending_request/declined/
//    prospect/ended cases below fail → revert.
// ---------------------------------------------------------------------------

it('the roster detail EXPOSES contact only to a rostered non-blacklisted agency', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $creator = CreatorFactory::new()->withContact()->createOne();
    AgencyCreatorRelation::factory()->create([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => RelationshipStatus::Roster,
        'is_blacklisted' => false,
    ]);

    $response = $this->actingAs($admin)
        ->getJson("/api/v1/agencies/{$agency->ulid}/creators/{$creator->ulid}");

    $response->assertOk()
        ->assertJsonPath('data.attributes.creator.phone', '+1 555 0100')
        ->assertJsonPath('data.attributes.creator.whatsapp', '+1 555 0142');
});

it('the roster detail WITHHOLDS contact from every non-roster status (AH-051 D-1 tightening)', function (RelationshipStatus $status): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $creator = CreatorFactory::new()->withContact()->createOne();
    AgencyCreatorRelation::factory()->create([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => $status,
        'is_blacklisted' => false,
    ]);

    $response = $this->actingAs($admin)
        ->getJson("/api/v1/agencies/{$agency->ulid}/creators/{$creator->ulid}");

    // The detail page renders (the relation exists) …
    $response->assertOk();
    // … but carries NO contact keys and leaks no values (withheld by omission).
    expectNoContactKeys($response->json('data.attributes.creator'));
    expectNoContactValues($response->getContent());
})->with([
    'pending_request' => [RelationshipStatus::PendingRequest],
    'declined' => [RelationshipStatus::Declined],
    'prospect' => [RelationshipStatus::Prospect],
    'ended' => [RelationshipStatus::Ended],
]);
