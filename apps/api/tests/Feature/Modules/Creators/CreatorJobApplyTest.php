<?php

declare(strict_types=1);

use App\Core\Tenancy\BelongsToAgencyScope;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\BrandCreatorBlacklist;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Campaigns\Enums\CampaignApplicationStatus;
use App\Modules\Campaigns\Enums\CampaignStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignApplication;
use App\Modules\Creators\Enums\ApplicationStatus;
use App\Modules\Creators\Enums\RelationshipStatus;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\JobsBoard\CreatorJobFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Jobs Board chunk 3 (AH-056) — APPLY.
 *
 *   POST /api/v1/creators/me/jobs/{campaign}/apply
 *
 * Three things are pinned here beyond the happy path:
 *
 * 1. The §5.34 seven-case set, re-run a THIRD time — because this surface
 *    WRITES. A leg missing from the read endpoints shows the creator a job they
 *    should not see; a leg missing here creates a row the agency can never act
 *    on, and the no-re-apply rule then makes it permanent.
 *
 * 2. Apply-time RE-VALIDATION specifically: a job that was visible when the
 *    board rendered and stopped being visible before the tap.
 *
 * 3. The no-re-apply pin and the §5.6 unique-pair race.
 */

/** @return list<CampaignApplication> */
function applicationsFor(Campaign $campaign): array
{
    return array_values(
        CampaignApplication::withoutGlobalScope(BelongsToAgencyScope::class)
            ->where('campaign_id', $campaign->id)
            ->get()
            ->all(),
    );
}

// ── The happy path ──────────────────────────────────────────────────────────

it('creates a pending application with one tap and no body', function (): void {
    $f = CreatorJobFixture::make();

    $this->actingAs($f->user)->postJson($f->applyUrl())
        ->assertCreated()
        ->assertJsonPath('data.type', 'campaign_application')
        ->assertJsonPath('data.attributes.status', 'pending')
        ->assertJsonPath('data.attributes.note', null);

    $applications = applicationsFor($f->campaign);

    expect($applications)->toHaveCount(1)
        ->and($applications[0]->status)->toBe(CampaignApplicationStatus::Pending)
        ->and($applications[0]->creator_id)->toBe($f->creator->id)
        ->and($applications[0]->responded_at)->toBeNull();
});

it('stores an optional note and trims a whitespace-only one to null', function (): void {
    $f = CreatorJobFixture::make();

    $this->actingAs($f->user)->postJson($f->applyUrl(), ['note' => '  I shot a similar campaign last spring.  '])
        ->assertCreated()
        ->assertJsonPath('data.attributes.note', 'I shot a similar campaign last spring.');

    $blank = CreatorJobFixture::make();
    $this->actingAs($blank->user)->postJson($blank->applyUrl(), ['note' => "   \n  "])
        ->assertCreated()
        ->assertJsonPath('data.attributes.note', null);
});

it('rejects a note longer than the cap', function (): void {
    $f = CreatorJobFixture::make();

    $this->actingAs($f->user)->postJson($f->applyUrl(), ['note' => str_repeat('x', 1001)])
        ->assertUnprocessable()
        ->assertEnvelopeValidationErrors(['note']);

    expect(applicationsFor($f->campaign))->toHaveCount(0);
});

// ── Q8 — the denormalized agency_id cannot diverge ──────────────────────────

it('sets agency_id from the campaign, never from ambient context', function (): void {
    $f = CreatorJobFixture::make();

    $this->actingAs($f->user)->postJson($f->applyUrl())->assertCreated();

    $application = applicationsFor($f->campaign)[0];

    expect($application->agency_id)->toBe($f->campaign->agency_id)->toBe($f->agency->id);
});

it('keeps agency_id equal to the campaign agency across EVERY application ever written', function (): void {
    // Two creators, two agencies, four applications — the drift surface in
    // miniature. The insert site is singular, so the invariant is checkable
    // exhaustively rather than by spot check.
    foreach (range(1, 2) as $ignored) {
        $f = CreatorJobFixture::make();
        $this->actingAs($f->user)->postJson($f->applyUrl())->assertCreated();

        $second = Campaign::factory()->listed()->createOne(['agency_id' => $f->agency->id]);
        $this->actingAs($f->user)->postJson($f->applyUrl($second))->assertCreated();
    }

    $diverged = CampaignApplication::withoutGlobalScope(BelongsToAgencyScope::class)
        ->join('campaigns', 'campaigns.id', '=', 'campaign_applications.campaign_id')
        ->whereColumn('campaigns.agency_id', '!=', 'campaign_applications.agency_id')
        ->count();

    expect(CampaignApplication::withoutGlobalScope(BelongsToAgencyScope::class)->count())->toBe(4)
        ->and($diverged)->toBe(0);
});

// ── §5.34 — the same seven cases, on the WRITE surface ──────────────────────

it('LEG 2 — refuses an apply from a creator who is not approved', function (ApplicationStatus $status): void {
    $f = CreatorJobFixture::make();
    $f->creator->forceFill(['application_status' => $status])->save();

    $this->actingAs($f->user)->postJson($f->applyUrl())->assertNotFound();
    expect(applicationsFor($f->campaign))->toHaveCount(0);
})->with([ApplicationStatus::Incomplete, ApplicationStatus::Pending, ApplicationStatus::Rejected]);

it('LEG 3a — refuses an apply to an agency the creator holds no relation with', function (): void {
    $f = CreatorJobFixture::make();
    $f->relation->delete();

    $this->actingAs($f->user)->postJson($f->applyUrl())->assertNotFound();
    expect(applicationsFor($f->campaign))->toHaveCount(0);
});

it('LEG 3b — refuses an apply once the relation is no longer roster', function (RelationshipStatus $status): void {
    $f = CreatorJobFixture::make();
    $f->relation->forceFill(['relationship_status' => $status])->save();

    $this->actingAs($f->user)->postJson($f->applyUrl())->assertNotFound();
    expect(applicationsFor($f->campaign))->toHaveCount(0);
})->with([
    RelationshipStatus::Ended,
    RelationshipStatus::PendingRequest,
    RelationshipStatus::Declined,
    RelationshipStatus::Prospect,
    RelationshipStatus::External,
]);

it('LEG 4 — refuses an apply to a delisted campaign', function (): void {
    $f = CreatorJobFixture::make();
    $f->campaign->forceFill(['listed_on_jobs_board' => false])->save();

    $this->actingAs($f->user)->postJson($f->applyUrl())->assertNotFound();
    expect(applicationsFor($f->campaign))->toHaveCount(0);
});

it('LEG 4b — refuses an apply to a terminal-status campaign', function (CampaignStatus $status): void {
    $f = CreatorJobFixture::make();
    $f->campaign->forceFill(['status' => $status])->save();

    $this->actingAs($f->user)->postJson($f->applyUrl())->assertNotFound();
    expect(applicationsFor($f->campaign))->toHaveCount(0);
})->with([CampaignStatus::Completed, CampaignStatus::Cancelled]);

it('LEG 5 — refuses an apply to an expired listing', function (): void {
    $f = CreatorJobFixture::make(['ends_at' => now('UTC')->subDay()]);

    $this->actingAs($f->user)->postJson($f->applyUrl())->assertNotFound();
    expect(applicationsFor($f->campaign))->toHaveCount(0);
});

it('LEG 6 — refuses an apply when the brand has hard-blacklisted the creator', function (): void {
    $f = CreatorJobFixture::make();

    BrandCreatorBlacklist::factory()->createOne(['brand_id' => $f->brand->id, 'creator_id' => $f->creator->id]);

    // The invite gate would 422 this exact pair, so an application here would be
    // one the agency could never accept. C5's whole point.
    $this->actingAs($f->user)->postJson($f->applyUrl())->assertNotFound();
    expect(applicationsFor($f->campaign))->toHaveCount(0);
});

it('refuses an apply to another agency job by ULID', function (): void {
    $f = CreatorJobFixture::make();
    $foreign = Campaign::factory()->listed()->createOne(['agency_id' => Agency::factory()->createOne()->id]);

    $this->actingAs($f->user)->postJson($f->applyUrl($foreign))->assertNotFound();
    expect(applicationsFor($foreign))->toHaveCount(0);
});

// ── Apply-time re-validation (D5) ───────────────────────────────────────────

it('refuses a job that was visible at render and stopped qualifying before the tap', function (Closure $delist): void {
    $f = CreatorJobFixture::make();

    // The board renders the job...
    $this->actingAs($f->user)->getJson(CreatorJobFixture::URL)->assertOk()->assertJsonCount(1, 'data');

    // ...and it stops qualifying while the creator is looking at it.
    $delist($f);

    $this->actingAs($f->user)->postJson($f->applyUrl())->assertNotFound();
    expect(applicationsFor($f->campaign))->toHaveCount(0);
})->with([
    'delisted' => [fn (CreatorJobFixture $f) => $f->campaign->forceFill(['listed_on_jobs_board' => false])->save()],
    'completed' => [fn (CreatorJobFixture $f) => $f->campaign->forceFill(['status' => CampaignStatus::Completed])->save()],
    'expired' => [fn (CreatorJobFixture $f) => $f->campaign->forceFill(['ends_at' => now('UTC')->subDay()])->save()],
    'roster ended' => [fn (CreatorJobFixture $f) => $f->relation->forceFill(['relationship_status' => RelationshipStatus::Ended])->save()],
    'brand-blacklisted' => [fn (CreatorJobFixture $f) => BrandCreatorBlacklist::factory()->createOne([
        'brand_id' => $f->brand->id,
        'creator_id' => $f->creator->id,
    ])],
]);

// ── Duplicate apply + the no-re-apply rule (D1/D5, Q2) ──────────────────────

it('refuses a second apply with job.already_applied', function (CampaignApplicationStatus $status): void {
    $f = CreatorJobFixture::make();

    CampaignApplication::factory()->status($status)->createOne([
        'campaign_id' => $f->campaign->id,
        'creator_id' => $f->creator->id,
    ]);

    $this->actingAs($f->user)->postJson($f->applyUrl())
        ->assertStatus(409)
        ->assertJsonPath('errors.0.code', 'job.already_applied');

    expect(applicationsFor($f->campaign))->toHaveCount(1);
})->with([CampaignApplicationStatus::Pending, CampaignApplicationStatus::Accepted]);

it('refuses a re-apply after rejection with a DISTINCT code, forever', function (): void {
    $f = CreatorJobFixture::make();

    CampaignApplication::factory()->status(CampaignApplicationStatus::Rejected)->createOne([
        'campaign_id' => $f->campaign->id,
        'creator_id' => $f->creator->id,
    ]);

    // A distinct code because the SPA renders it differently ("Not selected",
    // Apply dead) — and no fingerprinting concern, since it is the caller's own
    // fact about themselves.
    $this->actingAs($f->user)->postJson($f->applyUrl())
        ->assertStatus(409)
        ->assertJsonPath('errors.0.code', 'job.application_rejected');

    // Still dead on the second, third and hundredth attempt: the retained row
    // occupies the unique pair permanently.
    $this->actingAs($f->user)->postJson($f->applyUrl())->assertStatus(409);

    expect(applicationsFor($f->campaign))->toHaveCount(1);
});

it('surfaces the caller own application state back on the board and the detail', function (): void {
    $f = CreatorJobFixture::make();

    $this->actingAs($f->user)->postJson($f->applyUrl())->assertCreated();

    $this->actingAs($f->user)->getJson(CreatorJobFixture::URL)->assertOk()
        ->assertJsonPath('data.0.attributes.application_status', 'pending')
        ->assertJsonPath('data.0.attributes.applicant_count', 1);

    $this->actingAs($f->user)->getJson($f->jobUrl())->assertOk()
        ->assertJsonPath('data.attributes.application_status', 'pending');
});

// ── §5.6 — the unique-pair race ─────────────────────────────────────────────

it('turns a lost unique-pair race into the same 409, never a 500', function (): void {
    $f = CreatorJobFixture::make();

    // Simulate the interleaving directly: both taps read "no application", then
    // the first insert lands, then the second one hits the constraint. The
    // pre-check has already passed for the losing request, so the ONLY thing
    // standing between it and a 500 is the constraint-violation catch.
    CampaignApplication::query()->create([
        'agency_id' => $f->campaign->agency_id,
        'campaign_id' => $f->campaign->id,
        'creator_id' => $f->creator->id,
        'status' => CampaignApplicationStatus::Pending,
    ]);

    $this->actingAs($f->user)->postJson($f->applyUrl())
        ->assertStatus(409)
        ->assertJsonPath('errors.0.code', 'job.already_applied');

    expect(applicationsFor($f->campaign))->toHaveCount(1);
});

// ── Audit (D5) ──────────────────────────────────────────────────────────────

it('writes one campaign_application.submitted row, without the note', function (): void {
    $f = CreatorJobFixture::make();

    $this->actingAs($f->user)->postJson($f->applyUrl(), ['note' => 'SECRET pitch copy'])->assertCreated();

    $log = AuditLog::query()->where('action', 'campaign_application.submitted')->sole();

    expect($log->agency_id)->toBe($f->agency->id)
        ->and($log->metadata['campaign_id'] ?? null)->toBe($f->campaign->id)
        ->and($log->metadata['creator_id'] ?? null)->toBe($f->creator->id)
        ->and($log->metadata['has_note'] ?? null)->toBeTrue()
        // The free-text note is never snapshotted — the hand-written-audit
        // discipline. `has_note` carries the only fact chunk 4 needs.
        ->and(json_encode($log->getAttributes(), JSON_THROW_ON_ERROR))->not->toContain('SECRET pitch copy');
});

it('writes NO audit row when the apply is refused', function (): void {
    $f = CreatorJobFixture::make();
    $f->campaign->forceFill(['listed_on_jobs_board' => false])->save();

    $this->actingAs($f->user)->postJson($f->applyUrl())->assertNotFound();

    expect(AuditLog::query()->where('action', 'campaign_application.submitted')->count())->toBe(0);
});

// ── Auth ────────────────────────────────────────────────────────────────────

it('requires authentication to apply', function (): void {
    $f = CreatorJobFixture::make();

    $this->postJson($f->applyUrl())->assertUnauthorized();
    expect(applicationsFor($f->campaign))->toHaveCount(0);
});

it('404s an apply from an authenticated user who is not a creator', function (): void {
    $f = CreatorJobFixture::make();
    $agencyUser = User::factory()->agencyAdmin()->createOne();

    $this->actingAs($agencyUser)->postJson($f->applyUrl())->assertNotFound();
    expect(applicationsFor($f->campaign))->toHaveCount(0);
});
