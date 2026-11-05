<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Campaigns\Enums\CampaignApplicationStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignApplication;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Campaigns\Services\JobPostedFanOutService;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Enums\RelationshipStatus;
use App\Modules\Creators\Features\CampaignRegionRestrictionEnabled;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Tests\Fixtures\JobsBoard\CreatorJobFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * AH-118 A4 — the region lock on the creator side: the predicate, the apply
 * refusal (422 `job.region_not_allowed`), `is_region_locked` on the detail, and
 * the fan-out exclusion with its SQL/PHP parity.
 *
 * `listed()` campaigns carry regions ['IE', 'FR'].
 */

/**
 * @return CreatorJobFixture a rostered creator in $country on a region-restricted listed job
 */
function regionJob(?string $country, bool $restricted = true, bool $flag = true): CreatorJobFixture
{
    // Explicit both ways: Pennant state outlives a call within one test.
    $flag
        ? Feature::activate(CampaignRegionRestrictionEnabled::NAME)
        : Feature::deactivate(CampaignRegionRestrictionEnabled::NAME);

    $f = CreatorJobFixture::make(['restrict_to_listing_regions' => $restricted]);
    $f->creator->forceFill(['country_code' => $country])->save();

    return $f;
}

function reloadedCreator(CreatorJobFixture $f): Creator
{
    return Creator::query()->findOrFail($f->creator->id);
}

// ── the predicate ───────────────────────────────────────────────────────────

it('locks a creator outside the regions, and lets one inside through', function (): void {
    $out = regionJob('PT');
    expect($out->campaign->isRegionLockedFor(reloadedCreator($out)))->toBeTrue();

    $in = regionJob('IE');
    expect($in->campaign->isRegionLockedFor(reloadedCreator($in)))->toBeFalse();
});

it('locks a creator with a NULL country (Q7), and a blank one', function (): void {
    foreach ([null, '', '  '] as $country) {
        $f = regionJob($country);
        expect($f->campaign->isRegionLockedFor(reloadedCreator($f)))->toBeTrue(sprintf('country %s', var_export($country, true)));
    }
});

it('compares a lower-case or padded stored country case-insensitively', function (): void {
    foreach (['ie', 'Ie', ' ie '] as $country) {
        $f = regionJob($country);
        expect($f->campaign->isRegionLockedFor(reloadedCreator($f)))->toBeFalse(sprintf('country %s', var_export($country, true)));
    }

    // …and lower-case REGIONS on the campaign row match an upper-case country.
    $f = regionJob('FR');
    $f->campaign->forceFill(['listing_regions' => ['ie', 'fr']]);
    expect($f->campaign->isRegionLockedFor(reloadedCreator($f)))->toBeFalse();
});

it('never locks when the switch is off, the flag is off, or there are no regions', function (): void {
    $off = regionJob('PT', restricted: false);
    expect($off->campaign->isRegionLockedFor(reloadedCreator($off)))->toBeFalse();

    $flagOff = regionJob('PT', flag: false);
    expect($flagOff->campaign->restrict_to_listing_regions)->toBeTrue()
        ->and($flagOff->campaign->isRegionLockedFor(reloadedCreator($flagOff)))->toBeFalse();

    $empty = regionJob('PT');
    $empty->campaign->forceFill(['listing_regions' => []]);
    expect($empty->campaign->isRegionLockedFor(reloadedCreator($empty)))->toBeFalse();
});

// ── apply ───────────────────────────────────────────────────────────────────

it('refuses an out-of-region apply with 422 job.region_not_allowed and writes nothing', function (): void {
    $f = regionJob('PT');

    $response = $this->actingAs($f->user)->postJson($f->applyUrl());

    $response->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'job.region_not_allowed')
        ->assertJsonPath('errors.0.meta.reason', 'outside_regions');

    expect(CampaignApplication::withoutGlobalScopes()->where('campaign_id', $f->campaign->id)->count())->toBe(0);
});

it('answers a NULL-country apply with the same code and meta.reason = country_missing', function (): void {
    $f = regionJob(null);

    $this->actingAs($f->user)->postJson($f->applyUrl())
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'job.region_not_allowed')
        ->assertJsonPath('errors.0.meta.reason', 'country_missing');
});

it('lets an in-region creator apply, however the country is cased', function (): void {
    $f = regionJob('ie');

    $this->actingAs($f->user)->postJson($f->applyUrl())->assertCreated();
});

it('does not restrict when the switch is OFF, and does not restrict while the flag is OFF', function (): void {
    $switchOff = regionJob('PT', restricted: false);
    $this->actingAs($switchOff->user)->postJson($switchOff->applyUrl())->assertCreated();

    $flagOff = regionJob('PT', flag: false);
    $this->actingAs($flagOff->user)->postJson($flagOff->applyUrl())->assertCreated();
});

it('keeps the visibility 404 and the duplicate 409 ahead of the region 422', function (): void {
    $f = regionJob('PT');

    // Not visible to this creator at all → 404, never the region code.
    AgencyCreatorRelation::query()->where('creator_id', $f->creator->id)
        ->update(['relationship_status' => RelationshipStatus::Roster, 'is_blacklisted' => true]);
    $this->actingAs($f->user)->postJson($f->applyUrl())->assertNotFound();
    AgencyCreatorRelation::query()->where('creator_id', $f->creator->id)->update(['is_blacklisted' => false]);

    // An application that pre-dates the switch is left alone → 409, not 422 (Q13).
    CampaignApplication::query()->create([
        'agency_id' => $f->campaign->agency_id,
        'campaign_id' => $f->campaign->id,
        'creator_id' => $f->creator->id,
        'status' => CampaignApplicationStatus::Pending,
    ]);
    $this->actingAs($f->user)->postJson($f->applyUrl())
        ->assertStatus(409)->assertJsonPath('errors.0.code', 'job.already_applied');
    expect(CampaignApplication::withoutGlobalScopes()->where('campaign_id', $f->campaign->id)->count())->toBe(1);
});

it('leaves an existing assignment on a now-restricted campaign untouched (Q13)', function (): void {
    $f = regionJob('PT', restricted: false);
    $assignment = CampaignAssignment::factory()->createOne([
        'agency_id' => $f->campaign->agency_id,
        'campaign_id' => $f->campaign->id,
        'creator_id' => $f->creator->id,
    ]);

    $f->campaign->forceFill(['restrict_to_listing_regions' => true])->save();

    expect($assignment->fresh())->not->toBeNull();
    $this->actingAs($f->user)->getJson('/api/v1/creators/me/assignments')->assertOk();
});

// ── detail + card ───────────────────────────────────────────────────────────

it('reports is_region_locked on the detail, and only there', function (): void {
    $locked = regionJob('PT');
    $this->actingAs($locked->user)->getJson($locked->jobUrl())->assertOk()
        ->assertJsonPath('data.attributes.is_region_locked', true);

    // The card (board list) never carries it — Q10.
    $row = $this->actingAs($locked->user)->getJson(CreatorJobFixture::URL)->assertOk()->json('data.0.attributes');
    expect($row)->not->toHaveKey('is_region_locked')->and($row)->not->toHaveKey('restrict_to_listing_regions');

    $null = regionJob(null);
    $this->actingAs($null->user)->getJson($null->jobUrl())->assertJsonPath('data.attributes.is_region_locked', true);

    $in = regionJob('FR');
    $this->actingAs($in->user)->getJson($in->jobUrl())->assertJsonPath('data.attributes.is_region_locked', false);
});

it('names the reason on the detail: country_missing, outside_regions, or null', function (): void {
    $out = regionJob('PT');
    $this->actingAs($out->user)->getJson($out->jobUrl())->assertOk()
        ->assertJsonPath('data.attributes.region_lock_reason', 'outside_regions');

    $none = regionJob(null);
    $this->actingAs($none->user)->getJson($none->jobUrl())
        ->assertJsonPath('data.attributes.region_lock_reason', 'country_missing');

    $blank = regionJob('  ');
    $this->actingAs($blank->user)->getJson($blank->jobUrl())
        ->assertJsonPath('data.attributes.region_lock_reason', 'country_missing');

    $in = regionJob('fr');
    $this->actingAs($in->user)->getJson($in->jobUrl())
        ->assertJsonPath('data.attributes.region_lock_reason', null)
        ->assertJsonPath('data.attributes.is_region_locked', false);

    // Flag OFF: no reason either, and the key is still present.
    $off = regionJob('PT', flag: false);
    $body = $this->actingAs($off->user)->getJson($off->jobUrl())->assertOk()->json('data.attributes');
    expect($body)->toHaveKey('region_lock_reason')->and($body['region_lock_reason'])->toBeNull();
});

it('still shows a locked creator the job, and says false while the flag is OFF', function (): void {
    $flagOff = regionJob('PT', flag: false);

    $this->actingAs($flagOff->user)->getJson($flagOff->jobUrl())->assertOk()
        ->assertJsonPath('data.attributes.is_region_locked', false);

    Feature::activate(CampaignRegionRestrictionEnabled::NAME);
    $this->actingAs($flagOff->user)->getJson($flagOff->jobUrl())->assertOk()
        ->assertJsonPath('data.attributes.is_region_locked', true);
});

// ── fan-out: SQL mirror of the PHP predicate ────────────────────────────────

it('keeps the fan-out SQL in step with the PHP predicate across country spellings', function (): void {
    Feature::activate(CampaignRegionRestrictionEnabled::NAME);
    $f = CreatorJobFixture::make(['restrict_to_listing_regions' => true]);

    $countries = ['IE', 'ie', ' fr ', 'FR', 'PT', 'US', null, ''];
    $byId = [];

    foreach ($countries as $country) {
        $user = User::factory()->createOne();
        $creator = CreatorFactory::new()->approved()->createOne(['user_id' => $user->id, 'country_code' => $country]);
        AgencyCreatorRelation::factory()->createOne([
            'agency_id' => $f->agency->id,
            'creator_id' => $creator->id,
            'relationship_status' => RelationshipStatus::Roster,
            'is_blacklisted' => false,
        ]);
        $byId[$creator->id] = $creator;
    }

    // The fixture's own creator is on the roster too (country from the factory).
    $byId[$f->creator->id] = reloadedCreator($f);

    $service = app(JobPostedFanOutService::class);
    $recipients = $service->recipients($f->campaign, 100)->pluck('id')->all();

    $expected = [];
    foreach ($byId as $id => $creator) {
        if (! $f->campaign->isRegionLockedFor($creator)) {
            $expected[] = $id;
        }
    }

    sort($recipients);
    sort($expected);

    expect($recipients)->toBe($expected)
        ->and(count($expected))->toBeGreaterThan(0)
        ->and(count($expected))->toBeLessThan(count($byId));
});

it('fans out to everyone when the switch or the flag is off', function (): void {
    $f = regionJob('PT', restricted: false);
    expect(app(JobPostedFanOutService::class)->recipients($f->campaign, 10))->toHaveCount(1);

    $g = regionJob('PT', flag: false);
    Feature::deactivate(CampaignRegionRestrictionEnabled::NAME);
    expect(app(JobPostedFanOutService::class)->recipients($g->campaign, 10))->toHaveCount(1);
});
