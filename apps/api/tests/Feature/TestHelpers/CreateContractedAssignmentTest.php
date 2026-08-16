<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Boards\Models\Board;
use App\Modules\Boards\Models\BoardCard;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Enums\ApplicationStatus;
use App\Modules\Creators\Enums\RelationshipStatus;
use App\Modules\Identity\Models\User;
use App\TestHelpers\Http\Middleware\VerifyTestHelperToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The AH-069 (D9) contracted-assignment helper.
 *
 * Two properties carry the E2E leg, and drifting on either would leave the spec
 * green while quietly deleting a step:
 *
 *   - the assignment is CONTRACTED (the last state before a draft), and
 *   - the posting toggle is ON, because turning it off through the real
 *     Settings switch is the leg's first step.
 */
uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withHeader(VerifyTestHelperToken::HEADER, (string) config('test_helpers.token'));
});

function contractedAssignmentCreator(string $email = 'handoff-creator@example.com'): array
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

it('seeds a CONTRACTED assignment on the given agency', function (): void {
    [$creator, $agency] = contractedAssignmentCreator();

    $response = $this->postJson("/api/v1/_test/agencies/{$agency->ulid}/contracted-assignment", [
        'email' => 'handoff-creator@example.com',
        'campaign_name' => 'Spring lookbook',
        'brand_name' => 'Halden Studio',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.agency_ulid', $agency->ulid)
        ->assertJsonPath('data.campaign_name', 'Spring lookbook')
        ->assertJsonPath('data.brand_name', 'Halden Studio')
        ->assertJsonPath('data.creator_ulid', $creator->ulid);

    /** @var CampaignAssignment $assignment */
    $assignment = CampaignAssignment::query()
        ->where('ulid', (string) $response->json('data.assignment_ulid'))
        ->firstOrFail();

    expect($assignment->status)->toBe(AssignmentStatus::Contracted)
        ->and($assignment->agency_id)->toBe($agency->id)
        ->and($assignment->creator_id)->toBe($creator->id);
});

it('leaves the posting toggle ON — flipping it is the step under test', function (): void {
    [, $agency] = contractedAssignmentCreator();

    $response = $this->postJson("/api/v1/_test/agencies/{$agency->ulid}/contracted-assignment", [
        'email' => 'handoff-creator@example.com',
    ]);

    /** @var Campaign $campaign */
    $campaign = Campaign::query()
        ->where('ulid', (string) $response->json('data.campaign_ulid'))
        ->firstOrFail();

    expect($campaign->creator_posts_content)->toBeTrue()
        // …and no contract gate, so the draft form is reachable without one.
        ->and($campaign->requires_per_campaign_contract)->toBeFalse();
});

it('provisions the board and heals the card onto a column', function (): void {
    [, $agency] = contractedAssignmentCreator();

    $response = $this->postJson("/api/v1/_test/agencies/{$agency->ulid}/contracted-assignment", [
        'email' => 'handoff-creator@example.com',
    ]);

    /** @var Campaign $campaign */
    $campaign = Campaign::query()
        ->where('ulid', (string) $response->json('data.campaign_ulid'))
        ->firstOrFail();

    /** @var Board $board */
    $board = Board::query()->where('campaign_id', $campaign->id)->firstOrFail();

    $card = BoardCard::query()->where('board_id', $board->id)->firstOrFail();

    // The card exists and is on a column BEFORE the spec looks, so an assertion
    // about which columns render is not racing the lazy provisioner.
    expect($card->column_id)->not->toBeNull();
});

it('approves the creator and rosters them against THAT agency', function (): void {
    [$creator, $agency] = contractedAssignmentCreator();

    $this->postJson("/api/v1/_test/agencies/{$agency->ulid}/contracted-assignment", [
        'email' => 'handoff-creator@example.com',
    ])->assertStatus(201);

    expect($creator->refresh()->application_status)->toBe(ApplicationStatus::Approved);

    /** @var AgencyCreatorRelation $relation */
    $relation = AgencyCreatorRelation::query()
        ->where('agency_id', $agency->id)
        ->where('creator_id', $creator->id)
        ->firstOrFail();

    expect($relation->relationship_status)->toBe(RelationshipStatus::Roster)
        ->and($relation->is_blacklisted)->toBeFalse();
});

it('422s when no creator is associated with the email', function (): void {
    [, $agency] = contractedAssignmentCreator();

    $this->postJson("/api/v1/_test/agencies/{$agency->ulid}/contracted-assignment", [
        'email' => 'nobody@example.com',
    ])->assertStatus(422)->assertJsonPath('error', 'creator not found');
});

it('422s when the email is missing', function (): void {
    [, $agency] = contractedAssignmentCreator();

    $this->postJson("/api/v1/_test/agencies/{$agency->ulid}/contracted-assignment", [])
        ->assertStatus(422);
});

it('returns 404 when the helper gate is closed (no token header)', function (): void {
    [, $agency] = contractedAssignmentCreator();

    $this->withoutHeader(VerifyTestHelperToken::HEADER)
        ->postJson("/api/v1/_test/agencies/{$agency->ulid}/contracted-assignment", [
            'email' => 'handoff-creator@example.com',
        ])
        ->assertStatus(404);
});
