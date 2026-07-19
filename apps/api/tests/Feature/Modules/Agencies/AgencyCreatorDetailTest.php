<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Database\Factories\CreatorPortfolioItemFactory;
use App\Modules\Creators\Database\Factories\CreatorSocialAccountFactory;
use App\Modules\Creators\Enums\RelationshipStatus;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Sprint 6 Chunk 2a — GET/PATCH /agencies/{agency}/creators/{creator}.
 *
 * The agency-side per-creator detail view (D-2a-1/2) + the rating/notes edit
 * (D-2a-3/4/5). Tenancy mirrors the Chunk-5 roster + Sprint-5 availability:
 * a relation (any status) must exist, else 404 (§5.35 break-revert anchor).
 */
function detailUrl(Agency $agency, Creator $creator): string
{
    return "/api/v1/agencies/{$agency->ulid}/creators/{$creator->ulid}";
}

/** Roster a fresh creator under the agency and return the [creator, relation]. */
function rosterDetailCreator(
    Agency $agency,
    RelationshipStatus $status = RelationshipStatus::Roster,
): Creator {
    $creator = CreatorFactory::new()->createOne();
    AgencyCreatorRelation::factory()->create([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => $status,
    ]);

    return $creator;
}

// ---------------------------------------------------------------------------
// Auth + relation-exists tenancy (break-revert anchor)
// ---------------------------------------------------------------------------

it('returns 401 when unauthenticated', function (): void {
    $agency = Agency::factory()->createOne();
    $creator = rosterDetailCreator($agency);

    expect($this->getJson(detailUrl($agency, $creator))->status())->toBe(401);
});

it('returns 404 for a non-member (tenancy invisibility)', function (): void {
    $agency = Agency::factory()->createOne();
    $creator = rosterDetailCreator($agency);
    $outsider = User::factory()->agencyAdmin(Agency::factory()->createOne())->createOne();

    expect($this->actingAs($outsider)->getJson(detailUrl($agency, $creator))->status())->toBe(404);
});

it('returns 404 when the creator has NO relation with the agency (relation-exists break-revert)', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $stranger = CreatorFactory::new()->createOne();

    expect($this->actingAs($admin)->getJson(detailUrl($agency, $stranger))->status())->toBe(404);
});

it('reads the detail across any relationship status (mirrors roster scope)', function (string $status): void {
    $agency = Agency::factory()->createOne();
    $staff = User::factory()->agencyStaff($agency)->createOne();
    $creator = rosterDetailCreator($agency, RelationshipStatus::from($status));

    expect($this->actingAs($staff)->getJson(detailUrl($agency, $creator))->status())->toBe(200);
})->with(['roster', 'prospect', 'external']);

// ---------------------------------------------------------------------------
// Resource shape — profile + relation + portfolio + social, NO admin KYC PII
// ---------------------------------------------------------------------------

it('carries the creator profile + relation block + portfolio + social accounts', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $creator = CreatorFactory::new()->createOne([
        'display_name' => 'Ada Lovelace',
        'bio' => 'Pioneering mathematician',
        // AH-050 — profile-class, relation-detail-visible like accent.
        'content_companions' => ['partner', 'pets_cats'],
    ]);
    AgencyCreatorRelation::factory()->create([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'internal_rating' => 4,
        'internal_notes' => 'Great to work with',
        'total_campaigns_completed' => 7,
    ]);
    CreatorSocialAccountFactory::new()->for($creator)->create(['handle' => 'ada_l']);
    CreatorPortfolioItemFactory::new()->for($creator)->link()->create(['title' => 'My reel']);

    $response = $this->actingAs($admin)->getJson(detailUrl($agency, $creator));

    $response->assertOk();
    $attrs = $response->json('data.attributes');

    // Relation block.
    expect($attrs['internal_rating'])->toBe(4)
        ->and($attrs['internal_notes'])->toBe('Great to work with')
        ->and($attrs['total_campaigns_completed'])->toBe(7)
        ->and($attrs['relationship_status'])->toBe('roster');

    // Nested creator profile + portfolio + social.
    expect($attrs['creator']['display_name'])->toBe('Ada Lovelace')
        ->and($attrs['creator']['bio'])->toBe('Pioneering mathematician')
        ->and($attrs['creator']['content_companions'])->toBe(['partner', 'pets_cats'])
        ->and($attrs['creator']['social_accounts'])->toHaveCount(1)
        ->and($attrs['creator']['social_accounts'][0]['handle'])->toBe('ada_l')
        ->and($attrs['creator']['portfolio'])->toHaveCount(1)
        ->and($attrs['creator']['portfolio'][0]['title'])->toBe('My reel');
});

it('surfaces the creator contact email (D-2a-8 deliberate privacy decision)', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $user = User::factory()->createOne(['email' => 'ada@example.com']);
    $creator = CreatorFactory::new()->createOne(['user_id' => $user->id]);
    AgencyCreatorRelation::factory()->create([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
    ]);

    $response = $this->actingAs($admin)->getJson(detailUrl($agency, $creator));

    $response->assertOk()
        ->assertJsonPath('data.attributes.creator.email', 'ada@example.com');
});

// ---------------------------------------------------------------------------
// AH-005 — contact details (phone / WhatsApp / mailing address). Load-bearing
// privacy gate: a NON-blacklisted connected agency sees the block; a
// blacklisted-but-rostered agency gets the detail page but ZERO contact data.
// Break-revert: loosen the controller gate to relation-exists → the
// blacklisted-agency-sees-nothing spec below fails → revert.
// ---------------------------------------------------------------------------

it('surfaces the AH-005 contact block to a non-blacklisted connected agency', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $creator = CreatorFactory::new()->withContact()->createOne();
    AgencyCreatorRelation::factory()->create([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'is_blacklisted' => false,
    ]);

    $response = $this->actingAs($admin)->getJson(detailUrl($agency, $creator));

    $response->assertOk()
        ->assertJsonPath('data.attributes.creator.phone', '+1 555 0100')
        ->assertJsonPath('data.attributes.creator.whatsapp', '+1 555 0142')
        ->assertJsonPath('data.attributes.creator.address_street', '12 Market Street')
        ->assertJsonPath('data.attributes.creator.address_postal_code', 'D02 XY45');
});

it('WITHHOLDS the contact block from a blacklisted-but-rostered agency (load-bearing gate)', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $creator = CreatorFactory::new()->withContact()->createOne();
    AgencyCreatorRelation::factory()->blacklisted('Hard ban')->create([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
    ]);

    $response = $this->actingAs($admin)->getJson(detailUrl($agency, $creator));

    // The detail page still renders (the relation exists) …
    $response->assertOk();
    $creatorBlock = $response->json('data.attributes.creator');

    // … but carries NO contact keys at all (withheld by omission) …
    expect($creatorBlock)->not->toHaveKey('phone')
        ->and($creatorBlock)->not->toHaveKey('whatsapp')
        ->and($creatorBlock)->not->toHaveKey('address_street')
        ->and($creatorBlock)->not->toHaveKey('address_postal_code');

    // … and none of the values leak anywhere in the payload.
    $body = $response->getContent();
    expect($body)->not->toContain('+1 555 0100')
        ->and($body)->not->toContain('+1 555 0142')
        ->and($body)->not->toContain('12 Market Street')
        ->and($body)->not->toContain('D02 XY45');
});

it('does NOT carry admin-only KYC PII (no-admin-PII break-revert)', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $creator = rosterDetailCreator($agency);

    $response = $this->actingAs($admin)->getJson(detailUrl($agency, $creator));

    $response->assertOk();
    $body = $response->getContent();

    expect($body)->not->toContain('admin_attributes')
        ->and($body)->not->toContain('kyc_method')
        ->and($body)->not->toContain('verified_by_user_id')
        ->and($body)->not->toContain('kyc_verifications');
});

it('shows blacklist STATUS read-only but WITHHOLDS the free-text reason (D-2a-3/divergence-4)', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $creator = CreatorFactory::new()->createOne();
    AgencyCreatorRelation::factory()->blacklisted('SECRET BLACKLIST JUSTIFICATION')->create([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
    ]);

    $response = $this->actingAs($admin)->getJson(detailUrl($agency, $creator));

    $response->assertOk()
        ->assertJsonPath('data.attributes.is_blacklisted', true)
        ->assertJsonPath('data.attributes.blacklist_scope', 'agency')
        ->assertJsonPath('data.attributes.blacklist_type', 'hard');

    expect($response->getContent())->not->toContain('blacklist_reason')
        ->and($response->getContent())->not->toContain('SECRET BLACKLIST JUSTIFICATION');
});

// ---------------------------------------------------------------------------
// Portfolio download authz (AH-004 sub-step 6): the download_url is NOT a
// separate endpoint — it rides this resource, so it inherits the exact view
// authz (relation-exists tenancy). A caller who can see the roster creator
// receives a ready item's download_url; a non-member who gets 404 on the
// resource never receives a download_url at all (no broader grant than view).
// ---------------------------------------------------------------------------

it('mints a portfolio download_url for a roster member who can view the creator (AH-004 authz inherit)', function (): void {
    $adapter = Mockery::mock(AwsS3V3Adapter::class);
    $adapter->shouldReceive('temporaryUrl')
        ->andReturnUsing(function (string $path, $expiry, array $options = []): string {
            $disposition = isset($options['ResponseContentDisposition']) ? '&cd=1' : '';

            return "https://signed.example/{$path}?sig=test{$disposition}";
        });
    Storage::shouldReceive('disk')->with('media')->andReturn($adapter);

    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $creator = rosterDetailCreator($agency);
    CreatorPortfolioItemFactory::new()->for($creator)->createOne([
        's3_path' => 'creators/01/portfolio/img.jpg',
        'thumbnail_path' => 'creators/01/portfolio/thumbs/img.jpg',
    ]);

    $response = $this->actingAs($admin)->getJson(detailUrl($agency, $creator));

    $response->assertOk();
    $items = $response->json('data.attributes.creator.portfolio');

    expect($items)->toHaveCount(1);
    expect($items[0]['processing_status'])->toBe('ready');
    expect($items[0]['download_url'])->toContain('cd=1');
    expect($items[0]['download_url'])->toContain('creators/01/portfolio/img.jpg');
});

it('denies the portfolio download to a non-member — 404 before any download_url is minted (AH-004 authz break-revert)', function (): void {
    // The download is a presigned GET behind the resource gate. An outsider
    // who fails the relation-exists tenancy gets 404 and never receives the
    // resource body — so a download_url cannot leak as a broader grant.
    $adapter = Mockery::mock(AwsS3V3Adapter::class);
    $adapter->shouldReceive('temporaryUrl')
        ->andReturnUsing(fn (string $path): string => "https://signed.example/{$path}?sig=test");
    Storage::shouldReceive('disk')->with('media')->andReturn($adapter);

    $agency = Agency::factory()->createOne();
    $creator = rosterDetailCreator($agency);
    CreatorPortfolioItemFactory::new()->for($creator)->createOne([
        's3_path' => 'creators/01/portfolio/img.jpg',
        'thumbnail_path' => 'creators/01/portfolio/thumbs/img.jpg',
    ]);
    $outsider = User::factory()->agencyAdmin(Agency::factory()->createOne())->createOne();

    $response = $this->actingAs($outsider)->getJson(detailUrl($agency, $creator));

    $response->assertStatus(404);
    expect($response->getContent())->not->toContain('download_url')
        ->and($response->getContent())->not->toContain('creators/01/portfolio/img.jpg');
});

// ---------------------------------------------------------------------------
// Edit gate (D-2a-4): admin/manager write, staff 403 (break-revert)
// ---------------------------------------------------------------------------

it('lets an admin edit rating + notes', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $creator = rosterDetailCreator($agency);

    $response = $this->actingAs($admin)->patchJson(detailUrl($agency, $creator), [
        'internal_rating' => 5,
        'internal_notes' => 'Top performer',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.attributes.internal_rating', 5)
        ->assertJsonPath('data.attributes.internal_notes', 'Top performer');

    $this->assertDatabaseHas('agency_creator_relations', [
        'creator_id' => $creator->id,
        'internal_rating' => 5,
        'internal_notes' => 'Top performer',
    ]);
});

it('lets a manager edit rating + notes', function (): void {
    $agency = Agency::factory()->createOne();
    $manager = User::factory()->agencyManager($agency)->createOne();
    $creator = rosterDetailCreator($agency);

    $this->actingAs($manager)->patchJson(detailUrl($agency, $creator), [
        'internal_rating' => 3,
    ])->assertOk();
});

it('forbids a staff member from editing (policy gate break-revert)', function (): void {
    $agency = Agency::factory()->createOne();
    $staff = User::factory()->agencyStaff($agency)->createOne();
    $creator = rosterDetailCreator($agency);

    $this->actingAs($staff)->patchJson(detailUrl($agency, $creator), [
        'internal_rating' => 2,
    ])->assertForbidden();
});

// ---------------------------------------------------------------------------
// Scope guard (D-2a-3): ONLY rating + notes mutable (break-revert)
// ---------------------------------------------------------------------------

it('ignores any field other than rating + notes (scope-guard break-revert)', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $creator = CreatorFactory::new()->createOne();
    AgencyCreatorRelation::factory()->create([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'is_blacklisted' => false,
        'total_campaigns_completed' => 0,
        'relationship_status' => RelationshipStatus::Roster,
    ]);

    $this->actingAs($admin)->patchJson(detailUrl($agency, $creator), [
        'internal_rating' => 4,
        // Stray non-editable fields — must NOT mutate the relation.
        'is_blacklisted' => true,
        'total_campaigns_completed' => 999,
        'relationship_status' => 'external',
    ])->assertOk();

    $this->assertDatabaseHas('agency_creator_relations', [
        'creator_id' => $creator->id,
        'internal_rating' => 4,
        'is_blacklisted' => false,
        'total_campaigns_completed' => 0,
        'relationship_status' => RelationshipStatus::Roster->value,
    ]);
});

it('rejects an out-of-range rating', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $creator = rosterDetailCreator($agency);

    $this->actingAs($admin)->patchJson(detailUrl($agency, $creator), [
        'internal_rating' => 6,
    ])->assertStatus(422);
});

// ---------------------------------------------------------------------------
// Audit (D-2a-5): rating diff allowlisted; notes redacted event
// ---------------------------------------------------------------------------

it('emits the trait before/after diff for a rating edit', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $creator = CreatorFactory::new()->createOne();
    AgencyCreatorRelation::factory()->create([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'internal_rating' => 2,
    ]);

    $this->actingAs($admin)->patchJson(detailUrl($agency, $creator), [
        'internal_rating' => 5,
    ])->assertOk();

    $this->assertDatabaseHas('audit_logs', [
        'action' => AuditAction::AgencyCreatorRelationUpdated->value,
    ]);

    $before = (string) DB::table('audit_logs')
        ->where('action', AuditAction::AgencyCreatorRelationUpdated->value)
        ->latest('id')
        ->value('before');
    $after = (string) DB::table('audit_logs')
        ->where('action', AuditAction::AgencyCreatorRelationUpdated->value)
        ->latest('id')
        ->value('after');

    expect($before)->toContain('"internal_rating":2')
        ->and($after)->toContain('"internal_rating":5');
});

it('emits a REDACTED notes event with NO content when notes change (break-revert)', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $creator = CreatorFactory::new()->createOne();
    AgencyCreatorRelation::factory()->create([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'internal_notes' => null,
    ]);

    $this->actingAs($admin)->patchJson(detailUrl($agency, $creator), [
        'internal_notes' => 'CONFIDENTIAL NOTE CONTENT',
    ])->assertOk();

    // The redacted event row exists, attributed to the acting admin, with NO
    // before/after content (the redaction).
    $this->assertDatabaseHas('audit_logs', [
        'action' => AuditAction::AgencyCreatorRelationNotesUpdated->value,
        'actor_id' => $admin->id,
        'subject_type' => (new AgencyCreatorRelation)->getMorphClass(),
        'before' => null,
        'after' => null,
    ]);

    // The notes text must appear NOWHERE on the row (break-revert: the redaction).
    foreach (['before', 'after', 'metadata'] as $column) {
        $value = (string) DB::table('audit_logs')
            ->where('action', AuditAction::AgencyCreatorRelationNotesUpdated->value)
            ->latest('id')
            ->value($column);
        expect($value)->not->toContain('CONFIDENTIAL NOTE CONTENT');
    }
});

it('does NOT emit a notes event on a rating-only edit (the pin)', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $creator = CreatorFactory::new()->createOne();
    AgencyCreatorRelation::factory()->create([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'internal_rating' => 1,
        'internal_notes' => 'Existing note',
    ]);

    // Re-send the SAME notes value alongside a rating change — notes did not
    // actually change, so no spurious notes event must fire.
    $this->actingAs($admin)->patchJson(detailUrl($agency, $creator), [
        'internal_rating' => 4,
        'internal_notes' => 'Existing note',
    ])->assertOk();

    $this->assertDatabaseMissing('audit_logs', [
        'action' => AuditAction::AgencyCreatorRelationNotesUpdated->value,
    ]);
});
