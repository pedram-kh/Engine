<?php

declare(strict_types=1);

use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Enums\CampaignApplicationStatus;
use App\Modules\Campaigns\Models\CampaignApplication;
use App\Modules\Creators\Features\CampaignRegionRestrictionEnabled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Pennant\Feature;
use Tests\Fixtures\JobsBoard\CreatorJobFixture;
use Tests\Fixtures\JobsBoard\ExclusivityFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * AH-120 A4 — brand exclusivity on the creator side: the detail annotation
 * (`is_brand_locked`, `brand_lock_reason`), the apply refusal (422
 * `job.brand_exclusive`), its place in the refusal order (after the duplicate
 * 409, before the region 422), and the leak rule: nothing the creator can read
 * names the blocking brand or campaign.
 */
beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-11 12:00:00', 'UTC'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function applicationCount(ExclusivityFixture $f): int
{
    return CampaignApplication::withoutGlobalScopes()->where('campaign_id', $f->job->campaign->id)->count();
}

// ── detail ──────────────────────────────────────────────────────────────────

it('keeps the job visible and marks it locked while a selected creator is blocked', function (): void {
    $f = ExclusivityFixture::make();
    $f->select(AssignmentStatus::Accepted);

    $this->actingAs($f->job->user)->getJson($f->job->jobUrl())->assertOk()
        ->assertJsonPath('data.attributes.is_brand_locked', true)
        ->assertJsonPath('data.attributes.brand_lock_reason', 'brand_exclusive');
});

it('reports an unlocked detail when nothing blocks', function (): void {
    $f = ExclusivityFixture::make();

    $this->actingAs($f->job->user)->getJson($f->job->jobUrl())->assertOk()
        ->assertJsonPath('data.attributes.is_brand_locked', false)
        ->assertJsonPath('data.attributes.brand_lock_reason', null);
});

it('reports an unlocked detail while the flag is OFF, whatever the data says', function (): void {
    $f = ExclusivityFixture::make(flag: false);
    $f->select(AssignmentStatus::Accepted);

    $this->actingAs($f->job->user)->getJson($f->job->jobUrl())->assertOk()
        ->assertJsonPath('data.attributes.is_brand_locked', false);
});

it('does not lock the detail for a creator who is not selected on the blocking campaign', function (): void {
    $f = ExclusivityFixture::make();
    $f->select(AssignmentStatus::Invited);

    $this->actingAs($f->job->user)->getJson($f->job->jobUrl())->assertOk()
        ->assertJsonPath('data.attributes.is_brand_locked', false);
});

it('leaves the job list untouched: the card carries no lock key and the job is still listed', function (): void {
    $f = ExclusivityFixture::make();
    $f->select(AssignmentStatus::Accepted);

    $response = $this->actingAs($f->job->user)->getJson('/api/v1/creators/me/jobs')->assertOk();
    $ids = $response->json('data.*.id');
    $index = array_search($f->job->campaign->ulid, $ids, true);

    expect($index)->not->toBeFalse();

    $response->assertJsonMissingPath("data.{$index}.attributes.is_brand_locked")
        ->assertJsonMissingPath("data.{$index}.attributes.brand_lock_reason");
});

// ── apply ───────────────────────────────────────────────────────────────────

it('refuses an apply from a blocked creator with 422 job.brand_exclusive, and writes nothing', function (): void {
    $f = ExclusivityFixture::make();
    $f->select(AssignmentStatus::Accepted);

    $this->actingAs($f->job->user)->postJson($f->job->applyUrl())
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'job.brand_exclusive')
        ->assertJsonPath('errors.0.meta.reason', 'brand_exclusive');

    expect(applicationCount($f))->toBe(0);
});

it('lets a creator apply when nothing blocks them (the negative base)', function (): void {
    $f = ExclusivityFixture::make();

    $this->actingAs($f->job->user)->postJson($f->job->applyUrl())->assertCreated();

    expect(applicationCount($f))->toBe(1);
});

it('lets an applicant through once the block ends (declined, and a verified post with no date)', function (): void {
    foreach ([AssignmentStatus::Declined, AssignmentStatus::Rejected, AssignmentStatus::Cancelled, AssignmentStatus::LiveVerified] as $status) {
        $f = ExclusivityFixture::make();
        $f->select($status);

        $this->actingAs($f->job->user)->postJson($f->job->applyUrl())->assertCreated();
    }
});

it('lets an applicant through while the flag is OFF', function (): void {
    $f = ExclusivityFixture::make(flag: false);
    $f->select(AssignmentStatus::Accepted);

    $this->actingAs($f->job->user)->postJson($f->job->applyUrl())->assertCreated();
});

it('answers an existing application with the 409 before the exclusivity 422', function (): void {
    $f = ExclusivityFixture::make();
    CampaignApplication::query()->withoutGlobalScopes()->create([
        'agency_id' => $f->job->campaign->agency_id,
        'campaign_id' => $f->job->campaign->id,
        'creator_id' => $f->job->creator->id,
        'status' => CampaignApplicationStatus::Pending,
    ]);
    $f->select(AssignmentStatus::Accepted);

    $this->actingAs($f->job->user)->postJson($f->job->applyUrl())
        ->assertStatus(409)->assertJsonPath('errors.0.code', 'job.already_applied');
});

it('answers exclusivity before the region lock (Q3) when both would refuse', function (): void {
    $f = ExclusivityFixture::make();
    Feature::activate(CampaignRegionRestrictionEnabled::NAME);
    $f->job->campaign->forceFill(['restrict_to_listing_regions' => true])->save();
    $f->job->creator->forceFill(['country_code' => 'PT'])->save();
    $f->select(AssignmentStatus::Accepted);

    $this->actingAs($f->job->user)->postJson($f->job->applyUrl())
        ->assertStatus(422)->assertJsonPath('errors.0.code', 'job.brand_exclusive');

    // …and with the exclusivity gone, the region refusal is what remains.
    $g = ExclusivityFixture::make();
    Feature::activate(CampaignRegionRestrictionEnabled::NAME);
    $g->job->campaign->forceFill(['restrict_to_listing_regions' => true])->save();
    $g->job->creator->forceFill(['country_code' => 'PT'])->save();

    $this->actingAs($g->job->user)->postJson($g->job->applyUrl())
        ->assertStatus(422)->assertJsonPath('errors.0.code', 'job.region_not_allowed');
});

it('does not block another creator who is not selected on the blocking campaign', function (): void {
    $f = ExclusivityFixture::make();
    $f->select(AssignmentStatus::Accepted);
    $other = CreatorJobFixture::make();

    // A different agency, different creator: untouched.
    $this->actingAs($other->user)->postJson($other->applyUrl())->assertCreated();
});

// ── the leak rule ───────────────────────────────────────────────────────────

it('never names the blocking brand or campaign in anything the creator can read', function (): void {
    $f = ExclusivityFixture::make();
    $f->select(AssignmentStatus::Accepted);

    $needles = [
        'Zava',
        ExclusivityFixture::ZAVA_CAMPAIGN_NAME,
        $f->zava->ulid,
        $f->zavaCampaign->ulid,
    ];

    $bodies = [
        $this->actingAs($f->job->user)->getJson($f->job->jobUrl())->getContent(),
        $this->actingAs($f->job->user)->getJson('/api/v1/creators/me/jobs')->getContent(),
        $this->actingAs($f->job->user)->postJson($f->job->applyUrl())->getContent(),
    ];

    foreach ($bodies as $body) {
        foreach ($needles as $needle) {
            expect($body)->not->toContain($needle);
        }
    }
});
