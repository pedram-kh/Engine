<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Enums\CampaignStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Identity\Models\User;
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
