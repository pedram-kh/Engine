<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Enums\ApplicationStatus;
use App\Modules\Creators\Enums\RelationshipStatus;
use App\Modules\Identity\Models\User;
use App\TestHelpers\Http\Middleware\VerifyTestHelperToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withHeader(VerifyTestHelperToken::HEADER, (string) config('test_helpers.token'));
});

it('approves the creator + seeds a pending_request relation in one call', function (): void {
    $user = User::factory()->create(['email' => 'inbox-creator@example.com']);
    $creator = CreatorFactory::new()->createOne([
        'user_id' => $user->id,
        'application_status' => ApplicationStatus::Incomplete,
    ]);

    $response = $this->postJson('/api/v1/_test/creators/pending-connection-request', [
        'email' => 'inbox-creator@example.com',
        'agency_name' => 'Lumen Talent',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.agency_name', 'Lumen Talent')
        ->assertJsonPath('data.creator_ulid', $creator->ulid);

    // The creator was flipped to approved (the inbox-bearing branch).
    expect($creator->refresh()->application_status)->toBe(ApplicationStatus::Approved)
        ->and($creator->approved_at)->not->toBeNull();

    /** @var Agency $agency */
    $agency = Agency::query()->where('name', 'Lumen Talent')->firstOrFail();

    /** @var AgencyCreatorRelation $relation */
    $relation = AgencyCreatorRelation::query()
        ->where('agency_id', $agency->id)
        ->where('creator_id', $creator->id)
        ->firstOrFail();

    expect($relation->relationship_status)->toBe(RelationshipStatus::PendingRequest)
        ->and($relation->invitation_sent_at)->not->toBeNull()
        ->and($response->json('data.relation_ulid'))->toBe($relation->ulid)
        ->and($response->json('data.agency_ulid'))->toBe($agency->ulid);
});

it('defaults the agency name when omitted', function (): void {
    $user = User::factory()->create(['email' => 'inbox-default@example.com']);
    CreatorFactory::new()->createOne(['user_id' => $user->id]);

    $response = $this->postJson('/api/v1/_test/creators/pending-connection-request', [
        'email' => 'inbox-default@example.com',
    ]);

    $response->assertStatus(201);
    $agencyName = $response->json('data.agency_name');
    expect($agencyName)->toBeString();
    expect($agencyName)->not->toBeEmpty();
});

it('422s when no creator is associated with the email', function (): void {
    $this->postJson('/api/v1/_test/creators/pending-connection-request', [
        'email' => 'nobody@example.com',
    ])->assertStatus(422)->assertJsonPath('error', 'creator not found');
});

it('422s when the email is missing', function (): void {
    $this->postJson('/api/v1/_test/creators/pending-connection-request', [])
        ->assertStatus(422);
});

it('returns 404 when the helper gate is closed (no token header)', function (): void {
    $this->withoutHeader(VerifyTestHelperToken::HEADER)
        ->postJson('/api/v1/_test/creators/pending-connection-request', [
            'email' => 'gated@example.com',
        ])
        ->assertStatus(404);
});
