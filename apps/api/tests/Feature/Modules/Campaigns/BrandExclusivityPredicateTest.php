<?php

declare(strict_types=1);

use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Enums\CampaignStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Campaigns\Services\BrandExclusivity;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Models\Creator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Fixtures\JobsBoard\ExclusivityFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * AH-120 A2 — the predicate, its bulk read and its SQL mirror.
 *
 * §5.34: every negative starts from the SAME base (a creator selected on a Zava
 * campaign that blocks the Yahoo brand, so the creator IS blocked) and breaks
 * exactly one leg. The three forms (PHP, bulk read, SQL mirror) are asserted
 * against each other on every case, which is the parity proof.
 */
function exclusivity(): BrandExclusivity
{
    return app(BrandExclusivity::class);
}

/**
 * All three forms for ($f->job->campaign, $f->job->creator), asserted equal.
 * Returns the answer.
 */
function blockedByAllForms(ExclusivityFixture $f): bool
{
    $creator = Creator::query()->findOrFail($f->job->creator->id);
    $campaign = Campaign::query()->withoutGlobalScopes()->findOrFail($f->job->campaign->id);

    $php = exclusivity()->isBlocked($campaign, $creator);
    $bulk = array_key_exists($creator->id, exclusivity()->conflictsFor($campaign, [$creator->id]));

    $query = Creator::query()->whereKey($creator->id);
    exclusivity()->excludeBlockedCreators($query, $campaign);
    $sqlBlocked = $query->doesntExist();

    expect($bulk)->toBe($php, 'bulk read disagrees with the PHP predicate')
        ->and($sqlBlocked)->toBe($php, 'SQL mirror disagrees with the PHP predicate');

    return $php;
}

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-11 12:00:00', 'UTC'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

// ── the positive base ───────────────────────────────────────────────────────

it('blocks a creator selected on a campaign that names the brand, and returns that brand id', function (): void {
    $f = ExclusivityFixture::make();
    $f->select();

    expect(blockedByAllForms($f))->toBeTrue()
        ->and(exclusivity()->blockedBrandIdsFor(Creator::query()->findOrFail($f->job->creator->id)))->toBe([$f->job->brand->id]);
});

it('names the blocking campaign for the agency, and the earliest when there are two', function (): void {
    $f = ExclusivityFixture::make();
    $f->select();

    $second = Campaign::factory()->blocking([$f->job->brand])->createOne([
        'agency_id' => $f->job->agency->id,
        'brand_id' => $f->zava->id,
        'name' => 'Zava autumn',
        'status' => CampaignStatus::Active,
    ]);
    CampaignAssignment::factory()->createOne([
        'campaign_id' => $second->id,
        'creator_id' => $f->job->creator->id,
        'status' => AssignmentStatus::Producing,
    ]);

    $conflicts = exclusivity()->conflictsFor(
        Campaign::query()->withoutGlobalScopes()->findOrFail($f->job->campaign->id),
        [$f->job->creator->id],
    );

    expect($conflicts[$f->job->creator->id])->toBe([
        'campaign_ulid' => $f->zavaCampaign->ulid,
        'campaign_name' => ExclusivityFixture::ZAVA_CAMPAIGN_NAME,
    ]);
});

// ── the state classes (one case per AssignmentStatus) ───────────────────────

it('blocks in every selected state and in no other (no end date, campaign active)', function (): void {
    foreach (AssignmentStatus::cases() as $status) {
        $f = ExclusivityFixture::make();
        $f->select($status);

        // With no date, the block holds in the active states and ends at completion.
        $expected = $status->countsAsSelected() && ! $status->isCompletedForExclusivity();

        expect(blockedByAllForms($f))->toBe($expected, "status {$status->value}");
    }
});

it('blocks in every selected state, completed ones included, while a future end date holds', function (): void {
    foreach (AssignmentStatus::cases() as $status) {
        $f = ExclusivityFixture::make('2026-12-31');
        $f->select($status);

        expect(blockedByAllForms($f))->toBe($status->countsAsSelected(), "status {$status->value}");
    }
});

it('lets revision_requested block (an active state, not a completed one)', function (): void {
    $f = ExclusivityFixture::make();
    $f->select(AssignmentStatus::RevisionRequested);

    expect(blockedByAllForms($f))->toBeTrue();
});

it('ends the block the moment the work is verified, with no end date (Q1)', function (): void {
    foreach ([AssignmentStatus::LiveVerified, AssignmentStatus::ManuallyVerified, AssignmentStatus::PaymentHeld, AssignmentStatus::PaymentReleased, AssignmentStatus::CompletedOnApproval] as $status) {
        $f = ExclusivityFixture::make();
        $f->select($status);

        expect(blockedByAllForms($f))->toBeFalse("status {$status->value}");
    }
});

// ── the date ────────────────────────────────────────────────────────────────

it('blocks through the end date, inclusive to the end of that day, and not after it', function (): void {
    // today (UTC) is 2026-11-11
    $today = ExclusivityFixture::make('2026-11-11');
    $today->select();
    expect(blockedByAllForms($today))->toBeTrue('the end date itself is still blocked');

    $yesterday = ExclusivityFixture::make('2026-11-10');
    $yesterday->select();
    expect(blockedByAllForms($yesterday))->toBeFalse('the day after the end date is free');
});

it('lets an explicit end date win over an assignment that has not completed (past date frees an active creator)', function (): void {
    $f = ExclusivityFixture::make('2026-11-10');
    $f->select(AssignmentStatus::Producing);

    expect(blockedByAllForms($f))->toBeFalse();
});

it('lets a future end date keep a completed creator blocked', function (): void {
    $f = ExclusivityFixture::make('2027-03-31');
    $f->select(AssignmentStatus::PaymentReleased);

    expect(blockedByAllForms($f))->toBeTrue();
});

it('reads the end date as a UTC calendar date at the day boundary', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-11 23:59:59', 'UTC'));
    $f = ExclusivityFixture::make('2026-11-11');
    $f->select();
    expect(blockedByAllForms($f))->toBeTrue();

    Carbon::setTestNow(Carbon::parse('2026-11-12 00:00:00', 'UTC'));
    expect(blockedByAllForms($f))->toBeFalse();
});

// ── the blocking campaign's own status (Q2, with the date winning) ──────────

it('ends the block always when the blocking campaign is cancelled, even with a future date', function (): void {
    $noDate = ExclusivityFixture::make(null, true, ['status' => CampaignStatus::Cancelled]);
    $noDate->select();
    expect(blockedByAllForms($noDate))->toBeFalse();

    $dated = ExclusivityFixture::make('2027-03-31', true, ['status' => CampaignStatus::Cancelled]);
    $dated->select();
    expect(blockedByAllForms($dated))->toBeFalse();
});

it('ends the block when the blocking campaign is completed and no date is set, but an explicit date wins', function (): void {
    $noDate = ExclusivityFixture::make(null, true, ['status' => CampaignStatus::Completed]);
    $noDate->select(AssignmentStatus::Producing);
    expect(blockedByAllForms($noDate))->toBeFalse();

    $dated = ExclusivityFixture::make('2027-03-31', true, ['status' => CampaignStatus::Completed]);
    $dated->select(AssignmentStatus::Producing);
    expect(blockedByAllForms($dated))->toBeTrue();
});

it('blocks while the blocking campaign is draft or paused', function (): void {
    foreach ([CampaignStatus::Draft, CampaignStatus::Paused] as $status) {
        $f = ExclusivityFixture::make(null, true, ['status' => $status]);
        $f->select();
        expect(blockedByAllForms($f))->toBeTrue("campaign {$status->value}");
    }
});

// ── §5.34 disjoint negatives: each is eligible to block in every respect but one ─

it('does not block when the campaign names a different brand', function (): void {
    $f = ExclusivityFixture::make();
    $f->select();

    $other = Brand::factory()->forAgency($f->job->agency->id)->createOne(['name' => 'Other']);
    $f->zavaCampaign->exclusiveBrands()->sync([$other->id]);

    expect(blockedByAllForms($f))->toBeFalse();
});

it('does not block when the list is empty', function (): void {
    $f = ExclusivityFixture::make();
    $f->select();
    $f->zavaCampaign->exclusiveBrands()->sync([]);

    expect(blockedByAllForms($f))->toBeFalse();
});

it('does not block another creator, and does not read their assignment', function (): void {
    $f = ExclusivityFixture::make();
    $f->select();

    $bystander = CreatorFactory::new()->approved()->createOne();
    expect(exclusivity()->blockedBrandIdsFor($bystander))->toBe([])
        ->and(exclusivity()->conflictsFor(
            Campaign::query()->withoutGlobalScopes()->findOrFail($f->job->campaign->id),
            [$bystander->id],
        ))->toBe([]);
});

it('does not block when the assignment is soft-deleted', function (): void {
    $f = ExclusivityFixture::make();
    $f->select()->delete();

    expect(blockedByAllForms($f))->toBeFalse();
});

it('does not block when the blocking campaign is soft-deleted', function (): void {
    $f = ExclusivityFixture::make();
    $f->select();
    $f->zavaCampaign->delete();

    expect(blockedByAllForms($f))->toBeFalse();
});

it('does not block when the flag is off, whatever is stored', function (): void {
    $f = ExclusivityFixture::make(null, false);
    $f->select();

    expect(blockedByAllForms($f))->toBeFalse()
        ->and(exclusivity()->blockedBrandIdsFor(Creator::query()->findOrFail($f->job->creator->id)))->toBe([]);
});

it('does not report the blocking campaign as in conflict with itself', function (): void {
    $f = ExclusivityFixture::make();
    $f->select();

    // The Zava campaign is the one that blocks; its own brand is not on its list.
    $zava = Campaign::query()->withoutGlobalScopes()->findOrFail($f->zavaCampaign->id);
    expect(exclusivity()->conflictsFor($zava, [$f->job->creator->id]))->toBe([]);
});

it('lets one selection block several brands and keeps the list distinct', function (): void {
    $f = ExclusivityFixture::make();
    $f->select();

    $third = Brand::factory()->forAgency($f->job->agency->id)->createOne(['name' => 'Third']);
    $f->zavaCampaign->exclusiveBrands()->attach($third->id);

    $ids = exclusivity()->blockedBrandIdsFor(Creator::query()->findOrFail($f->job->creator->id));
    sort($ids);
    $expected = [$f->job->brand->id, $third->id];
    sort($expected);

    expect($ids)->toBe($expected);
});
