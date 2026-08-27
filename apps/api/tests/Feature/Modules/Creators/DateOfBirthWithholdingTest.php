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
use App\Modules\Creators\Http\Resources\CreatorResource;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Services\CompletenessScoreCalculator;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use App\Modules\TalentPools\Http\Resources\TalentPoolMemberResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| AH-088 — date-of-birth withholding spine (D5, "assert don't assume")
|--------------------------------------------------------------------------
|
| A COMPANION to the AH-005 contact spine (ContactDetailsWithholdingTest), and
| deliberately NOT a copy of its shape. Read the asymmetry before touching
| either file:
|
|   - contact  is withheld from every surface EXCEPT the owner/admin
|              CreatorResource and the GATED roster-detail full mode. It has an
|              agency-facing mode: a rostered, non-blacklisted agency sees a
|              phone number.
|   - the DOB  has NO agency-facing mode and NO admin mode. The raw date crosses
|              to the OWNER and to nobody else — so the roster detail's
|              contact-included full mode, the one surface the contact spine
|              deliberately exempts, is a WITHHOLDING surface here, and so is
|              the admin drill-in.
|
| That is stricter than contact by decision, not by oversight. A future reviewer
| "aligning" this file with the contact spine's exemptions would be removing the
| guarantee the chunk exists to provide.
|
| Everyone downstream reads the DERIVED `age` instead — asserted positively here
| too, so "withheld" can never quietly become "absent entirely".
|
| Break-revert artifact: emit `$creator->date_of_birth` from
| AgencyCreatorDetailResource::mapCreator() → the roster-detail cases below fail
| (both the full/rostered mode and the withheld statuses) → revert.
|
*/

/** The raw column that must never appear on a surface other than the owner's. */
const AH088_DOB_KEY = 'date_of_birth';

/** A fixed birthday, old enough that no rule about minors is in play. */
const AH088_DOB_VALUE = '1994-03-17';

function dobCreator(array $attributes = []): Creator
{
    return CreatorFactory::new()->createOne([AH088_DOB_KEY => AH088_DOB_VALUE] + $attributes);
}

function expectNoDobKey(array $attributes): void
{
    // The KEY SET, not `toHaveKey($key, $message)` — that second argument is an
    // expected VALUE, which would silently neuter the guard (the trap the
    // AH-005 spine documents at its own helper).
    expect(array_keys($attributes))->not->toContain(AH088_DOB_KEY);
}

function expectNoDobValue(string $body): void
{
    expect($body)->not->toContain(AH088_DOB_VALUE)
        // The ISO-8601 rendering of the same date, in case a future resource
        // ships the Carbon instance rather than the date string.
        ->and($body)->not->toContain('1994-03-17T');
}

// ---------------------------------------------------------------------------
// 1. Discover DETAIL — CreatorPublicProfileResource (age yes, birthday no)
// ---------------------------------------------------------------------------

it('CreatorPublicProfileResource (discover detail) emits age and withholds the birthday', function (): void {
    $creator = dobCreator();
    $creator->loadMissing(['socialAccounts', 'portfolioItems']);

    $attributes = (new CreatorPublicProfileResource($creator))->toArray(Request::create('/'))['attributes'];

    expectNoDobKey($attributes);
    expect($attributes['age'])->toBe($creator->age());
});

// ---------------------------------------------------------------------------
// 2. Discover LIST card — CreatorDiscoveryResource
// ---------------------------------------------------------------------------

it('CreatorDiscoveryResource (discover list card) emits age and withholds the birthday', function (): void {
    $creator = dobCreator();

    $attributes = (new CreatorDiscoveryResource($creator))->toArray(Request::create('/'))['attributes'];

    expectNoDobKey($attributes);
    expect($attributes['age'])->toBe($creator->age());
});

// ---------------------------------------------------------------------------
// 3. Talent pool member row — TalentPoolMemberResource (no age either; this
//    surface is not in the D4 visibility set, so it carries neither field)
// ---------------------------------------------------------------------------

it('TalentPoolMemberResource withholds the birthday', function (): void {
    $creator = dobCreator();

    $attributes = (new TalentPoolMemberResource($creator))->toArray(Request::create('/'))['attributes'];

    expectNoDobKey($attributes);
});

// ---------------------------------------------------------------------------
// 4. Campaign assignment row — CampaignAssignmentResource
// ---------------------------------------------------------------------------

it('CampaignAssignmentResource withholds the birthday (creator block is id + display_name only)', function (): void {
    $creator = dobCreator();
    $assignment = CampaignAssignment::factory()->createOne(['creator_id' => $creator->id]);
    $assignment->setRelation('creator', $creator);

    $attributes = (new CampaignAssignmentResource($assignment))->toArray(Request::create('/'))['attributes'];

    expect($attributes['creator'])->not->toBeNull();
    expectNoDobKey($attributes['creator']);
});

// ---------------------------------------------------------------------------
// 5. Roster LIST row — endpoint-level (the row's `age` derives from a projected
//    column, so the raw date IS hydrated on the model here: exactly the case
//    where an emission slip is easiest)
// ---------------------------------------------------------------------------

it('the roster list row emits age and withholds the birthday', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $creator = dobCreator();
    AgencyCreatorRelation::factory()->create([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => RelationshipStatus::Roster,
        'is_blacklisted' => false,
    ]);

    $response = $this->actingAs($admin)->getJson("/api/v1/agencies/{$agency->ulid}/creators");

    $response->assertOk();
    $row = $response->json('data.0.attributes');
    expectNoDobKey($row);
    expect($row['age'])->toBe($creator->age());
    expectNoDobValue($response->getContent());
});

// ---------------------------------------------------------------------------
// 6. Roster DETAIL — INCLUDING the contact-included full mode. This is THE
//    asymmetry with the contact spine: a rostered, non-blacklisted agency sees
//    the phone number and still never sees the birthday.
// ---------------------------------------------------------------------------

it('the roster detail withholds the birthday even in the contact-included full mode', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $creator = CreatorFactory::new()->withContact()->createOne([AH088_DOB_KEY => AH088_DOB_VALUE]);
    AgencyCreatorRelation::factory()->create([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => RelationshipStatus::Roster,
        'is_blacklisted' => false,
    ]);

    $response = $this->actingAs($admin)
        ->getJson("/api/v1/agencies/{$agency->ulid}/creators/{$creator->ulid}");

    $response->assertOk()
        // The contact gate genuinely passed — this is the full mode, not a
        // withheld one. Without this line the test could pass for the wrong
        // reason (a mode that shows nothing).
        ->assertJsonPath('data.attributes.creator.phone', '+1 555 0100');

    $profile = $response->json('data.attributes.creator');
    expectNoDobKey($profile);
    expect($profile['age'])->toBe($creator->age());
    expectNoDobValue($response->getContent());
});

it('the roster detail withholds the birthday from every non-roster status too', function (RelationshipStatus $status): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $creator = dobCreator();
    AgencyCreatorRelation::factory()->create([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => $status,
        'is_blacklisted' => false,
    ]);

    $response = $this->actingAs($admin)
        ->getJson("/api/v1/agencies/{$agency->ulid}/creators/{$creator->ulid}");

    $response->assertOk();
    expectNoDobKey($response->json('data.attributes.creator'));
    expectNoDobValue($response->getContent());
})->with([
    'pending_request' => [RelationshipStatus::PendingRequest],
    'declined' => [RelationshipStatus::Declined],
    'prospect' => [RelationshipStatus::Prospect],
    'ended' => [RelationshipStatus::Ended],
]);

// ---------------------------------------------------------------------------
// 7. The D1 conditional, BOTH directions — one resource, two audiences, one
//    field whose exposure differs. Neither half is meaningful without the other.
// ---------------------------------------------------------------------------

it('CreatorResource gives the OWNER both the birthday and the age', function (): void {
    $creator = dobCreator();

    $payload = (new CreatorResource($creator, app(CompletenessScoreCalculator::class)))
        ->toArray(Request::create('/'));

    expect($payload['attributes'][AH088_DOB_KEY])->toBe(AH088_DOB_VALUE)
        ->and($payload['attributes']['age'])->toBe($creator->age());
});

it('CreatorResource gives the ADMIN the age and NOT the birthday', function (): void {
    $creator = dobCreator();

    $payload = (new CreatorResource($creator, app(CompletenessScoreCalculator::class)))
        ->withAdmin()
        ->toArray(Request::create('/'));

    expectNoDobKey($payload['attributes']);
    expectNoDobKey($payload['admin_attributes']);
    expect($payload['attributes']['age'])->toBe($creator->age());
});

it('the admin creator drill-in endpoint withholds the birthday', function (): void {
    $admin = User::factory()->create([
        'type' => UserType::PlatformAdmin,
        'two_factor_confirmed_at' => now(),
    ]);
    $creator = dobCreator();

    $response = $this->actingAs($admin, 'web_admin')
        ->getJson("/api/v1/admin/creators/{$creator->ulid}");

    $response->assertOk()->assertJsonPath('data.attributes.age', $creator->age());
    expectNoDobKey($response->json('data.attributes'));
    expectNoDobValue($response->getContent());
});

// ---------------------------------------------------------------------------
// 8. Admin creator INDEX card — a separate hand-built shape from the drill-in,
//    so it needs its own pin.
// ---------------------------------------------------------------------------

it('the admin creator index card withholds the birthday', function (): void {
    $admin = User::factory()->create([
        'type' => UserType::PlatformAdmin,
        'two_factor_confirmed_at' => now(),
    ]);
    dobCreator();

    $response = $this->actingAs($admin, 'web_admin')->getJson('/api/v1/admin/creators');

    $response->assertOk();
    expectNoDobKey($response->json('data.0.attributes'));
    expectNoDobValue($response->getContent());
});

// ---------------------------------------------------------------------------
// 9. Messaging thread list — the value-level check the contact spine uses for
//    this surface (its creator block is assembled per-thread, not by a resource
//    this file can construct).
// ---------------------------------------------------------------------------

it('the agency message-thread list leaks no birthday value', function (): void {
    $agency = Agency::factory()->createOne();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();
    $campaign = Campaign::factory()->createOne(['agency_id' => $agency->id, 'brand_id' => $brand->id]);
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $creatorUser = User::factory()->createOne();
    $creator = dobCreator(['user_id' => $creatorUser->id]);

    $assignment = CampaignAssignment::factory()->createOne([
        'agency_id' => $agency->id,
        'campaign_id' => $campaign->id,
        'brand_id' => $brand->id,
        'creator_id' => $creator->id,
        'invited_by_user_id' => $admin->id,
    ]);

    $this->actingAs($creatorUser)
        ->postJson("/api/v1/creators/me/assignments/{$assignment->ulid}/messages", ['body' => 'hi'])
        ->assertCreated();

    $response = $this->actingAs($admin)
        ->getJson("/api/v1/agencies/{$agency->ulid}/campaigns/{$campaign->ulid}/message-threads");

    $response->assertOk();
    expectNoDobValue($response->getContent());
});

// ---------------------------------------------------------------------------
// 10. The AUDIT trail — not a wire surface, the same class of leak. Audit
//     snapshots are admin-readable, so allowlisting the birthday would hand it
//     to the exact audience D1 withholds it from. Source inspection, the
//     content_companions precedent (ContentCompanionsTest).
// ---------------------------------------------------------------------------

it('date_of_birth is excluded from the audit allowlist by decision, not omission', function (): void {
    $allowlist = (new Creator)->auditableAllowlist();

    expect($allowlist)->not->toContain(AH088_DOB_KEY);

    // And the exclusion is DOCUMENTED where the next reader will look, so
    // "add the new column to the allowlist for completeness" is not a tempting
    // one-line change.
    $source = file_get_contents(app_path('Modules/Creators/Models/Creator.php'));
    expect($source)->toBeString()
        ->and($source)->toContain('date_of_birth        (AH-088');
});
