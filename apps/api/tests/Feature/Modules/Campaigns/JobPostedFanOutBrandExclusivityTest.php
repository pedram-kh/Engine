<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Campaigns\Services\BrandExclusivity;
use App\Modules\Campaigns\Services\JobPostedFanOutService;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Enums\RelationshipStatus;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Fixtures\JobsBoard\ExclusivityFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * AH-120 A5 — the "a job was posted, apply!" fan-out skips a creator who is
 * blocked from the posted campaign's brand. The SQL mirror is the same query as
 * the PHP predicate; parity is asserted across every assignment state.
 */
beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-11 12:00:00', 'UTC'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** A rostered, approved creator of the fixture's agency with an assignment on the Zava campaign in $status. */
function rosteredOnZava(ExclusivityFixture $f, ?AssignmentStatus $status): Creator
{
    $creator = CreatorFactory::new()->approved()->createOne(['user_id' => User::factory()->createOne()->id]);
    AgencyCreatorRelation::factory()->createOne([
        'agency_id' => $f->job->agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => RelationshipStatus::Roster,
        'is_blacklisted' => false,
    ]);

    if ($status instanceof AssignmentStatus) {
        CampaignAssignment::factory()->createOne([
            'campaign_id' => $f->zavaCampaign->id,
            'creator_id' => $creator->id,
            'status' => $status,
        ]);
    }

    return $creator;
}

it('skips a creator who is blocked from the posted brand, and notifies the rest', function (): void {
    $f = ExclusivityFixture::make();
    $blocked = rosteredOnZava($f, AssignmentStatus::Accepted);
    $free = rosteredOnZava($f, null);

    $ids = app(JobPostedFanOutService::class)->recipients($f->job->campaign, 100)->pluck('id')->all();

    expect(in_array($free->id, $ids, true))->toBeTrue()
        ->and(in_array($blocked->id, $ids, true))->toBeFalse()
        ->and(app(JobPostedFanOutService::class)->pendingCount($f->job->campaign))->toBe(count($ids));
});

it('keeps the fan-out SQL in step with the PHP predicate across every assignment state', function (): void {
    $f = ExclusivityFixture::make();

    $byId = [];
    foreach (AssignmentStatus::cases() as $status) {
        $creator = rosteredOnZava($f, $status);
        $byId[$creator->id] = $creator;
    }
    $none = rosteredOnZava($f, null);
    $byId[$none->id] = $none;

    $recipients = app(JobPostedFanOutService::class)->recipients($f->job->campaign, 500)->pluck('id')->all();

    $predicate = app(BrandExclusivity::class);
    $expected = [];
    foreach ($byId as $id => $creator) {
        if (! $predicate->isBlocked($f->job->campaign, $creator)) {
            $expected[] = $id;
        }
    }

    // The fixture's own creator is on the roster as well; compare only the ones created here.
    $mine = array_values(array_intersect($recipients, array_keys($byId)));
    sort($mine);
    sort($expected);

    // Some blocked, some not: a parity check over a single-valued set proves nothing.
    expect($mine)->toBe($expected)
        ->and(count($expected))->toBeGreaterThan(0)
        ->and(count($expected))->toBeLessThan(count($byId));
});

it('fans out to everyone while the flag is OFF', function (): void {
    $f = ExclusivityFixture::make(flag: false);
    $blocked = rosteredOnZava($f, AssignmentStatus::Accepted);

    $ids = app(JobPostedFanOutService::class)->recipients($f->job->campaign, 100)->pluck('id')->all();

    expect($ids)->toContain($blocked->id);
});

it('does not skip a creator blocked from a DIFFERENT brand (the negative base)', function (): void {
    $f = ExclusivityFixture::make();
    // The Zava campaign blocks the posted job's brand; a campaign that blocks some other brand does not.
    $f->zavaCampaign->exclusiveBrands()->sync([$f->zava->id]);
    $creator = rosteredOnZava($f, AssignmentStatus::Accepted);

    $ids = app(JobPostedFanOutService::class)->recipients($f->job->campaign, 100)->pluck('id')->all();

    expect($ids)->toContain($creator->id);
});
