<?php

declare(strict_types=1);

use App\Modules\Agencies\Database\Factories\AgencyFactory;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Enums\RelationshipStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Pushback per kickoff Refinements §2 — preview endpoint shape
|--------------------------------------------------------------------------
|
| Standing standard #42: the unauthenticated preview MUST NOT expose
| the invitee's email. Response shape is restricted to
| {agency_name, is_expired, is_accepted}.
|
| Source-inspection regression test (#1) below pins the response shape
| as a strict equality so any future contributor adding `email` (or any
| other field) immediately breaks the test.
*/

it('returns the agency context with no email exposure (#42)', function (): void {
    $agency = AgencyFactory::new()->createOne(['name' => 'Catalyst HQ']);
    $creator = CreatorFactory::new()->createOne();

    $token = 'demo-token-1234567890abcdef';
    $hash = hash('sha256', $token);

    AgencyCreatorRelation::create([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => RelationshipStatus::Prospect->value,
        'invitation_token_hash' => $hash,
        'invitation_expires_at' => now()->addDays(7),
        'invitation_sent_at' => now(),
    ]);

    $response = $this->getJson('/api/v1/creators/invitations/preview?token='.urlencode($token));

    $response->assertOk()
        ->assertExactJson([
            'data' => [
                'agency_name' => 'Catalyst HQ',
                'is_expired' => false,
                'is_accepted' => false,
            ],
        ]);
});

it('returns 404 generic when the token is unknown', function (): void {
    $this->getJson('/api/v1/creators/invitations/preview?token=nope-not-a-real-token')
        ->assertNotFound()
        ->assertJsonPath('errors.0.code', 'invitation.not_found');
});

it('reports is_expired = true once past expiry', function (): void {
    $agency = AgencyFactory::new()->createOne();
    $creator = CreatorFactory::new()->createOne();
    $token = 'expired-token-deadbeefdeadbeef';

    AgencyCreatorRelation::create([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => RelationshipStatus::Prospect->value,
        'invitation_token_hash' => hash('sha256', $token),
        'invitation_expires_at' => now()->subDay(),
        'invitation_sent_at' => now()->subDays(8),
    ]);

    $this->getJson('/api/v1/creators/invitations/preview?token='.urlencode($token))
        ->assertOk()
        ->assertJsonPath('data.is_expired', true);
});

it('reports is_accepted = true once the relation is on roster', function (): void {
    $agency = AgencyFactory::new()->createOne();
    $creator = CreatorFactory::new()->createOne();
    $token = 'accepted-token-cafef00dcafef00d';

    AgencyCreatorRelation::create([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => RelationshipStatus::Roster->value,
        'invitation_token_hash' => hash('sha256', $token),
        'invitation_expires_at' => now()->addDay(),
        'invitation_sent_at' => now()->subDay(),
    ]);

    $this->getJson('/api/v1/creators/invitations/preview?token='.urlencode($token))
        ->assertOk()
        ->assertJsonPath('data.is_accepted', true);
});
