<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Agencies\Models\BrandCreatorBlacklist;
use App\Modules\Campaigns\Enums\CampaignApplicationStatus;
use App\Modules\Campaigns\Enums\CampaignStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignApplication;
use App\Modules\Creators\Enums\ApplicationStatus;
use App\Modules\Creators\Enums\RelationshipStatus;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\JobsBoard\CreatorJobFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Jobs Board chunk 3 (AH-056) — the creator's board LIST endpoint and the
 * six-leg visibility predicate behind it.
 *
 *   GET /api/v1/creators/me/jobs
 *
 * The centrepiece is the §5.34 disjoint-and-complete negative set: SEVEN cases,
 * each of which is eligible in every respect except one. Every one of them is
 * built from the same {@see CreatorJobFixture} happy path and then broken in
 * exactly one place, so a case that passes for the wrong reason is visible.
 *
 * The same seven cases are re-run against the DETAIL endpoint, the APPLY
 * endpoint and the fan-out recipient query in their own files — the predicate
 * is shared, but "shared" is a claim, and the claim is only worth what the
 * re-run proves.
 */

// ── The happy path ──────────────────────────────────────────────────────────

it('shows a rostered approved creator their agency listed job', function (): void {
    $f = CreatorJobFixture::make();

    $response = $this->actingAs($f->user)->getJson(CreatorJobFixture::URL)->assertOk();

    expect(CreatorJobFixture::ulids($response->json()))->toBe([$f->campaign->ulid]);

    $response->assertJsonPath('data.0.type', 'creator_job')
        ->assertJsonPath('data.0.attributes.name', 'Autumn UGC push')
        ->assertJsonPath('data.0.attributes.brand.name', 'Northwind Coffee')
        ->assertJsonPath('data.0.attributes.listing_fee', '€300 per video')
        ->assertJsonPath('data.0.attributes.listing_duration', '4 weeks')
        ->assertJsonPath('data.0.attributes.applicant_count', 0)
        ->assertJsonPath('data.0.attributes.application_status', null)
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('meta.per_page', 25);
});

// ── §5.34 — the seven-case disjoint negative set ────────────────────────────
//
// Each case is the happy path with EXACTLY ONE leg broken.

it('LEG 2 — hides everything from a creator who is not approved', function (ApplicationStatus $status): void {
    $f = CreatorJobFixture::make();
    $f->creator->forceFill(['application_status' => $status])->save();

    // An empty board, NOT an error — "you can see no jobs" is a legitimate
    // state (the agenciesForCreator shape), so the envelope stays valid.
    $this->actingAs($f->user)->getJson(CreatorJobFixture::URL)
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.total', 0);
})->with([
    ApplicationStatus::Incomplete,
    ApplicationStatus::Pending,
    ApplicationStatus::Rejected,
]);

it('LEG 3a — hides a job from an agency the creator holds NO relation with', function (): void {
    $f = CreatorJobFixture::make();
    $f->relation->delete();

    $this->actingAs($f->user)->getJson(CreatorJobFixture::URL)->assertOk()->assertJsonCount(0, 'data');
});

it('LEG 3b — hides a job once the relation is no longer roster', function (RelationshipStatus $status): void {
    $f = CreatorJobFixture::make();
    $f->relation->forceFill(['relationship_status' => $status])->save();

    $this->actingAs($f->user)->getJson(CreatorJobFixture::URL)->assertOk()->assertJsonCount(0, 'data');
})->with([
    RelationshipStatus::Ended,
    RelationshipStatus::PendingRequest,
    RelationshipStatus::Declined,
    RelationshipStatus::Prospect,
    RelationshipStatus::External,
]);

it('LEG 4 — hides a campaign that is not listed on the jobs board', function (): void {
    $f = CreatorJobFixture::make();
    $f->campaign->forceFill(['listed_on_jobs_board' => false])->save();

    $this->actingAs($f->user)->getJson(CreatorJobFixture::URL)->assertOk()->assertJsonCount(0, 'data');
});

it('LEG 4b — hides a listed campaign whose status has gone terminal', function (CampaignStatus $status): void {
    $f = CreatorJobFixture::make();
    $f->campaign->forceFill(['status' => $status])->save();

    // The flag is STILL true — AH-054 D5 rules a status change never rewrites
    // the agency's stored intent — so this can only be the read-time leg.
    expect($f->campaign->fresh()?->listed_on_jobs_board)->toBeTrue();

    $this->actingAs($f->user)->getJson(CreatorJobFixture::URL)->assertOk()->assertJsonCount(0, 'data');
})->with([CampaignStatus::Completed, CampaignStatus::Cancelled]);

it('LEG 5 — hides a listing whose end date has passed', function (): void {
    $f = CreatorJobFixture::make(['ends_at' => now('UTC')->subDay()]);

    $this->actingAs($f->user)->getJson(CreatorJobFixture::URL)->assertOk()->assertJsonCount(0, 'data');
});

it('LEG 6 — hides a job whose BRAND has hard-blacklisted the creator', function (): void {
    $f = CreatorJobFixture::make();

    BrandCreatorBlacklist::factory()->createOne([
        'brand_id' => $f->brand->id,
        'creator_id' => $f->creator->id,
    ]);

    // The board must never solicit an application the invite gate would
    // hard-block: AssignmentInviteGate::isHardBlacklisted() would 422 this
    // exact pair, so the agency could never act on the application.
    $this->actingAs($f->user)->getJson(CreatorJobFixture::URL)->assertOk()->assertJsonCount(0, 'data');
});

// ── The positive partition (the complement of the negative set) ─────────────

it('shows a listing in every non-terminal status', function (CampaignStatus $status): void {
    $f = CreatorJobFixture::make();
    $f->campaign->forceFill(['status' => $status])->save();

    $this->actingAs($f->user)->getJson(CreatorJobFixture::URL)->assertOk()->assertJsonCount(1, 'data');
})->with([CampaignStatus::Draft, CampaignStatus::Active, CampaignStatus::Paused]);

it('treats a null ends_at as never-expires, and keeps a job visible THROUGH its end date', function (?string $endsAt): void {
    $f = CreatorJobFixture::make([
        'ends_at' => $endsAt === null ? null : now('UTC')->{$endsAt}(),
    ]);

    $this->actingAs($f->user)->getJson(CreatorJobFixture::URL)->assertOk()->assertJsonCount(1, 'data');
})->with([
    null,          // never-expires
    'startOfDay',  // ends today, first instant — still visible
    'endOfDay',    // ends today, last instant — still visible
    'addWeek',     // ends in the future
]);

it('keeps a job visible when the brand blacklist is SOFT, or hard-but-lifted', function (): void {
    $f = CreatorJobFixture::make();

    // Soft is warn-at-invite semantics and must NOT hide jobs (C5).
    $entry = BrandCreatorBlacklist::factory()->soft()->createOne([
        'brand_id' => $f->brand->id,
        'creator_id' => $f->creator->id,
    ]);
    $this->actingAs($f->user)->getJson(CreatorJobFixture::URL)->assertOk()->assertJsonCount(1, 'data');

    $entry->forceFill(['blacklist_type' => 'hard'])->save();
    $this->actingAs($f->user)->getJson(CreatorJobFixture::URL)->assertOk()->assertJsonCount(0, 'data');

    // A LIFTED hard blacklist is soft-deleted, and the predicate honours that
    // (the same `whereNull('deleted_at')` guard the invite gate uses).
    $entry->delete();
    $this->actingAs($f->user)->getJson(CreatorJobFixture::URL)->assertOk()->assertJsonCount(1, 'data');
});

it('hides a job when the AGENCY-wide relation blacklist is set — hard OR soft', function (string $type): void {
    $f = CreatorJobFixture::make();
    $f->relation->forceFill(['is_blacklisted' => true, 'blacklist_type' => $type])->save();

    // Deliberately STRICTER than the discover feed, which excludes hard only:
    // an agency that has soft-blacklisted a creator should not be soliciting
    // applications from them. This falls out of scopePermitsMessaging() for
    // free — recorded, not worked around (C5).
    $this->actingAs($f->user)->getJson(CreatorJobFixture::URL)->assertOk()->assertJsonCount(0, 'data');
})->with(['hard', 'soft']);

// ── Cross-tenant isolation ──────────────────────────────────────────────────

it('never shows a job from an agency the creator is not rostered with, even when another is', function (): void {
    $f = CreatorJobFixture::make();

    Campaign::factory()->listed()->createOne([
        'agency_id' => Agency::factory()->createOne()->id,
    ]);

    $response = $this->actingAs($f->user)->getJson(CreatorJobFixture::URL)->assertOk();

    expect(CreatorJobFixture::ulids($response->json()))->toBe([$f->campaign->ulid]);
});

it('shows jobs from EVERY agency the creator is rostered with', function (): void {
    $f = CreatorJobFixture::make();

    $second = Agency::factory()->createOne();
    AgencyCreatorRelation::factory()->createOne([
        'agency_id' => $second->id,
        'creator_id' => $f->creator->id,
        'relationship_status' => RelationshipStatus::Roster,
        'is_blacklisted' => false,
    ]);
    $secondJob = Campaign::factory()->listed()->createOne([
        'agency_id' => $second->id,
        'listed_at' => now()->addMinute(),
    ]);

    $response = $this->actingAs($f->user)->getJson(CreatorJobFixture::URL)->assertOk();

    // Newest listing first.
    expect(CreatorJobFixture::ulids($response->json()))->toBe([$secondJob->ulid, $f->campaign->ulid]);
});

it('excludes a soft-deleted campaign — the SoftDeletes scope is deliberately not dropped', function (): void {
    $f = CreatorJobFixture::make();
    $f->campaign->delete();

    $this->actingAs($f->user)->getJson(CreatorJobFixture::URL)->assertOk()->assertJsonCount(0, 'data');
});

// ── The per-caller annotations ──────────────────────────────────────────────

it('counts applicants in every status but reports only the CALLER own application', function (): void {
    $f = CreatorJobFixture::make();

    CampaignApplication::factory()->createOne([
        'campaign_id' => $f->campaign->id,
        'creator_id' => $f->creator->id,
        'status' => CampaignApplicationStatus::Pending,
    ]);
    CampaignApplication::factory()->createOne([
        'campaign_id' => $f->campaign->id,
        'creator_id' => Creator::factory()->createOne()->id,
        'status' => CampaignApplicationStatus::Rejected,
    ]);

    $this->actingAs($f->user)->getJson(CreatorJobFixture::URL)->assertOk()
        ->assertJsonPath('data.0.attributes.applicant_count', 2)
        ->assertJsonPath('data.0.attributes.application_status', 'pending');
});

it('never leaks another creator application status through the annotation', function (): void {
    $f = CreatorJobFixture::make();

    // Somebody else applied; the caller has not.
    CampaignApplication::factory()->createOne([
        'campaign_id' => $f->campaign->id,
        'creator_id' => Creator::factory()->createOne()->id,
        'status' => CampaignApplicationStatus::Accepted,
    ]);

    $this->actingAs($f->user)->getJson(CreatorJobFixture::URL)->assertOk()
        ->assertJsonPath('data.0.attributes.applicant_count', 1)
        ->assertJsonPath('data.0.attributes.application_status', null);
});

// ── Envelope ────────────────────────────────────────────────────────────────

it('paginates with the discover-shaped meta envelope and clamps per_page', function (): void {
    $f = CreatorJobFixture::make();
    Campaign::factory()->count(3)->listed()->create(['agency_id' => $f->agency->id]);

    $this->actingAs($f->user)->getJson(CreatorJobFixture::URL.'?per_page=2')->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 4)
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonPath('meta.page', 1);

    // Clamped to MAX_PER_PAGE rather than honoured or rejected.
    $this->actingAs($f->user)->getJson(CreatorJobFixture::URL.'?per_page=5000')->assertOk()
        ->assertJsonPath('meta.per_page', 100);
});

it('requires authentication', function (): void {
    $this->getJson(CreatorJobFixture::URL)->assertUnauthorized();
});

it('404s for an authenticated user who is not a creator', function (): void {
    $agencyUser = User::factory()->agencyAdmin()->createOne();

    $this->actingAs($agencyUser)->getJson(CreatorJobFixture::URL)->assertNotFound();
});
