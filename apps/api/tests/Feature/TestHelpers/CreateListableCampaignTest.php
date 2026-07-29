<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Campaigns\Enums\CampaignStatus;
use App\Modules\Campaigns\Http\Requests\UpdateCampaignRequest;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Enums\ApplicationStatus;
use App\Modules\Creators\Enums\RelationshipStatus;
use App\Modules\Identity\Models\User;
use App\TestHelpers\Http\Middleware\VerifyTestHelperToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The AH-059 (D6) listable-campaign helper.
 *
 * The helper's whole value is a state no other helper produces — floor-COMPLETE
 * but UNLISTED — so the two halves of that state are what this file pins. A
 * helper that drifted into pre-listing the campaign would delete the first step
 * of the full-lifecycle spec while leaving it green, which is the failure mode
 * worth a test of its own.
 */
uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withHeader(VerifyTestHelperToken::HEADER, (string) config('test_helpers.token'));
});

function listableCampaignCreator(string $email = 'loop-creator@example.com'): array
{
    $user = User::factory()->create(['email' => $email]);
    $creator = CreatorFactory::new()->createOne([
        'user_id' => $user->id,
        'application_status' => ApplicationStatus::Incomplete,
    ]);

    /** @var Agency $agency */
    $agency = Agency::factory()->create(['name' => 'Lumen Talent']);

    return [$creator, $agency];
}

it('seeds an UNLISTED campaign on the given agency, with the listing floor complete', function (): void {
    [$creator, $agency] = listableCampaignCreator();

    $response = $this->postJson("/api/v1/_test/agencies/{$agency->ulid}/listable-campaign", [
        'email' => 'loop-creator@example.com',
        'campaign_name' => 'Winter UGC push',
        'brand_name' => 'Northwind Coffee',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.agency_ulid', $agency->ulid)
        ->assertJsonPath('data.campaign_name', 'Winter UGC push')
        ->assertJsonPath('data.brand_name', 'Northwind Coffee')
        ->assertJsonPath('data.creator_ulid', $creator->ulid);

    /** @var Campaign $campaign */
    $campaign = Campaign::query()
        ->where('ulid', (string) $response->json('data.campaign_ulid'))
        ->firstOrFail();

    // Not listed, and not stamped: the spec's own toggle does both, and a
    // pre-stamped `listed_at` would hide a regression in the flip detector.
    expect($campaign->agency_id)->toBe($agency->id)
        ->and($campaign->listed_on_jobs_board)->toBeFalse()
        ->and($campaign->listed_at)->toBeNull()
        ->and($campaign->status)->toBe(CampaignStatus::Active)
        ->and($campaign->ends_at)->toBeNull();

    // …but every floor field is filled, so the toggle is accepted rather than
    // refused on five field errors. Read through the request that USES the
    // trait (PHP forbids reading a trait constant off the trait name) so a new
    // floor field cannot be added without this helper being updated.
    foreach (UpdateCampaignRequest::LISTING_FLOOR_FIELDS as $field) {
        $value = $campaign->{$field};
        expect(is_array($value) ? $value !== [] : trim((string) $value) !== '')->toBeTrue(
            "The floor field {$field} must be filled for the campaign to be listable.",
        );
    }
});

it('forces requires_per_campaign_contract off so the offer-accept is reachable', function (): void {
    [, $agency] = listableCampaignCreator();

    $response = $this->postJson("/api/v1/_test/agencies/{$agency->ulid}/listable-campaign", [
        'email' => 'loop-creator@example.com',
    ]);

    /** @var Campaign $campaign */
    $campaign = Campaign::query()
        ->where('ulid', (string) $response->json('data.campaign_ulid'))
        ->firstOrFail();

    expect($campaign->requires_per_campaign_contract)->toBeFalse();
});

it('approves the creator and rosters them against THAT agency', function (): void {
    [$creator, $agency] = listableCampaignCreator();

    $this->postJson("/api/v1/_test/agencies/{$agency->ulid}/listable-campaign", [
        'email' => 'loop-creator@example.com',
    ])->assertStatus(201);

    expect($creator->refresh()->application_status)->toBe(ApplicationStatus::Approved)
        ->and($creator->approved_at)->not->toBeNull();

    /** @var AgencyCreatorRelation $relation */
    $relation = AgencyCreatorRelation::query()
        ->where('agency_id', $agency->id)
        ->where('creator_id', $creator->id)
        ->firstOrFail();

    expect($relation->relationship_status)->toBe(RelationshipStatus::Roster)
        ->and($relation->is_blacklisted)->toBeFalse();
});

it('leaves no application, assignment or board card behind — that is the loop', function (): void {
    [, $agency] = listableCampaignCreator();

    $this->postJson("/api/v1/_test/agencies/{$agency->ulid}/listable-campaign", [
        'email' => 'loop-creator@example.com',
    ])->assertStatus(201);

    $this->assertDatabaseCount('campaign_applications', 0);
    $this->assertDatabaseCount('campaign_assignments', 0);
    $this->assertDatabaseCount('board_cards', 0);
});

it('is idempotent on the roster relation when called twice for the same pair', function (): void {
    [$creator, $agency] = listableCampaignCreator();

    $this->postJson("/api/v1/_test/agencies/{$agency->ulid}/listable-campaign", [
        'email' => 'loop-creator@example.com',
    ])->assertStatus(201);
    $this->postJson("/api/v1/_test/agencies/{$agency->ulid}/listable-campaign", [
        'email' => 'loop-creator@example.com',
    ])->assertStatus(201);

    expect(AgencyCreatorRelation::query()
        ->where('agency_id', $agency->id)
        ->where('creator_id', $creator->id)
        ->count())->toBe(1);
});

it('422s when no creator is associated with the email', function (): void {
    [, $agency] = listableCampaignCreator();

    $this->postJson("/api/v1/_test/agencies/{$agency->ulid}/listable-campaign", [
        'email' => 'nobody@example.com',
    ])->assertStatus(422)->assertJsonPath('error', 'creator not found');
});

it('422s when the email is missing', function (): void {
    [, $agency] = listableCampaignCreator();

    $this->postJson("/api/v1/_test/agencies/{$agency->ulid}/listable-campaign", [])
        ->assertStatus(422);
});

it('returns 404 when the helper gate is closed (no token header)', function (): void {
    [, $agency] = listableCampaignCreator();

    $this->withoutHeader(VerifyTestHelperToken::HEADER)
        ->postJson("/api/v1/_test/agencies/{$agency->ulid}/listable-campaign", [
            'email' => 'loop-creator@example.com',
        ])
        ->assertStatus(404);
});
