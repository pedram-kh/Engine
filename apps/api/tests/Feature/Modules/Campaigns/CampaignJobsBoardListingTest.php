<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Enums\CampaignStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Identity\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Jobs board, agency side (AH-054) — the listing fields (D2), the
 * completeness gate (D3), the terminal-status block (D5) and the read-time
 * visibility predicate (D1/D5).
 *
 * Nothing here asserts a creator-visible effect: the feature ships dark (D10)
 * and the flag has no consumer outside {@see Campaign::scopeListedOnJobsBoard}.
 */

/**
 * @return array{agency: Agency, admin: User, campaign: Campaign}
 */
function jobsBoardFixture(string $factoryState = 'jobReady'): array
{
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $campaign = Campaign::factory()
        ->forAgency($agency->id)
        ->{$factoryState}()
        ->createOne();

    return ['agency' => $agency, 'admin' => $admin, 'campaign' => $campaign];
}

function campaignUrl(Agency $agency, Campaign $campaign): string
{
    return "/api/v1/agencies/{$agency->ulid}/campaigns/{$campaign->ulid}";
}

// ── D2 — the listing fields on the wire ─────────────────────────────────────

it('accepts the listing fields on create but never lists a fresh campaign (D2/D4)', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();

    $response = $this->actingAs($admin)->postJson("/api/v1/agencies/{$agency->ulid}/campaigns", [
        'brand_id' => $brand->ulid,
        'name' => 'Autumn UGC',
        'description' => 'Two Reels a month.',
        'budget_minor_units' => 500_000,
        'budget_currency' => 'EUR',
        'listing_duration' => '4 weeks',
        'listing_fee' => '€300 per video',
        'listing_languages' => ['en', 'ga'],
        'listing_regions' => ['IE', 'PT'],
        'listing_examples_url' => 'https://example.com/refs',
        // Forged: create must never list, whatever the payload claims (D4).
        'listed_on_jobs_board' => true,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.attributes.listing_duration', '4 weeks')
        ->assertJsonPath('data.attributes.listing_fee', '€300 per video')
        ->assertJsonPath('data.attributes.listing_languages', ['en', 'ga'])
        ->assertJsonPath('data.attributes.listing_regions', ['IE', 'PT'])
        ->assertJsonPath('data.attributes.listing_examples_url', 'https://example.com/refs')
        ->assertJsonPath('data.attributes.listed_on_jobs_board', false);

    expect(Campaign::query()->where('agency_id', $agency->id)->firstOrFail()->listed_on_jobs_board)->toBeFalse();
});

it('uppercase-normalises region codes before storing them', function (): void {
    ['agency' => $agency, 'admin' => $admin, 'campaign' => $campaign] = jobsBoardFixture();

    $this->actingAs($admin)
        ->patchJson(campaignUrl($agency, $campaign), ['listing_regions' => ['ie', 'fr']])
        ->assertOk()
        ->assertJsonPath('data.attributes.listing_regions', ['IE', 'FR']);

    expect($campaign->fresh()?->listing_regions)->toBe(['IE', 'FR']);
});

it('rejects a non-EU listing language, a malformed region and an over-long duration', function (): void {
    ['agency' => $agency, 'admin' => $admin, 'campaign' => $campaign] = jobsBoardFixture();

    // 'ja' is a world language but NOT one of the 24 EU production languages.
    $this->actingAs($admin)
        ->patchJson(campaignUrl($agency, $campaign), [
            'listing_languages' => ['ja'],
            'listing_regions' => ['IRL'],
            'listing_duration' => str_repeat('x', 121),
        ])
        ->assertUnprocessable()
        ->assertEnvelopeValidationErrors(['listing_languages.0', 'listing_regions.0', 'listing_duration']);
});

it('rejects duplicate entries and over-long lists', function (): void {
    ['agency' => $agency, 'admin' => $admin, 'campaign' => $campaign] = jobsBoardFixture();

    $this->actingAs($admin)
        ->patchJson(campaignUrl($agency, $campaign), [
            'listing_languages' => ['en', 'en'],
            'listing_regions' => array_fill(0, 61, 'IE'),
        ])
        ->assertUnprocessable();
});

it('preserves stored listing fields when the edit omits them', function (): void {
    ['agency' => $agency, 'admin' => $admin, 'campaign' => $campaign] = jobsBoardFixture();

    $before = $campaign->only(['listing_duration', 'listing_fee', 'listing_languages', 'listing_regions', 'listing_examples_url']);

    $this->actingAs($admin)
        ->patchJson(campaignUrl($agency, $campaign), ['name' => 'Renamed only'])
        ->assertOk();

    expect($campaign->fresh()?->only(array_keys($before)))->toBe($before);
});

// ── D3 — the completeness gate ──────────────────────────────────────────────

it('refuses to list a campaign that is missing listing fields, naming every one (D3)', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    // Bare campaign: no description, no listing copy at all.
    $campaign = Campaign::factory()->forAgency($agency->id)->createOne(['description' => null]);

    $this->actingAs($admin)
        ->patchJson(campaignUrl($agency, $campaign), ['listed_on_jobs_board' => true])
        ->assertUnprocessable()
        ->assertEnvelopeValidationErrors([
            'description',
            'listing_duration',
            'listing_fee',
            'listing_languages',
            'listing_regions',
        ]);

    expect($campaign->fresh()?->listed_on_jobs_board)->toBeFalse();
});

it('lists a campaign once every floor field is present (D3 happy path)', function (): void {
    ['agency' => $agency, 'admin' => $admin, 'campaign' => $campaign] = jobsBoardFixture();

    $this->actingAs($admin)
        ->patchJson(campaignUrl($agency, $campaign), ['listed_on_jobs_board' => true])
        ->assertOk()
        ->assertJsonPath('data.attributes.listed_on_jobs_board', true);

    expect($campaign->fresh()?->listed_on_jobs_board)->toBeTrue();
});

it('lets one request fill the floor and flip the switch together', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $campaign = Campaign::factory()->forAgency($agency->id)->createOne(['description' => null]);

    $this->actingAs($admin)
        ->patchJson(campaignUrl($agency, $campaign), [
            'description' => 'One Reel a week.',
            'listing_duration' => '8 weeks',
            'listing_fee' => '€500 per Reel',
            'listing_languages' => ['en'],
            'listing_regions' => ['IE'],
            'listed_on_jobs_board' => true,
        ])
        ->assertOk()
        ->assertJsonPath('data.attributes.listed_on_jobs_board', true);
});

it('refuses to gut a LISTED campaign — the gate judges the resulting state, not the transition (D3)', function (): void {
    ['agency' => $agency, 'admin' => $admin, 'campaign' => $campaign] = jobsBoardFixture('listed');

    // The Settings form re-sends the whole payload; clearing the description
    // while staying listed must be refused, not silently accepted.
    $this->actingAs($admin)
        ->patchJson(campaignUrl($agency, $campaign), [
            'description' => '',
            'listed_on_jobs_board' => true,
        ])
        ->assertUnprocessable()
        ->assertEnvelopeValidationErrors(['description']);

    expect($campaign->fresh()?->description)->not->toBe('');
});

it('treats a whitespace-only value as empty (the isFilled agreement)', function (): void {
    ['agency' => $agency, 'admin' => $admin, 'campaign' => $campaign] = jobsBoardFixture();

    $this->actingAs($admin)
        ->patchJson(campaignUrl($agency, $campaign), [
            'listing_fee' => '   ',
            'listed_on_jobs_board' => true,
        ])
        ->assertUnprocessable()
        ->assertEnvelopeValidationErrors(['listing_fee']);
});

it('treats an emptied array as a missing floor field', function (): void {
    ['agency' => $agency, 'admin' => $admin, 'campaign' => $campaign] = jobsBoardFixture();

    $this->actingAs($admin)
        ->patchJson(campaignUrl($agency, $campaign), [
            'listing_regions' => [],
            'listed_on_jobs_board' => true,
        ])
        ->assertUnprocessable()
        ->assertEnvelopeValidationErrors(['listing_regions']);
});

it('never gates a toggle-OFF, even from an incomplete state (D3)', function (): void {
    ['agency' => $agency, 'admin' => $admin, 'campaign' => $campaign] = jobsBoardFixture('listed');

    // Wipe the copy AND unlist in one request: unlisting is always allowed.
    $this->actingAs($admin)
        ->patchJson(campaignUrl($agency, $campaign), [
            'listing_fee' => null,
            'listed_on_jobs_board' => false,
        ])
        ->assertOk()
        ->assertJsonPath('data.attributes.listed_on_jobs_board', false);

    expect($campaign->fresh()?->listing_fee)->toBeNull();
});

it('leaves an UNLISTED campaign free to hold partial listing copy (D3)', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $campaign = Campaign::factory()->forAgency($agency->id)->createOne();

    $this->actingAs($admin)
        ->patchJson(campaignUrl($agency, $campaign), ['listing_duration' => '2 weeks'])
        ->assertOk()
        ->assertJsonPath('data.attributes.listing_duration', '2 weeks');
});

// ── D5 — the terminal-status block ──────────────────────────────────────────

it('refuses to switch listing ON for a completed or cancelled campaign (D5)', function (CampaignStatus $status): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $campaign = Campaign::factory()->forAgency($agency->id)->jobReady()->createOne(['status' => $status]);

    $this->actingAs($admin)
        ->patchJson(campaignUrl($agency, $campaign), ['listed_on_jobs_board' => true])
        ->assertUnprocessable()
        ->assertEnvelopeValidationErrors(['listed_on_jobs_board']);

    expect($campaign->fresh()?->listed_on_jobs_board)->toBeFalse();
})->with([
    'completed' => CampaignStatus::Completed,
    'cancelled' => CampaignStatus::Cancelled,
]);

it('refuses a request that ends the campaign and lists it in one move (D5)', function (): void {
    ['agency' => $agency, 'admin' => $admin, 'campaign' => $campaign] = jobsBoardFixture();

    $this->actingAs($admin)
        ->patchJson(campaignUrl($agency, $campaign), [
            'status' => CampaignStatus::Completed->value,
            'listed_on_jobs_board' => true,
        ])
        ->assertUnprocessable()
        ->assertEnvelopeValidationErrors(['listed_on_jobs_board']);
});

it('permits listing while draft, active or paused (D5 — the complete positive set)', function (CampaignStatus $status): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $campaign = Campaign::factory()->forAgency($agency->id)->jobReady()->createOne(['status' => $status]);

    $this->actingAs($admin)
        ->patchJson(campaignUrl($agency, $campaign), ['listed_on_jobs_board' => true])
        ->assertOk()
        ->assertJsonPath('data.attributes.listed_on_jobs_board', true);
})->with([
    'draft' => CampaignStatus::Draft,
    'active' => CampaignStatus::Active,
    'paused' => CampaignStatus::Paused,
]);

it('lets a LISTED campaign move to a terminal status and leaves the flag untouched (D5/A1 — no auto-clear)', function (CampaignStatus $status): void {
    ['agency' => $agency, 'admin' => $admin, 'campaign' => $campaign] = jobsBoardFixture('listed');

    $this->actingAs($admin)
        ->patchJson(campaignUrl($agency, $campaign), ['status' => $status->value])
        ->assertOk()
        ->assertJsonPath('data.attributes.status', $status->value)
        ->assertJsonPath('data.attributes.listed_on_jobs_board', true);

    // The stored intent survives: reopening the campaign re-lights the listing
    // rather than silently losing it.
    expect($campaign->fresh()?->listed_on_jobs_board)->toBeTrue();
})->with([
    'completed' => CampaignStatus::Completed,
    'cancelled' => CampaignStatus::Cancelled,
]);

it('keeps a terminal LISTED campaign editable (the flag is re-sent, not re-toggled)', function (): void {
    ['agency' => $agency, 'admin' => $admin, 'campaign' => $campaign] = jobsBoardFixture('listed');
    $campaign->forceFill(['status' => CampaignStatus::Completed])->save();

    // The Settings form always re-sends the whole payload — a no-change `true`
    // must not be mistaken for an attempt to switch listing on.
    $this->actingAs($admin)
        ->patchJson(campaignUrl($agency, $campaign), [
            'name' => 'Wrapped up',
            'listed_on_jobs_board' => true,
        ])
        ->assertOk()
        ->assertJsonPath('data.attributes.name', 'Wrapped up');
});

// ── Audit (F3) ──────────────────────────────────────────────────────────────

it('records the visibility flip in the campaign.updated audit snapshot, without the listing copy', function (): void {
    ['agency' => $agency, 'admin' => $admin, 'campaign' => $campaign] = jobsBoardFixture();

    $this->actingAs($admin)
        ->patchJson(campaignUrl($agency, $campaign), ['listed_on_jobs_board' => true])
        ->assertOk();

    $log = AuditLog::query()
        ->where('action', 'campaign.updated')
        ->where('subject_id', $campaign->id)
        ->latest('id')
        ->firstOrFail();

    expect($log->before['listed_on_jobs_board'] ?? null)->toBeFalse()
        ->and($log->after['listed_on_jobs_board'] ?? null)->toBeTrue()
        // Agency-authored content stays out of audit rows (the `brief` posture).
        ->and($log->after)->not->toHaveKey('listing_fee')
        ->and($log->after)->not->toHaveKey('listing_duration');
});

// ── D3 (AH-059) — the campaigns-list surface's single-key PATCH ─────────────
//
// The list page's Job board switch sends `{listed_on_jobs_board}` and NOTHING
// else. Every rule it depends on already existed and was proven for the Settings
// tab; what these cases add is the proof that the endpoint behaves the same way
// when it is the ONLY key in the body, because that is the shape a table row can
// safely write with — and because "the two surfaces share one gate" is otherwise
// a claim about the import graph.

it('a SINGLE-KEY listing PATCH blanks nothing — every other column survives by omission', function (): void {
    ['agency' => $agency, 'admin' => $admin, 'campaign' => $campaign] = jobsBoardFixture();

    // A campaign carrying a value in every column the Settings form governs, so
    // an accidental blanking has something to blank.
    $campaign->forceFill([
        'brief' => 'The internal brief, which Settings deliberately never re-sends.',
        'target_creator_count' => 7,
        'listing_examples_url' => 'https://examples.test/reel',
        'requires_per_campaign_contract' => true,
    ])->save();

    $before = $campaign->fresh()?->only([
        'name', 'description', 'objective', 'status', 'brief', 'target_creator_count',
        'budget_minor_units', 'budget_currency', 'starts_at', 'ends_at',
        'requires_per_campaign_contract', 'is_marketplace_visible',
        'listing_duration', 'listing_fee', 'listing_languages', 'listing_regions',
        'listing_examples_url',
    ]);

    $this->actingAs($admin)
        ->patchJson(campaignUrl($agency, $campaign), ['listed_on_jobs_board' => true])
        ->assertOk();

    $after = $campaign->fresh();

    // The one intended change…
    expect($after?->listed_on_jobs_board)->toBeTrue()
        // …and nothing else moved. This is what the endpoint's `sometimes` rules
        // buy, and it is the whole safety argument for writing from a table row:
        // the switch cannot reach a field it does not render.
        ->and($after?->only(array_keys($before ?? [])))->toBe($before);
});

it('judges a single-key flip against the STORED row, so an incomplete floor still refuses', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    // Floor complete except for one field. The list page's local mirror would
    // catch this first, but the mirror is a courtesy — the authority is here, and
    // it must reach the same verdict from a body that names only the switch.
    $campaign = Campaign::factory()->forAgency($agency->id)->jobReady()->createOne([
        'listing_fee' => null,
    ]);

    $this->actingAs($admin)
        ->patchJson(campaignUrl($agency, $campaign), ['listed_on_jobs_board' => true])
        ->assertUnprocessable()
        ->assertEnvelopeValidationErrors(['listing_fee']);

    expect($campaign->fresh()?->listed_on_jobs_board)->toBeFalse();
});

it('§5.34 STAFF NEGATIVE: a staff member cannot flip the listing from anywhere', function (): void {
    ['agency' => $agency, 'campaign' => $campaign] = jobsBoardFixture();

    $staff = User::factory()->agencyStaff($agency)->createOne();

    // Staff may EXECUTE campaigns (they can invite creators — CampaignPolicy's
    // deliberately broader `invite` ability), but listing a job to the whole
    // roster is a management act. The new surface inherits that gate rather than
    // introducing its own: same policy, same 403, no listing-specific carve-out.
    $this->actingAs($staff)
        ->patchJson(campaignUrl($agency, $campaign), ['listed_on_jobs_board' => true])
        ->assertForbidden();

    expect($campaign->fresh()?->listed_on_jobs_board)->toBeFalse();
});

it('repeated flips are SAFE — relisting refreshes the recency chip, and the fan-out stays idempotent for an unrelated reason', function (): void {
    ['agency' => $agency, 'admin' => $admin, 'campaign' => $campaign] = jobsBoardFixture();

    $url = campaignUrl($agency, $campaign);

    // AH-070 correction: this test previously asserted `listed_at` stays
    // pinned to the FIRST flip. That contradicts the documented design
    // (`jobs-board-c3-review.md` D4: "written only on the false → true
    // flip" — i.e. every such flip, since it is recency display metadata,
    // not an idempotency stamp — {@see CampaignController::update()}). The
    // job-posted fan-out's real once-only guarantee is a completely separate
    // mechanism, the `campaign_job_notifications` stamp table, asserted in
    // {@see JobPostedFanOutTest} ("sends nothing when a campaign is delisted
    // and RE-LISTED") — it never reads `listed_at` at all. The old assertion
    // only ever passed because three fast requests usually land within the
    // same wall-clock second; a slower CI runner finally crossed a second
    // boundary and it failed for the first time on 2026-08-17. Fixed here to
    // assert the actual, intended behaviour with a frozen clock rather than
    // real time, so it is correct AND deterministic.
    Carbon::setTestNow(Carbon::parse('2026-01-01T00:00:00Z'));

    $this->actingAs($admin)->patchJson($url, ['listed_on_jobs_board' => true])->assertOk();

    $firstListedAt = $campaign->fresh()?->listed_at;
    expect($firstListedAt?->toIso8601String())->toBe('2026-01-01T00:00:00+00:00');

    $this->actingAs($admin)->patchJson($url, ['listed_on_jobs_board' => false])->assertOk();

    Carbon::setTestNow(Carbon::parse('2026-01-02T00:00:00Z'));
    $this->actingAs($admin)->patchJson($url, ['listed_on_jobs_board' => true])->assertOk();

    $relistedAt = $campaign->fresh()?->listed_at?->toIso8601String();
    expect($relistedAt)->toBe('2026-01-02T00:00:00+00:00');
    expect($relistedAt)->not->toBe($firstListedAt?->toIso8601String());

    Carbon::setTestNow();
});

it('audits a single-key flip exactly as it audits the Settings save', function (): void {
    ['agency' => $agency, 'admin' => $admin, 'campaign' => $campaign] = jobsBoardFixture();

    $this->actingAs($admin)
        ->patchJson(campaignUrl($agency, $campaign), ['listed_on_jobs_board' => true])
        ->assertOk();

    $log = AuditLog::query()
        ->where('action', 'campaign.updated')
        ->where('subject_id', $campaign->id)
        ->latest('id')
        ->firstOrFail();

    // Same snapshot, same shape, from a body with one key in it — the audit trail
    // cannot tell which surface pressed the switch, and it does not need to.
    expect($log->before['listed_on_jobs_board'] ?? null)->toBeFalse()
        ->and($log->after['listed_on_jobs_board'] ?? null)->toBeTrue()
        ->and($log->after)->not->toHaveKey('listing_fee');
});

// ── D1/D5 — the read-time visibility predicate (S2) ─────────────────────────

it('scopeListedOnJobsBoard returns a listed, non-terminal campaign', function (): void {
    $agency = Agency::factory()->createOne();
    Campaign::factory()->forAgency($agency->id)->listed()->createOne();

    expect(Campaign::query()->listedOnJobsBoard()->count())->toBe(1);
});

it('scopeListedOnJobsBoard excludes every non-qualifying case (§5.34 — disjoint and complete)', function (
    string $case,
    array $overrides,
): void {
    $agency = Agency::factory()->createOne();
    Campaign::factory()->forAgency($agency->id)->listed()->createOne($overrides);

    expect(Campaign::query()->listedOnJobsBoard()->count())->toBe(0, "case: {$case}");
})->with([
    // Eligible in every respect except one, one row per exclusion reason.
    ['flag off', ['listed_on_jobs_board' => false]],
    ['completed', ['status' => CampaignStatus::Completed]],
    ['cancelled', ['status' => CampaignStatus::Cancelled]],
]);

it('scopeListedOnJobsBoard admits each listable status (the positive partition)', function (CampaignStatus $status): void {
    $agency = Agency::factory()->createOne();
    Campaign::factory()->forAgency($agency->id)->listed()->createOne(['status' => $status]);

    expect(Campaign::query()->listedOnJobsBoard()->count())->toBe(1);
})->with([
    'draft' => CampaignStatus::Draft,
    'active' => CampaignStatus::Active,
    'paused' => CampaignStatus::Paused,
]);

it('pins LISTABLE_STATUSES as the complement of the terminal statuses', function (): void {
    $all = array_map(static fn (CampaignStatus $s): string => $s->value, CampaignStatus::cases());

    expect(Campaign::LISTABLE_STATUSES)->toEqualCanonicalizing(
        array_values(array_diff($all, ['completed', 'cancelled'])),
    );
});
