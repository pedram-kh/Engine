<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Http\Requests\UpdateCampaignRequest;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Waves\Models\Wave;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\WaveFixtures;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * PL-1.1 — the campaign link: same brand (B4), one wave per campaign (B5), the dropdown (B6), the G2 pins (B6, B6b),
 * set-link on edit (B14) and the Cancelled rule (B15).
 */

/**
 * @param  TestResponse<JsonResponse>  $response
 * @return array<string, mixed>
 */
function wvErrorBody(TestResponse $response): array
{
    /** @var array{code: string, title: string, status: string} $error */
    $error = $response->json('errors.0');

    return ['status' => $error['status'], 'code' => $error['code'], 'title' => $error['title']];
}

it('B4: refuses a campaign of another brand, another agency, a deleted one and an unknown ulid with an identical 422', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();
    $sameAgencyOtherBrand = WaveFixtures::campaign($w['agency'], Brand::factory()->forAgency($w['agency']->id)->createOne());
    $otherAgency = WaveFixtures::campaign($w['otherAgency'], $w['otherBrand']);
    $deleted = WaveFixtures::campaign($w['agency'], $w['brand']);
    $deleted->delete();
    $unknown = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

    $bodies = [];
    foreach ([$sameAgencyOtherBrand->ulid, $otherAgency->ulid, $deleted->ulid, $unknown] as $ulid) {
        $res = $this->actingAs($w['admin'], 'web')
            ->postJson(WaveFixtures::wavesUrl($w['agency'], $w['brand']), WaveFixtures::body(['campaign_id' => $ulid]))
            ->assertUnprocessable()->assertJsonPath('errors.0.code', 'wave.campaign_not_available');
        $bodies[] = wvErrorBody($res);
    }

    expect(array_unique($bodies, SORT_REGULAR))->toHaveCount(1)
        ->and(Wave::query()->count())->toBe(0);
});

it('B4: links an own-brand unlinked campaign', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();
    $campaign = WaveFixtures::campaign($w['agency'], $w['brand']);

    $this->actingAs($w['admin'], 'web')
        ->postJson(WaveFixtures::wavesUrl($w['agency'], $w['brand']), WaveFixtures::body(['campaign_id' => $campaign->ulid]))
        ->assertCreated()->assertJsonPath('data.relationships.campaign.data.id', $campaign->ulid);
});

it('B5: a second wave for a linked campaign gives 422 wave.campaign_already_linked', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();
    $campaign = WaveFixtures::campaign($w['agency'], $w['brand']);
    Wave::factory()->forBrand($w['agency']->id, $w['brand']->id)->linkedTo($campaign->id)->create();

    $this->actingAs($w['admin'], 'web')
        ->postJson(WaveFixtures::wavesUrl($w['agency'], $w['brand']), WaveFixtures::body(['campaign_id' => $campaign->ulid]))
        ->assertUnprocessable()->assertJsonPath('errors.0.code', 'wave.campaign_already_linked');
});

it('B5: the race: when a competing row lands first the index fires and the answer is the 422, not a 500', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();
    $campaign = WaveFixtures::campaign($w['agency'], $w['brand']);

    // Another request wins the race between our pre-check and our insert: a one-shot creating hook inserts the
    // competing row (raw, so no model event runs) after the pre-check has already passed.
    $armed = true;
    Wave::creating(function () use (&$armed, $w, $campaign): void {
        if (! $armed) {
            return;
        }
        $armed = false;
        DB::table('waves')->insert([
            'ulid' => (string) Str::ulid(), 'agency_id' => $w['agency']->id, 'brand_id' => $w['brand']->id,
            'campaign_id' => $campaign->id, 'name' => 'Winner', 'go_live_on' => '2026-12-01', 'markets' => '["SK"]',
            'status' => 'draft', 'created_by_user_id' => $w['admin']->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    });

    $this->actingAs($w['admin'], 'web')
        ->postJson(WaveFixtures::wavesUrl($w['agency'], $w['brand']), WaveFixtures::body(['campaign_id' => $campaign->ulid]))
        ->assertUnprocessable()->assertJsonPath('errors.0.code', 'wave.campaign_already_linked');

    // The hook ran inside the request's own transaction, so the whole attempt (winner row included) rolled back.
    // What is pinned: the unique violation became the 422, not a 500 (there is no pre-check any more).
    expect($armed)->toBeFalse()
        ->and(Wave::query()->where('name', 'Wave 7')->count())->toBe(0);
});

it('B6: campaign-options returns only this brand\'s live, non-cancelled campaigns with no wave, with their status', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();
    $draft = WaveFixtures::campaign($w['agency'], $w['brand'], 'draft', 'A draft');
    $active = WaveFixtures::campaign($w['agency'], $w['brand'], 'active', 'B active');
    $paused = WaveFixtures::campaign($w['agency'], $w['brand'], 'paused', 'C paused');
    $completed = WaveFixtures::campaign($w['agency'], $w['brand'], 'completed', 'D completed');
    $linked = WaveFixtures::campaign($w['agency'], $w['brand'], 'active', 'E linked');
    WaveFixtures::campaign($w['agency'], $w['brand'], 'cancelled', 'F cancelled');
    $gone = WaveFixtures::campaign($w['agency'], $w['brand'], 'active', 'G deleted');
    $gone->delete();
    WaveFixtures::campaign($w['agency'], Brand::factory()->forAgency($w['agency']->id)->createOne(), 'active', 'H other brand');
    WaveFixtures::campaign($w['otherAgency'], $w['otherBrand'], 'active', 'I other agency');
    $wave = Wave::factory()->forBrand($w['agency']->id, $w['brand']->id)->linkedTo($linked->id)->create();

    $res = $this->actingAs($w['admin'], 'web')->getJson(WaveFixtures::wavesUrl($w['agency'], $w['brand'], 'campaign-options'))->assertOk();

    /** @var list<array{id: string, attributes: array{name: string, status: string}}> $rows */
    $rows = $res->json('data');
    expect(array_column($rows, 'id'))->toBe([$draft->ulid, $active->ulid, $paused->ulid, $completed->ulid])
        ->and(array_map(static fn (array $r): string => $r['attributes']['status'], $rows))->toBe(['draft', 'active', 'paused', 'completed']);

    // A campaign returns when its wave is soft-deleted.
    $wave->delete();
    $again = $this->actingAs($w['admin'], 'web')->getJson(WaveFixtures::wavesUrl($w['agency'], $w['brand'], 'campaign-options'))->json('data');
    expect(array_column($again, 'id'))->toContain($linked->ulid);
});

it('B6 pin 1 (G2): UpdateCampaignRequest::rules() carries no brand_id and no agency_id', function (): void {
    $rules = (new UpdateCampaignRequest)->rules();

    expect($rules)->not->toHaveKey('brand_id')
        ->and($rules)->not->toHaveKey('agency_id');
});

it('B6b pin 2 (G2): PATCHing a linked campaign with a brand_id leaves the campaign brand and the wave link unchanged', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();
    $campaign = WaveFixtures::campaign($w['agency'], $w['brand']);
    $wave = Wave::factory()->forBrand($w['agency']->id, $w['brand']->id)->linkedTo($campaign->id)->create();
    $other = Brand::factory()->forAgency($w['agency']->id)->createOne();
    $url = "/api/v1/agencies/{$w['agency']->ulid}/campaigns/{$campaign->ulid}";

    foreach ([$other->ulid, $other->id] as $brandValue) {
        $this->actingAs($w['admin'], 'web')->patchJson($url, ['name' => 'Renamed '.$brandValue, 'brand_id' => $brandValue])->assertOk();
    }

    $fresh = Campaign::query()->findOrFail($campaign->id);
    expect($fresh->brand_id)->toBe($w['brand']->id)
        ->and($fresh->name)->toStartWith('Renamed')
        ->and($wave->fresh()?->campaign_id)->toBe($campaign->id);
});

it('B14: sets a link on a wave that has none, audits wave.updated, and shows the campaign', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();
    $campaign = WaveFixtures::campaign($w['agency'], $w['brand']);
    $wave = Wave::factory()->forBrand($w['agency']->id, $w['brand']->id)->create();

    $this->actingAs($w['admin'], 'web')
        ->patchJson(WaveFixtures::wavesUrl($w['agency'], $w['brand'], $wave->ulid), ['campaign_id' => $campaign->ulid])
        ->assertOk()->assertJsonPath('data.relationships.campaign.data.id', $campaign->ulid);

    expect($wave->fresh()?->campaign_id)->toBe($campaign->id)
        ->and(AuditLog::query()->where('action', 'wave.updated')->count())->toBe(1);
});

it('B14: refuses to change or remove an existing link with 422 wave.campaign_link_locked', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();
    $linked = WaveFixtures::campaign($w['agency'], $w['brand']);
    $other = WaveFixtures::campaign($w['agency'], $w['brand']);
    $wave = Wave::factory()->forBrand($w['agency']->id, $w['brand']->id)->linkedTo($linked->id)->create();
    $url = WaveFixtures::wavesUrl($w['agency'], $w['brand'], $wave->ulid);

    $this->actingAs($w['admin'], 'web')->patchJson($url, ['campaign_id' => $other->ulid])
        ->assertUnprocessable()->assertJsonPath('errors.0.code', 'wave.campaign_link_locked');
    $this->actingAs($w['admin'], 'web')->patchJson($url, ['campaign_id' => null, 'name' => 'Sneaky'])
        ->assertUnprocessable()->assertJsonPath('errors.0.code', 'wave.campaign_link_locked');

    expect($wave->fresh()?->campaign_id)->toBe($linked->id)
        ->and($wave->fresh()?->name)->toBe($wave->name)
        ->and(AuditLog::query()->where('action', 'wave.updated')->count())->toBe(0);
});

it('B14: the wave\'s own campaign id is a no-op: 200 and no audit row; null on an unlinked wave is a no-op too', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();
    $linked = WaveFixtures::campaign($w['agency'], $w['brand']);
    $withLink = Wave::factory()->forBrand($w['agency']->id, $w['brand']->id)->linkedTo($linked->id)->create();
    $without = Wave::factory()->forBrand($w['agency']->id, $w['brand']->id)->create();

    $this->actingAs($w['admin'], 'web')
        ->patchJson(WaveFixtures::wavesUrl($w['agency'], $w['brand'], $withLink->ulid), ['campaign_id' => $linked->ulid])->assertOk();
    $this->actingAs($w['admin'], 'web')
        ->patchJson(WaveFixtures::wavesUrl($w['agency'], $w['brand'], $without->ulid), ['campaign_id' => null])->assertOk();

    expect(AuditLog::query()->where('action', 'wave.updated')->count())->toBe(0)
        ->and($without->fresh()?->campaign_id)->toBeNull();
});

it('B14: set-link obeys every create rule', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();
    $wave = Wave::factory()->forBrand($w['agency']->id, $w['brand']->id)->create();
    $url = WaveFixtures::wavesUrl($w['agency'], $w['brand'], $wave->ulid);

    $otherBrandCampaign = WaveFixtures::campaign($w['agency'], Brand::factory()->forAgency($w['agency']->id)->createOne());
    $otherAgencyCampaign = WaveFixtures::campaign($w['otherAgency'], $w['otherBrand']);
    $deleted = WaveFixtures::campaign($w['agency'], $w['brand']);
    $deleted->delete();
    $taken = WaveFixtures::campaign($w['agency'], $w['brand']);
    Wave::factory()->forBrand($w['agency']->id, $w['brand']->id)->linkedTo($taken->id)->create();

    $bodies = [];
    foreach ([$otherBrandCampaign->ulid, $otherAgencyCampaign->ulid, $deleted->ulid, '01ARZ3NDEKTSV4RRFFQ69G5FAV'] as $ulid) {
        $res = $this->actingAs($w['admin'], 'web')->patchJson($url, ['campaign_id' => $ulid])
            ->assertUnprocessable()->assertJsonPath('errors.0.code', 'wave.campaign_not_available');
        $bodies[] = wvErrorBody($res);
    }
    expect(array_unique($bodies, SORT_REGULAR))->toHaveCount(1);

    $this->actingAs($w['admin'], 'web')->patchJson($url, ['campaign_id' => $taken->ulid])
        ->assertUnprocessable()->assertJsonPath('errors.0.code', 'wave.campaign_already_linked');

    expect($wave->fresh()?->campaign_id)->toBeNull();
});

it('B15: a Cancelled campaign is refused by the server on create and on set-link, with the same body as any other refusal', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();
    $cancelled = WaveFixtures::campaign($w['agency'], $w['brand'], 'cancelled');
    $foreign = WaveFixtures::campaign($w['otherAgency'], $w['otherBrand']);
    $wave = Wave::factory()->forBrand($w['agency']->id, $w['brand']->id)->create();

    $create = $this->actingAs($w['admin'], 'web')
        ->postJson(WaveFixtures::wavesUrl($w['agency'], $w['brand']), WaveFixtures::body(['campaign_id' => $cancelled->ulid]))
        ->assertUnprocessable()->assertJsonPath('errors.0.code', 'wave.campaign_not_available');
    $edit = $this->actingAs($w['admin'], 'web')
        ->patchJson(WaveFixtures::wavesUrl($w['agency'], $w['brand'], $wave->ulid), ['campaign_id' => $cancelled->ulid])
        ->assertUnprocessable()->assertJsonPath('errors.0.code', 'wave.campaign_not_available');
    $reference = $this->actingAs($w['admin'], 'web')
        ->postJson(WaveFixtures::wavesUrl($w['agency'], $w['brand']), WaveFixtures::body(['campaign_id' => $foreign->ulid]))
        ->assertUnprocessable();

    expect(wvErrorBody($create))->toBe(wvErrorBody($reference))
        ->and(wvErrorBody($edit))->toBe(wvErrorBody($reference))
        ->and(Wave::query()->count())->toBe(1)
        ->and($wave->fresh()?->campaign_id)->toBeNull();
});

it('B15: a campaign cancelled AFTER it was linked keeps its link', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();
    $campaign = WaveFixtures::campaign($w['agency'], $w['brand'], 'active');
    $wave = Wave::factory()->forBrand($w['agency']->id, $w['brand']->id)->linkedTo($campaign->id)->create();

    $campaign->update(['status' => 'cancelled']);

    $this->actingAs($w['admin'], 'web')->getJson(WaveFixtures::wavesUrl($w['agency'], $w['brand'], $wave->ulid))
        ->assertOk()->assertJsonPath('data.relationships.campaign.data.id', $campaign->ulid);
    $this->actingAs($w['admin'], 'web')
        ->patchJson(WaveFixtures::wavesUrl($w['agency'], $w['brand'], $wave->ulid), ['campaign_id' => $campaign->ulid, 'name' => 'Still linked'])
        ->assertOk();
    expect($wave->fresh()?->campaign_id)->toBe($campaign->id);
});
