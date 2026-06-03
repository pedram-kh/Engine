<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Creators\Enums\ApplicationStatus;
use App\Modules\Creators\Enums\RelationshipStatus;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

/**
 * Sprint 4 Chunk 5 — GET /api/v1/agencies/{agency}/creators.
 *
 * The agency roster list ("my creators"): the agency's relations across
 * ALL relationship_status values, joined to their creators, with the four
 * backed filters (status / country / language / category). Slim hand-rolled
 * resource (D-c5-5) — internal_rating present, internal_notes + signed URLs
 * absent. Blacklisted relations are INCLUDED (with flag), unlike the
 * dashboard KPI which excludes them.
 */

/**
 * Create a relation for the given agency + a fresh creator, returning the
 * relation so callers can read its ulid.
 *
 * @param  array<string, mixed>  $relationAttributes
 * @param  array<string, mixed>  $creatorAttributes
 */
function makeRosterRelation(
    Agency $agency,
    array $relationAttributes = [],
    array $creatorAttributes = [],
): AgencyCreatorRelation {
    $creator = Creator::factory()->create($creatorAttributes);

    return AgencyCreatorRelation::factory()->create(array_merge([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => RelationshipStatus::Roster,
        'is_blacklisted' => false,
    ], $relationAttributes));
}

function rosterUrl(Agency $agency, string $query = ''): string
{
    return "/api/v1/agencies/{$agency->ulid}/creators".($query === '' ? '' : "?{$query}");
}

// ---------------------------------------------------------------------------
// Auth + tenancy boundary
// ---------------------------------------------------------------------------

it('returns 401 when no user is authenticated', function (): void {
    $agency = Agency::factory()->createOne();

    expect($this->getJson(rosterUrl($agency))->status())->toBe(401);
});

it('returns 404 when the authenticated user is not a member (tenancy.agency invisibility)', function (): void {
    $agency = Agency::factory()->createOne();
    $otherAgency = Agency::factory()->createOne();
    $outsider = User::factory()->agencyAdmin($otherAgency)->createOne();

    expect($this->actingAs($outsider)->getJson(rosterUrl($agency))->status())->toBe(404);
});

it('returns 200 for any agency member (staff included — read-only, no admin gate)', function (): void {
    $agency = Agency::factory()->createOne();
    $staff = User::factory()->agencyStaff($agency)->createOne();

    expect($this->actingAs($staff)->getJson(rosterUrl($agency))->status())->toBe(200);
});

// ---------------------------------------------------------------------------
// Roster contents — all relationship statuses
// ---------------------------------------------------------------------------

it('lists relations across all relationship statuses (roster, prospect, external)', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    makeRosterRelation($agency, ['relationship_status' => RelationshipStatus::Roster]);
    makeRosterRelation($agency, ['relationship_status' => RelationshipStatus::Prospect]);
    makeRosterRelation($agency, ['relationship_status' => RelationshipStatus::External]);

    $response = $this->actingAs($admin)->getJson(rosterUrl($agency));

    expect($response->status())->toBe(200);
    expect($response->json('meta.total'))->toBe(3);

    $statuses = $response->json('data.*.attributes.relationship_status');
    expect($statuses)->toEqualCanonicalizing(['roster', 'prospect', 'external']);
});

// ---------------------------------------------------------------------------
// Slim resource shape (D-c5-5)
// ---------------------------------------------------------------------------

it('exposes the slim row shape with internal_rating but NOT internal_notes and no signed URLs', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    makeRosterRelation(
        $agency,
        ['internal_rating' => 4, 'internal_notes' => 'private agency note — never surfaced'],
        ['display_name' => 'Ada Lovelace', 'country_code' => 'GB', 'primary_language' => 'en'],
    );

    $response = $this->actingAs($admin)->getJson(rosterUrl($agency));

    $attributes = $response->json('data.0.attributes');

    expect($attributes)->toHaveKeys([
        'relationship_status',
        'is_blacklisted',
        'internal_rating',
        'total_campaigns_completed',
        'total_paid_minor_units',
        'last_engaged_at',
        'creator_id',
        'display_name',
        'application_status',
        'country_code',
        'primary_language',
        'categories',
    ]);
    expect($attributes['internal_rating'])->toBe(4);

    // The GDPR-sensitive note must NEVER appear anywhere in the payload
    // (break-revert: adding it to the row shape fails this).
    expect($attributes)->not->toHaveKey('internal_notes');
    expect($response->getContent())->not->toContain('internal_notes');
    expect($response->getContent())->not->toContain('private agency note');

    // No signed media URLs (the slim resource is not CreatorResource).
    expect($attributes)->not->toHaveKey('avatar_url');
    expect($attributes)->not->toHaveKey('cover_url');
});

it('surfaces each creator application_status on the slim row, reflecting actual state (Chunk 5b)', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    // Four creators in distinct application states — all roster relations of
    // this agency. Names chosen so the default display_name ASC sort gives a
    // deterministic order; application_status is read per-creator, NOT a
    // constant, so this pins it to the real column value (break-revert: a
    // hardcoded literal in toRow would fail the per-name pairing below).
    makeRosterRelation($agency, [], ['display_name' => 'Amy Approved', 'application_status' => ApplicationStatus::Approved]);
    makeRosterRelation($agency, [], ['display_name' => 'Ivy Incomplete', 'application_status' => ApplicationStatus::Incomplete]);
    makeRosterRelation($agency, [], ['display_name' => 'Pat Pending', 'application_status' => ApplicationStatus::Pending]);
    makeRosterRelation($agency, [], ['display_name' => 'Rae Rejected', 'application_status' => ApplicationStatus::Rejected]);

    $response = $this->actingAs($admin)->getJson(rosterUrl($agency));

    expect($response->status())->toBe(200);

    // Parallel wildcard arrays under the default display_name ASC sort — the
    // status at index i belongs to the creator named at index i.
    expect($response->json('data.*.attributes.display_name'))
        ->toBe(['Amy Approved', 'Ivy Incomplete', 'Pat Pending', 'Rae Rejected']);
    expect($response->json('data.*.attributes.application_status'))
        ->toBe(['approved', 'incomplete', 'pending', 'rejected']);
});

// ---------------------------------------------------------------------------
// Filters
// ---------------------------------------------------------------------------

it('filters by relationship status', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    makeRosterRelation($agency, ['relationship_status' => RelationshipStatus::Roster]);
    makeRosterRelation($agency, ['relationship_status' => RelationshipStatus::Prospect]);

    $response = $this->actingAs($admin)->getJson(rosterUrl($agency, 'status=prospect'));

    expect($response->json('meta.total'))->toBe(1);
    expect($response->json('data.0.attributes.relationship_status'))->toBe('prospect');
});

it('filters by country_code', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    makeRosterRelation($agency, [], ['country_code' => 'PT']);
    makeRosterRelation($agency, [], ['country_code' => 'US']);

    $response = $this->actingAs($admin)->getJson(rosterUrl($agency, 'country=PT'));

    expect($response->json('meta.total'))->toBe(1);
    expect($response->json('data.0.attributes.country_code'))->toBe('PT');
});

it('filters by primary_language', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    makeRosterRelation($agency, [], ['primary_language' => 'it']);
    makeRosterRelation($agency, [], ['primary_language' => 'en']);

    $response = $this->actingAs($admin)->getJson(rosterUrl($agency, 'language=it'));

    expect($response->json('meta.total'))->toBe(1);
    expect($response->json('data.0.attributes.primary_language'))->toBe('it');
});

it('filters by category via jsonb containment', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    makeRosterRelation($agency, [], ['categories' => ['fitness', 'travel']]);
    makeRosterRelation($agency, [], ['categories' => ['food', 'tech']]);

    $response = $this->actingAs($admin)->getJson(rosterUrl($agency, 'category=travel'));

    expect($response->json('meta.total'))->toBe(1);
    expect($response->json('data.0.attributes.categories'))->toContain('travel');
});

// ---------------------------------------------------------------------------
// Name/bio full-text search (?q=) — Sprint 6 Chunk 1 (D-1)
//
// The CI suite runs on SQLite, so these exercise the `LOWER(...) LIKE`
// substring FALLBACK (D-3) — the path the test suite actually runs in dev +
// CI. The Postgres `to_tsvector @@ plainto_tsquery` branch is un-unit-testable
// under SQLite (no tsvector); it ships with a dormant markTestSkipped
// counterpart below + a manual local-Postgres verification noted in the review.
// ---------------------------------------------------------------------------

it('narrows by display_name via the q search (SQLite ILIKE fallback)', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $match = makeRosterRelation($agency, [], ['display_name' => 'Ada Lovelace', 'bio' => 'Mathematician']);
    makeRosterRelation($agency, [], ['display_name' => 'Grace Hopper', 'bio' => 'Computer scientist']);

    // Case-insensitive substring match on the name.
    $response = $this->actingAs($admin)->getJson(rosterUrl($agency, 'q=lovelace'));

    expect($response->json('meta.total'))->toBe(1);
    expect($response->json('data.0.id'))->toBe($match->ulid);
    expect($response->json('data.0.attributes.display_name'))->toBe('Ada Lovelace');
});

it('narrows by bio via the q search (SQLite ILIKE fallback)', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $match = makeRosterRelation($agency, [], ['display_name' => 'Ada Lovelace', 'bio' => 'Pioneering mathematician']);
    makeRosterRelation($agency, [], ['display_name' => 'Grace Hopper', 'bio' => 'Computer scientist']);

    // The needle only appears in the bio, not the name.
    $response = $this->actingAs($admin)->getJson(rosterUrl($agency, 'q=mathematician'));

    expect($response->json('meta.total'))->toBe(1);
    expect($response->json('data.0.id'))->toBe($match->ulid);
});

it('returns zero rows for an unmatched q (break-revert anchor)', function (): void {
    // Break-revert (§5.35): if the `q` filter is dropped from the controller,
    // this query would ignore `q` and return BOTH rows — failing this
    // expect(0). It pins that an unmatched search actually narrows rather than
    // silently returning all.
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    makeRosterRelation($agency, [], ['display_name' => 'Ada Lovelace', 'bio' => 'Mathematician']);
    makeRosterRelation($agency, [], ['display_name' => 'Grace Hopper', 'bio' => 'Computer scientist']);

    $response = $this->actingAs($admin)->getJson(rosterUrl($agency, 'q=nonexistentneedle'));

    expect($response->status())->toBe(200);
    expect($response->json('meta.total'))->toBe(0);
});

it('treats a blank/whitespace q as a no-op (returns all)', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    makeRosterRelation($agency, [], ['display_name' => 'Ada Lovelace']);
    makeRosterRelation($agency, [], ['display_name' => 'Grace Hopper']);

    $response = $this->actingAs($admin)->getJson(rosterUrl($agency, 'q=%20%20'));

    expect($response->json('meta.total'))->toBe(2);
});

it('escapes LIKE wildcards so a literal % in q is not a wildcard', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    // A bare `%` would match every row if wildcards weren't escaped; with
    // escaping it matches only a literal percent, of which there are none.
    makeRosterRelation($agency, [], ['display_name' => 'Ada Lovelace']);
    makeRosterRelation($agency, [], ['display_name' => 'Grace Hopper']);

    $response = $this->actingAs($admin)->getJson(rosterUrl($agency, 'q=%25'));

    expect($response->json('meta.total'))->toBe(0);
});

it('[postgres-only] matches name/bio via to_tsvector @@ plainto_tsquery', function (): void {
    // The Postgres FTS branch (search_vector @@ plainto_tsquery('simple', ?))
    // cannot run under the SQLite :memory: test DB — there is no tsvector type
    // or generated `search_vector` column there (the migration is pgsql-guarded,
    // D-1). This assertion is dormant: it skips under SQLite and becomes live
    // the day a Postgres CI job lands (docs/tech-debt.md — SQLite-in-tests).
    // Until then the FTS path is covered by manual local-Postgres verification
    // (recorded in the chunk review) — the one place "green CI" is not full
    // proof, stated honestly (D-3).
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('FTS tsvector path requires Postgres; SQLite uses the ILIKE fallback.');
    }

    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $match = makeRosterRelation($agency, [], ['display_name' => 'Ada Lovelace', 'bio' => 'Mathematician']);
    makeRosterRelation($agency, [], ['display_name' => 'Grace Hopper', 'bio' => 'Computer scientist']);

    // FTS matches whole-word lexemes (not substrings): a full token narrows.
    $response = $this->actingAs($admin)->getJson(rosterUrl($agency, 'q=lovelace'));

    expect($response->json('meta.total'))->toBe(1);
    expect($response->json('data.0.id'))->toBe($match->ulid);
});

it('ANDs combined filters together', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    // Match: roster + PT + categories has lifestyle.
    $match = makeRosterRelation(
        $agency,
        ['relationship_status' => RelationshipStatus::Roster],
        ['country_code' => 'PT', 'primary_language' => 'pt', 'categories' => ['lifestyle']],
    );
    // Same country but wrong status.
    makeRosterRelation(
        $agency,
        ['relationship_status' => RelationshipStatus::External],
        ['country_code' => 'PT', 'categories' => ['lifestyle']],
    );
    // Right status but wrong country.
    makeRosterRelation(
        $agency,
        ['relationship_status' => RelationshipStatus::Roster],
        ['country_code' => 'US', 'categories' => ['lifestyle']],
    );

    $response = $this->actingAs($admin)
        ->getJson(rosterUrl($agency, 'status=roster&country=PT&category=lifestyle'));

    expect($response->json('meta.total'))->toBe(1);
    expect($response->json('data.0.id'))->toBe($match->ulid);
});

it('returns an empty page for an unknown status value', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    makeRosterRelation($agency);

    $response = $this->actingAs($admin)->getJson(rosterUrl($agency, 'status=not_a_status'));

    expect($response->status())->toBe(200);
    expect($response->json('meta.total'))->toBe(0);
});

// ---------------------------------------------------------------------------
// Pagination
// ---------------------------------------------------------------------------

it('paginates with per_page and reports paging meta', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    foreach (range(1, 3) as $i) {
        makeRosterRelation($agency);
    }

    $response = $this->actingAs($admin)->getJson(rosterUrl($agency, 'per_page=2&page=1'));

    expect($response->status())->toBe(200);
    expect($response->json('meta.total'))->toBe(3);
    expect($response->json('meta.per_page'))->toBe(2);
    expect($response->json('meta.page'))->toBe(1);
    expect($response->json('meta.last_page'))->toBe(2);
    expect($response->json('data'))->toHaveCount(2);
});

it('clamps per_page to the 1..100 bound', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    makeRosterRelation($agency);

    $response = $this->actingAs($admin)->getJson(rosterUrl($agency, 'per_page=9999'));

    expect($response->json('meta.per_page'))->toBe(100);
});

// ---------------------------------------------------------------------------
// Blacklist + soft-delete
// ---------------------------------------------------------------------------

it('INCLUDES blacklisted relations with the flag visible (unlike the dashboard KPI)', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    makeRosterRelation($agency, ['is_blacklisted' => false]);
    makeRosterRelation($agency, ['is_blacklisted' => true]);

    $response = $this->actingAs($admin)->getJson(rosterUrl($agency));

    expect($response->json('meta.total'))->toBe(2);
    $flags = $response->json('data.*.attributes.is_blacklisted');
    expect($flags)->toContain(true)->toContain(false);
});

it('excludes soft-deleted creators from the roster list', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    makeRosterRelation($agency);
    $relation = makeRosterRelation($agency);
    $relation->creator()->firstOrFail()->delete();

    $response = $this->actingAs($admin)->getJson(rosterUrl($agency));

    expect($response->json('meta.total'))->toBe(1);
});

// ---------------------------------------------------------------------------
// Default sort
// ---------------------------------------------------------------------------

it('sorts by creator display_name ascending by default', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    makeRosterRelation($agency, [], ['display_name' => 'Charlie']);
    makeRosterRelation($agency, [], ['display_name' => 'Alice']);
    makeRosterRelation($agency, [], ['display_name' => 'Bob']);

    $response = $this->actingAs($admin)->getJson(rosterUrl($agency));

    $names = $response->json('data.*.attributes.display_name');
    expect($names)->toBe(['Alice', 'Bob', 'Charlie']);
});

// ---------------------------------------------------------------------------
// Tenancy isolation (break-revert anchor)
// ---------------------------------------------------------------------------

it('never lists another agency\'s relations', function (): void {
    $agency = Agency::factory()->createOne();
    $other = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    // Agency B data — must be invisible to agency A's roster.
    makeRosterRelation($other);
    makeRosterRelation($other);

    // Agency A data — a single relation.
    $mine = makeRosterRelation($agency, [], ['display_name' => 'Only Mine']);

    $response = $this->actingAs($admin)->getJson(rosterUrl($agency));

    expect($response->json('meta.total'))->toBe(1);
    expect($response->json('data.0.id'))->toBe($mine->ulid);
    expect($response->json('data.0.attributes.display_name'))->toBe('Only Mine');
});
