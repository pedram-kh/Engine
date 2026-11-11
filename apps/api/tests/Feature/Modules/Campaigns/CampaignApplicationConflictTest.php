<?php

declare(strict_types=1);

use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Enums\CampaignApplicationStatus;
use App\Modules\Campaigns\Models\CampaignApplication;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\JobsBoard\CreatorJobFixture;
use Tests\Fixtures\JobsBoard\ExclusivityFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * AH-120 A6 — the Applications tab's conflict badge (Q9): PENDING rows only,
 * naming the blocking campaign to the agency, one query for the page. Existing
 * applications are never touched (ruling 6).
 */
beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-11 12:00:00', 'UTC'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function applicationOf(ExclusivityFixture $f, CampaignApplicationStatus $status): CampaignApplication
{
    return CampaignApplication::factory()->status($status)->createOne([
        'campaign_id' => $f->job->campaign->id,
        'agency_id' => $f->job->campaign->agency_id,
        'creator_id' => $f->job->creator->id,
    ]);
}

function conflictListUrl(ExclusivityFixture $f): string
{
    return "/api/v1/agencies/{$f->job->agency->ulid}/campaigns/{$f->job->campaign->ulid}/applications";
}

function conflictListAdmin(ExclusivityFixture $f): User
{
    return User::factory()->agencyAdmin($f->job->agency)->createOne();
}

it('flags a pending applicant who is blocked, and names the blocking campaign', function (): void {
    $f = ExclusivityFixture::make();
    applicationOf($f, CampaignApplicationStatus::Pending);
    $f->select(AssignmentStatus::Accepted);

    $row = $this->actingAs(conflictListAdmin($f))->getJson(conflictListUrl($f))->assertOk()->json('data.0.attributes');

    expect($row['has_exclusivity_conflict'])->toBeTrue()
        ->and($row['exclusivity_conflict'])->toBe(['id' => $f->zavaCampaign->ulid, 'name' => ExclusivityFixture::ZAVA_CAMPAIGN_NAME]);
});

it('does not flag a pending applicant who is not blocked', function (): void {
    $f = ExclusivityFixture::make();
    applicationOf($f, CampaignApplicationStatus::Pending);

    $row = $this->actingAs(conflictListAdmin($f))->getJson(conflictListUrl($f))->assertOk()->json('data.0.attributes');

    expect($row['has_exclusivity_conflict'])->toBeFalse()->and($row['exclusivity_conflict'])->toBeNull();
});

it('flags PENDING rows only: an answered application is left alone', function (CampaignApplicationStatus $status): void {
    $f = ExclusivityFixture::make();
    applicationOf($f, $status);
    $f->select(AssignmentStatus::Accepted);

    $row = $this->actingAs(conflictListAdmin($f))->getJson(conflictListUrl($f))->assertOk()->json('data.0.attributes');

    expect($row['has_exclusivity_conflict'])->toBeFalse()
        ->and($row['exclusivity_conflict'])->toBeNull();
})->with([
    'accepted' => [CampaignApplicationStatus::Accepted],
    'rejected' => [CampaignApplicationStatus::Rejected],
]);

it('flags nothing while the flag is OFF', function (): void {
    $f = ExclusivityFixture::make(flag: false);
    applicationOf($f, CampaignApplicationStatus::Pending);
    $f->select(AssignmentStatus::Accepted);

    expect($this->actingAs(conflictListAdmin($f))->getJson(conflictListUrl($f))->assertOk()->json('data.0.attributes')['has_exclusivity_conflict'])->toBeFalse();
});

it('stops flagging once the block ends', function (): void {
    $f = ExclusivityFixture::make();
    applicationOf($f, CampaignApplicationStatus::Pending);
    $f->select(AssignmentStatus::Declined);

    expect($this->actingAs(conflictListAdmin($f))->getJson(conflictListUrl($f))->assertOk()->json('data.0.attributes')['has_exclusivity_conflict'])->toBeFalse();
});

it('reads the conflicts of a whole page in ONE query', function (): void {
    $f = ExclusivityFixture::make();
    applicationOf($f, CampaignApplicationStatus::Pending);
    for ($i = 0; $i < 4; $i++) {
        $other = CreatorJobFixture::make()->creator;
        CampaignApplication::factory()->status(CampaignApplicationStatus::Pending)->createOne([
            'campaign_id' => $f->job->campaign->id,
            'agency_id' => $f->job->campaign->agency_id,
            'creator_id' => $other->id,
        ]);
    }
    $f->select(AssignmentStatus::Accepted);
    $admin = conflictListAdmin($f);

    DB::enableQueryLog();
    $this->actingAs($admin)->getJson(conflictListUrl($f))->assertOk();
    $queries = array_filter(DB::getQueryLog(), static fn (array $q): bool => str_contains($q['query'], 'campaign_exclusive_brands'));
    DB::disableQueryLog();

    expect($queries)->toHaveCount(1);
});
