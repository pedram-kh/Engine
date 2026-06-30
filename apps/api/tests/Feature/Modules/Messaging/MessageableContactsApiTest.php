<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Enums\RelationshipStatus;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * AH-012 (D4) — the two gate-filtered contact-picker endpoints behind the
 * WhatsApp-style new-conversation flow. They surface ONLY contacts the messaging
 * gate permits (the agreement is pinned separately in
 * MessageableContactsAgreementTest); here we assert the HTTP shape, the search +
 * pagination (agency side), and that non-messageable relations never leak.
 */
function relatedCreator(Agency $agency, RelationshipStatus $status, bool $approved, bool $blacklisted, ?string $name = null): Creator
{
    $factory = CreatorFactory::new();
    if ($approved) {
        $factory = $factory->approved();
    }
    $attrs = $name !== null ? ['display_name' => $name] : [];
    $creator = $factory->createOne($attrs);

    AgencyCreatorRelation::factory()->createOne([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => $status,
        'is_blacklisted' => $blacklisted,
    ]);

    return $creator;
}

// ── Agency picker: GET /agencies/{agency}/messageable-creators ───────────────

it('the agency picker lists only messageable creators (roster + approved + non-blacklisted)', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $messageable = relatedCreator($agency, RelationshipStatus::Roster, approved: true, blacklisted: false, name: 'Ada Lovelace');
    relatedCreator($agency, RelationshipStatus::Roster, approved: true, blacklisted: true);   // blacklisted
    relatedCreator($agency, RelationshipStatus::Prospect, approved: true, blacklisted: false); // not roster
    relatedCreator($agency, RelationshipStatus::Roster, approved: false, blacklisted: false);  // not approved

    $this->actingAs($admin)->getJson("/api/v1/agencies/{$agency->ulid}/messageable-creators")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', 'messageable_creator')
        ->assertJsonPath('data.0.id', $messageable->ulid)
        ->assertJsonPath('data.0.attributes.display_name', 'Ada Lovelace')
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('meta.page', 1);
});

it('the agency picker filters by a case-insensitive display_name substring', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $ada = relatedCreator($agency, RelationshipStatus::Roster, approved: true, blacklisted: false, name: 'Ada Lovelace');
    relatedCreator($agency, RelationshipStatus::Roster, approved: true, blacklisted: false, name: 'Grace Hopper');

    $this->actingAs($admin)->getJson("/api/v1/agencies/{$agency->ulid}/messageable-creators?search=ADA")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $ada->ulid);
});

it('the agency picker paginates', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    foreach (range(1, 3) as $i) {
        relatedCreator($agency, RelationshipStatus::Roster, approved: true, blacklisted: false, name: "Creator {$i}");
    }

    $this->actingAs($admin)->getJson("/api/v1/agencies/{$agency->ulid}/messageable-creators?per_page=2&page=1")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.last_page', 2);

    $this->actingAs($admin)->getJson("/api/v1/agencies/{$agency->ulid}/messageable-creators?per_page=2&page=2")
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

// ── Creator picker: GET /creators/me/messageable-agencies ────────────────────

it('the creator picker lists only messageable agencies (roster + non-blacklisted)', function (): void {
    $creatorUser = User::factory()->createOne();
    $creator = CreatorFactory::new()->approved()->createOne(['user_id' => $creatorUser->id]);

    $messageable = Agency::factory()->createOne(['name' => 'Bright Talent']);
    AgencyCreatorRelation::factory()->createOne([
        'agency_id' => $messageable->id,
        'creator_id' => $creator->id,
        'relationship_status' => RelationshipStatus::Roster,
        'is_blacklisted' => false,
    ]);

    $declined = Agency::factory()->createOne();
    AgencyCreatorRelation::factory()->createOne([
        'agency_id' => $declined->id,
        'creator_id' => $creator->id,
        'relationship_status' => RelationshipStatus::Declined,
        'is_blacklisted' => false,
    ]);

    $blacklisted = Agency::factory()->createOne();
    AgencyCreatorRelation::factory()->createOne([
        'agency_id' => $blacklisted->id,
        'creator_id' => $creator->id,
        'relationship_status' => RelationshipStatus::Roster,
        'is_blacklisted' => true,
    ]);

    $this->actingAs($creatorUser)->getJson('/api/v1/creators/me/messageable-agencies')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', 'messageable_agency')
        ->assertJsonPath('data.0.id', $messageable->ulid)
        ->assertJsonPath('data.0.attributes.name', 'Bright Talent');
});

it('the creator picker carries the agency logo for the contact-row avatar (AH-013 logo_url)', function (): void {
    $creatorUser = User::factory()->createOne();
    $creator = CreatorFactory::new()->approved()->createOne(['user_id' => $creatorUser->id]);

    $agency = Agency::factory()->createOne(['logo_path' => 'https://cdn.example.com/logo.png']);
    AgencyCreatorRelation::factory()->createOne([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => RelationshipStatus::Roster,
        'is_blacklisted' => false,
    ]);

    $this->actingAs($creatorUser)->getJson('/api/v1/creators/me/messageable-agencies')
        ->assertOk()
        ->assertJsonPath('data.0.attributes.logo_path', 'https://cdn.example.com/logo.png')
        // AH-013 — an absolute logo URL resolves through verbatim.
        ->assertJsonPath('data.0.attributes.logo_url', 'https://cdn.example.com/logo.png');
});

it('the agency picker carries the creator avatar_url for the contact-row avatar (AH-013)', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $creator = relatedCreator($agency, RelationshipStatus::Roster, approved: true, blacklisted: false, name: 'Ada Lovelace');
    // An absolute avatar reference resolves through verbatim (a bare S3 key would
    // be signed; null on a non-S3 test disk). Pins the field is wired per-row.
    $creator->forceFill(['avatar_path' => 'https://cdn.example.com/ada.png'])->save();

    $this->actingAs($admin)->getJson("/api/v1/agencies/{$agency->ulid}/messageable-creators")
        ->assertOk()
        ->assertJsonPath('data.0.id', $creator->ulid)
        ->assertJsonPath('data.0.attributes.avatar_url', 'https://cdn.example.com/ada.png');
});
