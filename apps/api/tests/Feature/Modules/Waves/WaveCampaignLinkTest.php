<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Http\Requests\UpdateCampaignRequest;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Waves\Models\Wave;
use App\Modules\Waves\Services\WaveCampaignLink;
use App\Modules\Waves\Services\WaveRuleViolation;
use App\Modules\Waves\Services\WaveService;
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

it('B4 / L1: refuses a campaign of another brand, another agency, a deleted one, an unknown ulid and a NORMAL campaign with an identical 422', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();
    $sameAgencyOtherBrand = WaveFixtures::campaign($w['agency'], Brand::factory()->forAgency($w['agency']->id)->createOne());
    $otherAgency = WaveFixtures::campaign($w['otherAgency'], $w['otherBrand']);
    $deleted = WaveFixtures::campaign($w['agency'], $w['brand']);
    $deleted->delete();
    $unknown = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
    // PL-1.2 (P2): a NORMAL campaign of this very brand and agency is refused with the same body (L1).
    $normal = WaveFixtures::campaign($w['agency'], $w['brand']);

    $bodies = [];
    foreach ([$sameAgencyOtherBrand->ulid, $otherAgency->ulid, $deleted->ulid, $unknown, $normal->ulid] as $ulid) {
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
    $campaign = WaveFixtures::waveCampaign($w['agency'], $w['brand']);

    $this->actingAs($w['admin'], 'web')
        ->postJson(WaveFixtures::wavesUrl($w['agency'], $w['brand']), WaveFixtures::body(['campaign_id' => $campaign->ulid]))
        ->assertCreated()->assertJsonPath('data.relationships.campaign.data.id', $campaign->ulid);
});

it('B5: a second wave for a linked campaign gives 422 wave.campaign_already_linked', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();
    $campaign = WaveFixtures::waveCampaign($w['agency'], $w['brand']);
    Wave::factory()->forBrand($w['agency']->id, $w['brand']->id)->linkedTo($campaign->id)->create();

    $this->actingAs($w['admin'], 'web')
        ->postJson(WaveFixtures::wavesUrl($w['agency'], $w['brand']), WaveFixtures::body(['campaign_id' => $campaign->ulid]))
        ->assertUnprocessable()->assertJsonPath('errors.0.code', 'wave.campaign_already_linked');
});

it('B5: the race: when a competing row lands first the index fires and the answer is the 422, not a 500', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();
    $campaign = WaveFixtures::waveCampaign($w['agency'], $w['brand']);

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
    $draft = WaveFixtures::waveCampaign($w['agency'], $w['brand'], 'draft', 'A draft');
    $active = WaveFixtures::waveCampaign($w['agency'], $w['brand'], 'active', 'B active');
    $paused = WaveFixtures::waveCampaign($w['agency'], $w['brand'], 'paused', 'C paused');
    $completed = WaveFixtures::waveCampaign($w['agency'], $w['brand'], 'completed', 'D completed');
    $linked = WaveFixtures::waveCampaign($w['agency'], $w['brand'], 'active', 'E linked');
    WaveFixtures::campaign($w['agency'], $w['brand'], 'active', 'J normal (never offered: P2)');
    WaveFixtures::waveCampaign($w['agency'], $w['brand'], 'cancelled', 'F cancelled');
    $gone = WaveFixtures::waveCampaign($w['agency'], $w['brand'], 'active', 'G deleted');
    $gone->delete();
    WaveFixtures::waveCampaign($w['agency'], Brand::factory()->forAgency($w['agency']->id)->createOne(), 'active', 'H other brand');
    WaveFixtures::waveCampaign($w['otherAgency'], $w['otherBrand'], 'active', 'I other agency');
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
    $campaign = WaveFixtures::waveCampaign($w['agency'], $w['brand']);
    $wave = Wave::factory()->forBrand($w['agency']->id, $w['brand']->id)->create();

    $this->actingAs($w['admin'], 'web')
        ->patchJson(WaveFixtures::wavesUrl($w['agency'], $w['brand'], $wave->ulid), ['campaign_id' => $campaign->ulid])
        ->assertOk()->assertJsonPath('data.relationships.campaign.data.id', $campaign->ulid);

    expect($wave->fresh()?->campaign_id)->toBe($campaign->id)
        ->and(AuditLog::query()->where('action', 'wave.updated')->count())->toBe(1);
});

it('L3a: with no card in a working column an existing link can be changed and removed, and each change audits wave.updated', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();
    $linked = WaveFixtures::waveCampaign($w['agency'], $w['brand']);
    $other = WaveFixtures::waveCampaign($w['agency'], $w['brand']);
    $wave = Wave::factory()->forBrand($w['agency']->id, $w['brand']->id)->linkedTo($linked->id)->create();
    $url = WaveFixtures::wavesUrl($w['agency'], $w['brand'], $wave->ulid);

    // A card in Applied-side territory (Invited) and one in Dropped never lock.
    WaveFixtures::cardIn($linked, 'invited');
    WaveFixtures::cardIn($linked, 'dropped');

    $this->actingAs($w['admin'], 'web')->patchJson($url, ['campaign_id' => $other->ulid])->assertOk()
        ->assertJsonPath('data.relationships.campaign.data.id', $other->ulid);
    expect($wave->fresh()?->campaign_id)->toBe($other->id);

    $this->actingAs($w['admin'], 'web')->patchJson($url, ['campaign_id' => null, 'name' => 'Unlinked'])->assertOk();
    expect($wave->fresh()?->campaign_id)->toBeNull()
        ->and($wave->fresh()?->name)->toBe('Unlinked');

    $audits = AuditLog::query()->where('action', 'wave.updated')->orderBy('id')->get();
    expect($audits)->toHaveCount(2)
        ->and(WaveFixtures::auditCampaign($audits[0], 'before'))->toBe($linked->ulid)
        ->and(WaveFixtures::auditCampaign($audits[0], 'after'))->toBe($other->ulid)
        ->and(WaveFixtures::auditCampaign($audits[1], 'after'))->toBeNull();
});

it('L3a: a card currently in ANY working column locks the link: change and remove are 422 wave.campaign_link_locked and nothing changes', function (string $stage): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();
    $linked = WaveFixtures::waveCampaign($w['agency'], $w['brand']);
    $other = WaveFixtures::waveCampaign($w['agency'], $w['brand']);
    $wave = Wave::factory()->forBrand($w['agency']->id, $w['brand']->id)->linkedTo($linked->id)->create();
    $url = WaveFixtures::wavesUrl($w['agency'], $w['brand'], $wave->ulid);
    WaveFixtures::cardIn($linked, $stage);

    $this->actingAs($w['admin'], 'web')->patchJson($url, ['campaign_id' => $other->ulid])
        ->assertUnprocessable()->assertJsonPath('errors.0.code', 'wave.campaign_link_locked');
    $this->actingAs($w['admin'], 'web')->patchJson($url, ['campaign_id' => null, 'name' => 'Sneaky'])
        ->assertUnprocessable()->assertJsonPath('errors.0.code', 'wave.campaign_link_locked');

    expect($wave->fresh()?->campaign_id)->toBe($linked->id)
        ->and($wave->fresh()?->name)->toBe($wave->name)
        ->and(AuditLog::query()->where('action', 'wave.updated')->count())->toBe(0);
})->with(['selected', 'filming', 'submitted', 'in_edits', 'client_review', 'approved']);

it('L3b: the lock releases when the card leaves the working set, even though it was there once (positions, not history)', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();
    $linked = WaveFixtures::waveCampaign($w['agency'], $w['brand']);
    $wave = Wave::factory()->forBrand($w['agency']->id, $w['brand']->id)->linkedTo($linked->id)->create();
    $url = WaveFixtures::wavesUrl($w['agency'], $w['brand'], $wave->ulid);
    $card = WaveFixtures::cardIn($linked, 'selected');
    $link = app(WaveCampaignLink::class);

    expect($link->isLocked($linked->id))->toBeTrue();
    $this->actingAs($w['admin'], 'web')->patchJson($url, ['campaign_id' => null])->assertUnprocessable();

    // Moved back to Invited (by the real route): unlocked, though the card WAS selected.
    $this->actingAs($w['admin'], 'web')
        ->postJson("/api/v1/agencies/{$w['agency']->ulid}/campaigns/{$linked->ulid}/board/cards/{$card->ulid}/move", [
            'target_column_id' => WaveFixtures::stageColumn($card, 'invited')->ulid,
        ])->assertOk();
    expect($link->isLocked($linked->id))->toBeFalse();
    $this->actingAs($w['admin'], 'web')->patchJson($url, ['campaign_id' => null])->assertOk();
    expect($wave->fresh()?->campaign_id)->toBeNull();
});

it('L3b: moved to Dropped it unlocks too; L3c: the answer flips with every move in and out, and a campaign with no board is unlocked', function (): void {
    $w = WaveFixtures::world();
    $linked = WaveFixtures::waveCampaign($w['agency'], $w['brand']);
    $link = app(WaveCampaignLink::class);

    expect($link->isLocked($linked->id))->toBeFalse();   // no board yet

    $card = WaveFixtures::cardIn($linked, 'invited');
    $answers = [];
    foreach (['selected', 'dropped', 'filming', 'invited', 'approved', 'dropped'] as $stage) {
        $card->update(['column_id' => WaveFixtures::stageColumn($card, $stage)->id]);
        $answers[$stage.'#'.count($answers)] = $link->isLocked($linked->id);
    }

    expect(array_values($answers))->toBe([true, false, true, false, true, false]);
});

it('B14: the wave\'s own campaign id is a no-op: 200 and no audit row; null on an unlinked wave is a no-op too', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();
    $linked = WaveFixtures::waveCampaign($w['agency'], $w['brand']);
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

    $otherBrandCampaign = WaveFixtures::waveCampaign($w['agency'], Brand::factory()->forAgency($w['agency']->id)->createOne());
    $otherAgencyCampaign = WaveFixtures::waveCampaign($w['otherAgency'], $w['otherBrand']);
    $deleted = WaveFixtures::waveCampaign($w['agency'], $w['brand']);
    $deleted->delete();
    $taken = WaveFixtures::waveCampaign($w['agency'], $w['brand']);
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
    $cancelled = WaveFixtures::waveCampaign($w['agency'], $w['brand'], 'cancelled');
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
    $campaign = WaveFixtures::waveCampaign($w['agency'], $w['brand'], 'active');
    $wave = Wave::factory()->forBrand($w['agency']->id, $w['brand']->id)->linkedTo($campaign->id)->create();

    $campaign->update(['status' => 'cancelled']);

    $this->actingAs($w['admin'], 'web')->getJson(WaveFixtures::wavesUrl($w['agency'], $w['brand'], $wave->ulid))
        ->assertOk()->assertJsonPath('data.relationships.campaign.data.id', $campaign->ulid);
    $this->actingAs($w['admin'], 'web')
        ->patchJson(WaveFixtures::wavesUrl($w['agency'], $w['brand'], $wave->ulid), ['campaign_id' => $campaign->ulid, 'name' => 'Still linked'])
        ->assertOk();
    expect($wave->fresh()?->campaign_id)->toBe($campaign->id);
});

it('F2: a second edit holding a STALE wave re-reads the locked row: unlocked, a different campaign is applied on the fresh row; locked, it is refused; the same campaign is a no-op', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();
    $first = WaveFixtures::waveCampaign($w['agency'], $w['brand'], 'draft', 'First');
    $second = WaveFixtures::waveCampaign($w['agency'], $w['brand'], 'draft', 'Second');
    $wave = Wave::factory()->forBrand($w['agency']->id, $w['brand']->id)->create();

    // Both "requests" loaded the wave while it had no link.
    $staleForB = Wave::query()->findOrFail($wave->id);
    $staleForA = Wave::query()->findOrFail($wave->id);
    expect($staleForB->campaign_id)->toBeNull();

    $service = app(WaveService::class);
    $service->update($w['agency'], $w['brand'], $staleForA, ['campaign_id' => $first->ulid]);

    // (b) a card is Selected on the winner's campaign: the loser's different-campaign request is refused.
    $card = WaveFixtures::cardIn($first, 'selected');
    $caught = null;
    try {
        $service->update($w['agency'], $w['brand'], $staleForB, ['campaign_id' => $second->ulid]);
    } catch (WaveRuleViolation $e) {
        $caught = $e->errorCode;
    }
    expect($caught)->toBe('wave.campaign_link_locked')
        ->and(Wave::query()->findOrFail($wave->id)->campaign_id)->toBe($first->id);

    // (a) the card leaves the working set: the same stale model's change is now applied on the FRESH row, and the
    // audit's "before" is the winner's campaign, not the stale null.
    $card->update(['column_id' => WaveFixtures::stageColumn($card, 'invited')->id]);
    $auditsBefore = AuditLog::query()->where('action', 'wave.updated')->count();
    $returned = $service->update($w['agency'], $w['brand'], $staleForB, ['campaign_id' => $second->ulid]);
    $audit = AuditLog::query()->where('action', 'wave.updated')->orderByDesc('id')->firstOrFail();
    expect($returned->campaign_id)->toBe($second->id)
        ->and(WaveFixtures::auditCampaign($audit, 'before'))->toBe($first->ulid)
        ->and(WaveFixtures::auditCampaign($audit, 'after'))->toBe($second->ulid);

    // The same campaign the row already has: a no-op, no audit row.
    $count = AuditLog::query()->where('action', 'wave.updated')->count();
    $service->update($w['agency'], $w['brand'], Wave::query()->findOrFail($wave->id), ['campaign_id' => $second->ulid]);
    expect(AuditLog::query()->where('action', 'wave.updated')->count())->toBe($count)
        ->and($count)->toBe($auditsBefore + 1);
});

it('F2: the stale loser asking for the winner\'s campaign with a rename applies the rename on the fresh row and audits the true before', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();
    $first = WaveFixtures::waveCampaign($w['agency'], $w['brand'], 'draft', 'First');
    $wave = Wave::factory()->forBrand($w['agency']->id, $w['brand']->id)->create(['name' => 'Original']);
    $stale = Wave::query()->findOrFail($wave->id);
    Wave::query()->whereKey($wave->id)->update(['campaign_id' => $first->id]);

    app(WaveService::class)->update($w['agency'], $w['brand'], $stale, ['campaign_id' => $first->ulid, 'name' => 'Renamed']);

    $audit = AuditLog::query()->where('action', 'wave.updated')->firstOrFail();
    expect(Wave::query()->findOrFail($wave->id)->name)->toBe('Renamed')
        ->and(WaveFixtures::auditCampaign($audit, 'before') ?? null)->toBe($first->ulid)
        ->and(WaveFixtures::auditCampaign($audit, 'after') ?? null)->toBe($first->ulid);
});
