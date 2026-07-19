<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Creators\Database\Factories\CreatorPortfolioItemFactory;
use App\Modules\Creators\Database\Factories\CreatorSocialAccountFactory;
use App\Modules\Creators\Enums\ApplicationStatus;
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
 * Sprint 6.6a — the creator discovery read path (the global pool).
 *
 *   GET /agencies/{agency}/creators/discover            — browse/search the pool
 *   GET /agencies/{agency}/creators/discover/{creator}  — view a public profile
 *
 * The first agency-facing creator query that is NOT relation-scoped (D-1). Pins:
 * the fail-closed discoverable gate (D-2), the public-resource privacy delta
 * (D-5), the no-404-on-no-relation detail (D-6), the calling-agency-scoped
 * "already-connected" annotation (D-4), and — the load-bearing pin — the
 * cross-agency isolation invariant (D-7).
 */
function discoverUrl(Agency $agency, string $query = ''): string
{
    return "/api/v1/agencies/{$agency->ulid}/creators/discover".($query === '' ? '' : "?{$query}");
}

function publicProfileUrl(Agency $agency, Creator $creator): string
{
    return "/api/v1/agencies/{$agency->ulid}/creators/discover/{$creator->ulid}";
}

/** An approved + discoverable creator (passes the gate). */
function discoverableCreator(array $attributes = []): Creator
{
    return Creator::factory()->approved()->createOne($attributes);
}

// ---------------------------------------------------------------------------
// Auth + tenancy + the `discover` ability
// ---------------------------------------------------------------------------

it('returns 401 when unauthenticated (list + detail)', function (): void {
    $agency = Agency::factory()->createOne();
    $creator = discoverableCreator();

    expect($this->getJson(discoverUrl($agency))->status())->toBe(401);
    expect($this->getJson(publicProfileUrl($agency, $creator))->status())->toBe(401);
});

it('returns 404 for a non-member (tenancy invisibility)', function (): void {
    $agency = Agency::factory()->createOne();
    $creator = discoverableCreator();
    $outsider = User::factory()->agencyAdmin(Agency::factory()->createOne())->createOne();

    expect($this->actingAs($outsider)->getJson(discoverUrl($agency))->status())->toBe(404);
    expect($this->actingAs($outsider)->getJson(publicProfileUrl($agency, $creator))->status())->toBe(404);
});

it('lets ANY agency member discover (staff included — the discover ability is any-member)', function (): void {
    $agency = Agency::factory()->createOne();
    $staff = User::factory()->agencyStaff($agency)->createOne();
    discoverableCreator();

    $response = $this->actingAs($staff)->getJson(discoverUrl($agency));

    expect($response->status())->toBe(200);
    expect($response->json('meta.total'))->toBe(1);
});

// ---------------------------------------------------------------------------
// The fail-closed discoverable gate (D-2) — WHITELIST: approved + discoverable
// ---------------------------------------------------------------------------

it('lists an approved + discoverable creator', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    discoverableCreator(['display_name' => 'Visible Vera']);

    $response = $this->actingAs($admin)->getJson(discoverUrl($agency));

    expect($response->json('meta.total'))->toBe(1);
    expect($response->json('data.0.attributes.display_name'))->toBe('Visible Vera');
});

it('EXCLUDES non-approved creators (pending / incomplete / rejected — fail-closed break-revert)', function (): void {
    // Break-revert (§5.35): relax the gate to drop the application_status leg
    // and these non-approved creators leak into the pool — failing this.
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    Creator::factory()->createOne(['display_name' => 'Inny Incomplete']); // default: incomplete
    Creator::factory()->submitted()->createOne(['display_name' => 'Penny Pending']);
    Creator::factory()->createOne([
        'display_name' => 'Reggie Rejected',
        'application_status' => ApplicationStatus::Rejected,
    ]);
    discoverableCreator(['display_name' => 'Amy Approved']);

    $response = $this->actingAs($admin)->getJson(discoverUrl($agency));

    expect($response->json('meta.total'))->toBe(1);
    expect($response->json('data.0.attributes.display_name'))->toBe('Amy Approved');
});

it('EXCLUDES an approved creator who opted out (is_discoverable = false — the future opt-out works)', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    Creator::factory()->approved()->notDiscoverable()->createOne(['display_name' => 'Hidden Hank']);
    discoverableCreator(['display_name' => 'Open Olive']);

    $response = $this->actingAs($admin)->getJson(discoverUrl($agency));

    expect($response->json('meta.total'))->toBe(1);
    expect($response->json('data.0.attributes.display_name'))->toBe('Open Olive');
});

it('EXCLUDES soft-deleted creators (the implicit global scope)', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    discoverableCreator(['display_name' => 'Alive Al']);
    $deleted = discoverableCreator(['display_name' => 'Gone Gus']);
    $deleted->delete();

    $response = $this->actingAs($admin)->getJson(discoverUrl($agency));

    expect($response->json('meta.total'))->toBe(1);
    expect($response->json('data.0.attributes.display_name'))->toBe('Alive Al');
});

// ---------------------------------------------------------------------------
// The public LIST card shape (D-5/D-10) — public facts, single avatar, NO leak
// ---------------------------------------------------------------------------

it('exposes the slim card shape and carries NONE of the relation block', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $creator = discoverableCreator([
        'display_name' => 'Carl Card',
        'country_code' => 'PT',
        'primary_language' => 'pt',
        'categories' => ['fitness'],
    ]);
    // This agency's own private relation data — must NEVER reach the card.
    AgencyCreatorRelation::factory()->create([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => RelationshipStatus::Roster,
        'internal_notes' => 'MY PRIVATE NOTE',
        'internal_rating' => 5,
        'total_campaigns_completed' => 9,
    ]);

    $response = $this->actingAs($admin)->getJson(discoverUrl($agency));

    $attrs = $response->json('data.0.attributes');
    expect(array_keys($attrs))->toEqualCanonicalizing([
        'display_name', 'country_code', 'primary_language', 'accent',
        'content_companions', 'categories', 'avatar_url', 'relationship_status',
    ]);

    // The per-agency relation block is absent (only the caller's own status
    // annotation survives, tested below).
    $body = $response->getContent();
    expect($body)->not->toContain('internal_notes')
        ->and($body)->not->toContain('MY PRIVATE NOTE')
        ->and($body)->not->toContain('internal_rating')
        ->and($body)->not->toContain('total_campaigns_completed')
        ->and($body)->not->toContain('is_blacklisted');
});

// ---------------------------------------------------------------------------
// The public PROFILE detail (D-5/D-6) — public profile, NO relation/email/KYC
// ---------------------------------------------------------------------------

it('does NOT 404 the public detail for an agency with NO relation (D-6 break-revert)', function (): void {
    // Break-revert (§5.35): copying the 2a relation-exists 404 gate here would
    // make this 404 — the public profile MUST be visible without a relation.
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $creator = discoverableCreator(['display_name' => 'Solo Sal']);

    $response = $this->actingAs($admin)->getJson(publicProfileUrl($agency, $creator));

    $response->assertOk()
        ->assertJsonPath('data.attributes.display_name', 'Solo Sal')
        ->assertJsonPath('data.attributes.relationship_status', null);
});

it('404s the public detail for a non-discoverable / non-approved creator (fail-closed, not probeable)', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $optedOut = Creator::factory()->approved()->notDiscoverable()->createOne();
    $pending = Creator::factory()->submitted()->createOne();

    expect($this->actingAs($admin)->getJson(publicProfileUrl($agency, $optedOut))->status())->toBe(404);
    expect($this->actingAs($admin)->getJson(publicProfileUrl($agency, $pending))->status())->toBe(404);
});

it('carries the public profile (bio, region, languages, categories, social, portfolio, completeness)', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $creator = discoverableCreator([
        'display_name' => 'Profile Pat',
        'bio' => 'Public bio text',
        'region' => 'Lisbon',
        'primary_language' => 'pt',
        'secondary_languages' => ['en'],
        'categories' => ['travel', 'food'],
        // AH-050 — discover-visible by design (profile-class data).
        'content_companions' => ['partner', 'pets_dogs'],
        'profile_completeness_score' => 80,
    ]);
    CreatorSocialAccountFactory::new()->for($creator)->create(['handle' => 'pat_public']);
    CreatorPortfolioItemFactory::new()->for($creator)->link()->create(['title' => 'Public reel']);

    $response = $this->actingAs($admin)->getJson(publicProfileUrl($agency, $creator));

    $attrs = $response->assertOk()->json('data.attributes');
    expect($attrs['bio'])->toBe('Public bio text')
        ->and($attrs['region'])->toBe('Lisbon')
        ->and($attrs['primary_language'])->toBe('pt')
        ->and($attrs['secondary_languages'])->toBe(['en'])
        ->and($attrs['categories'])->toEqualCanonicalizing(['travel', 'food'])
        ->and($attrs['content_companions'])->toBe(['partner', 'pets_dogs'])
        ->and($attrs['profile_completeness_score'])->toBe(80)
        ->and($attrs['social_accounts'])->toHaveCount(1)
        ->and($attrs['social_accounts'][0]['handle'])->toBe('pat_public')
        ->and($attrs['portfolio'])->toHaveCount(1)
        ->and($attrs['portfolio'][0]['title'])->toBe('Public reel');
});

it('public detail WITHHOLDS email, the relation block, blacklist, counters and admin KYC (break-revert each)', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $user = User::factory()->createOne(['email' => 'leaky@example.com']);
    $creator = discoverableCreator(['user_id' => $user->id]);
    // The CALLING agency holds a rich private relation — none of it (bar the
    // status annotation) may surface on the public profile.
    AgencyCreatorRelation::factory()->blacklisted('SECRET BLACKLIST REASON')->create([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'internal_notes' => 'CONFIDENTIAL NOTE',
        'internal_rating' => 4,
        'total_campaigns_completed' => 12,
    ]);

    $body = $this->actingAs($admin)->getJson(publicProfileUrl($agency, $creator))
        ->assertOk()
        ->getContent();

    expect($body)->not->toContain('leaky@example.com')   // NO contact email pre-connection
        ->and($body)->not->toContain('"email"')
        ->and($body)->not->toContain('internal_notes')
        ->and($body)->not->toContain('CONFIDENTIAL NOTE')
        ->and($body)->not->toContain('internal_rating')
        ->and($body)->not->toContain('is_blacklisted')
        ->and($body)->not->toContain('blacklist_reason')
        ->and($body)->not->toContain('SECRET BLACKLIST REASON')
        ->and($body)->not->toContain('total_campaigns_completed')
        ->and($body)->not->toContain('admin_attributes')
        ->and($body)->not->toContain('kyc_method')
        ->and($body)->not->toContain('verified_by_user_id')
        ->and($body)->not->toContain('kyc_verifications');
});

// ---------------------------------------------------------------------------
// Portfolio download authz (AH-004 sub-step 6) — discover surface. The
// download_url rides the public-profile resource, so it inherits that
// resource's view authz (agency membership + the discoverable gate). A member
// who can view the public profile gets a ready item's download_url; an
// outsider who 404s the profile never receives one (no broader grant).
// ---------------------------------------------------------------------------

it('mints a portfolio download_url on the discover public profile for an agency member (AH-004 authz inherit)', function (): void {
    $adapter = Mockery::mock(AwsS3V3Adapter::class);
    $adapter->shouldReceive('temporaryUrl')
        ->andReturnUsing(function (string $path, $expiry, array $options = []): string {
            $disposition = isset($options['ResponseContentDisposition']) ? '&cd=1' : '';

            return "https://signed.example/{$path}?sig=test{$disposition}";
        });
    Storage::shouldReceive('disk')->with('media')->andReturn($adapter);

    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $creator = discoverableCreator();
    CreatorPortfolioItemFactory::new()->for($creator)->createOne([
        's3_path' => 'creators/01/portfolio/img.jpg',
        'thumbnail_path' => 'creators/01/portfolio/thumbs/img.jpg',
    ]);

    $items = $this->actingAs($admin)->getJson(publicProfileUrl($agency, $creator))
        ->assertOk()
        ->json('data.attributes.portfolio');

    expect($items)->toHaveCount(1);
    expect($items[0]['processing_status'])->toBe('ready');
    expect($items[0]['download_url'])->toContain('cd=1');
    expect($items[0]['download_url'])->toContain('creators/01/portfolio/img.jpg');
});

it('denies the discover portfolio download to a non-member — 404 before any download_url is minted (AH-004 authz break-revert)', function (): void {
    $adapter = Mockery::mock(AwsS3V3Adapter::class);
    $adapter->shouldReceive('temporaryUrl')
        ->andReturnUsing(fn (string $path): string => "https://signed.example/{$path}?sig=test");
    Storage::shouldReceive('disk')->with('media')->andReturn($adapter);

    $agency = Agency::factory()->createOne();
    $creator = discoverableCreator();
    CreatorPortfolioItemFactory::new()->for($creator)->createOne([
        's3_path' => 'creators/01/portfolio/img.jpg',
        'thumbnail_path' => 'creators/01/portfolio/thumbs/img.jpg',
    ]);
    $outsider = User::factory()->agencyAdmin(Agency::factory()->createOne())->createOne();

    $response = $this->actingAs($outsider)->getJson(publicProfileUrl($agency, $creator));

    $response->assertStatus(404);
    expect($response->getContent())->not->toContain('download_url')
        ->and($response->getContent())->not->toContain('creators/01/portfolio/img.jpg');
});

// ---------------------------------------------------------------------------
// The calling-agency-scoped "already-connected" annotation (D-4)
// ---------------------------------------------------------------------------

it('annotates the calling agency\'s OWN relation status (list + detail)', function (string $status): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $creator = discoverableCreator();
    AgencyCreatorRelation::factory()->create([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => RelationshipStatus::from($status),
    ]);

    $list = $this->actingAs($admin)->getJson(discoverUrl($agency));
    expect($list->json('data.0.attributes.relationship_status'))->toBe($status);

    $detail = $this->actingAs($admin)->getJson(publicProfileUrl($agency, $creator));
    expect($detail->json('data.attributes.relationship_status'))->toBe($status);
})->with(['roster', 'prospect', 'external', 'pending_request', 'declined']);

it('shows not-connected for a creator the calling agency has no relation with', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    discoverableCreator();

    $response = $this->actingAs($admin)->getJson(discoverUrl($agency));

    expect($response->json('data.0.attributes.relationship_status'))->toBeNull();
});

it('computes the list annotation in ONE query (no N+1 across the page)', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    // A page of creators, some connected to the calling agency, some not.
    foreach (range(1, 6) as $i) {
        $creator = discoverableCreator();
        if ($i % 2 === 0) {
            AgencyCreatorRelation::factory()->create([
                'agency_id' => $agency->id,
                'creator_id' => $creator->id,
                'relationship_status' => RelationshipStatus::Roster,
            ]);
        }
    }

    $this->actingAs($admin); // resolve auth before measuring the read

    DB::enableQueryLog();
    $response = $this->getJson(discoverUrl($agency, 'per_page=25'));
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    $response->assertOk();
    expect($response->json('meta.total'))->toBe(6);

    // The annotation is a correlated subquery on the ONE list query — NOT a
    // per-row membership query. Break-revert: a per-creator annotation would
    // add ~6 queries here. We allow generous headroom for auth/tenancy/count
    // bookkeeping but pin that it does not scale with the page size.
    expect(count($queries))->toBeLessThanOrEqual(6);
});

// ---------------------------------------------------------------------------
// Shared FTS / filter logic (D-3) narrows the GLOBAL pool
// ---------------------------------------------------------------------------

it('narrows the pool by country / language / category / q (the shared filters work on creators)', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $match = discoverableCreator([
        'display_name' => 'Ada Lovelace',
        'bio' => 'Pioneering mathematician',
        'country_code' => 'GB',
        'primary_language' => 'en',
        'categories' => ['tech'],
    ]);
    discoverableCreator([
        'display_name' => 'Grace Hopper',
        'bio' => 'Computer scientist',
        'country_code' => 'US',
        'primary_language' => 'en',
        'categories' => ['gaming'],
    ]);

    expect($this->actingAs($admin)->getJson(discoverUrl($agency, 'country=GB'))->json('meta.total'))->toBe(1);
    expect($this->actingAs($admin)->getJson(discoverUrl($agency, 'category=tech'))->json('meta.total'))->toBe(1);

    // q (SQLite ILIKE fallback) narrows by name AND bio.
    $byName = $this->actingAs($admin)->getJson(discoverUrl($agency, 'q=lovelace'));
    expect($byName->json('meta.total'))->toBe(1);
    expect($byName->json('data.0.attributes.display_name'))->toBe('Ada Lovelace');

    $byBio = $this->actingAs($admin)->getJson(discoverUrl($agency, 'q=mathematician'));
    expect($byBio->json('meta.total'))->toBe(1);
    expect($byBio->json('data.0.id'))->toBe($match->ulid);

    expect($this->actingAs($admin)->getJson(discoverUrl($agency, 'q=nonexistentneedle'))->json('meta.total'))->toBe(0);
});

// ---------------------------------------------------------------------------
// ⚠ Cross-agency isolation (D-7) — the load-bearing privacy pin
// ---------------------------------------------------------------------------

it('never surfaces another agency\'s private annotations via discovery OR the public detail (D-7 break-revert)', function (): void {
    // The shared creator sits on BOTH agencies' rosters.
    $shared = discoverableCreator(['display_name' => 'Shared Sam']);

    $agencyA = Agency::factory()->createOne();
    $adminA = User::factory()->agencyAdmin($agencyA)->createOne();
    AgencyCreatorRelation::factory()->create([
        'agency_id' => $agencyA->id,
        'creator_id' => $shared->id,
        'relationship_status' => RelationshipStatus::External,
        'internal_notes' => 'AGENCY A PRIVATE NOTE',
        'internal_rating' => 5,
    ]);

    $agencyB = Agency::factory()->createOne();
    $adminB = User::factory()->agencyAdmin($agencyB)->createOne();
    AgencyCreatorRelation::factory()->create([
        'agency_id' => $agencyB->id,
        'creator_id' => $shared->id,
        'relationship_status' => RelationshipStatus::Roster,
        'internal_notes' => 'AGENCY B PRIVATE NOTE',
        'internal_rating' => 1,
    ]);

    // Agency B — discovery list. Break-revert: un-scope the annotation join and
    // B begins seeing A's row (and the wrong status) → this fails.
    $bList = $this->actingAs($adminB)->getJson(discoverUrl($agencyB));
    expect($bList->json('data.0.attributes.relationship_status'))->toBe('roster'); // B's OWN status
    expect($bList->getContent())->not->toContain('AGENCY A PRIVATE NOTE');

    // Agency B — public detail (B HAS its own relation).
    $bDetail = $this->actingAs($adminB)->getJson(publicProfileUrl($agencyB, $shared));
    expect($bDetail->json('data.attributes.relationship_status'))->toBe('roster');
    expect($bDetail->getContent())->not->toContain('AGENCY A PRIVATE NOTE');

    // And the converse — Agency A sees only its OWN status, never B's.
    $aList = $this->actingAs($adminA)->getJson(discoverUrl($agencyA));
    expect($aList->json('data.0.attributes.relationship_status'))->toBe('external'); // A's OWN status
    expect($aList->getContent())->not->toContain('AGENCY B PRIVATE NOTE');
});
