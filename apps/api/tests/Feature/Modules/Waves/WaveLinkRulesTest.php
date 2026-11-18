<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Boards\Models\Board;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Enums\CampaignType;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Waves\Models\Wave;
use App\Modules\Waves\Services\WaveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\WaveFixtures;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * PL-1.2 — the link rules: P3 (create a wave with its campaign in ONE transaction), P4/L5 (the campaign side is the same
 * rule as the wave side), T1 (tenancy), T2 (flag gate) and the P5 lock's SQL (L4, PostgreSQL).
 */

function wlCampaignUrl(Campaign $campaign, string $suffix, ?string $agencyUlid = null): string
{
    return '/api/v1/agencies/'.($agencyUlid ?? Campaign::query()->findOrFail($campaign->id)->agency->ulid)."/campaigns/{$campaign->ulid}/{$suffix}";
}

/** @return array<string, int> */
function wlCounts(): array
{
    // Raw table counts: the models' tenant scopes would hide rows from whoever is acting.
    return [
        'campaigns' => DB::table('campaigns')->count(),
        'boards' => DB::table('boards')->count(),
        'columns' => DB::table('board_columns')->count(),
        'waves' => DB::table('waves')->count(),
        'audits' => DB::table('audit_logs')->count(),
    ];
}

// ───────────────────────────── P3: one transaction ─────────────────────────────

it('P3: new_campaign creates a for-a-wave draft campaign with budget 0, its wave board and the wave, linked, and audits both', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();

    $res = $this->actingAs($w['admin'], 'web')
        ->postJson(WaveFixtures::wavesUrl($w['agency'], $w['brand']), WaveFixtures::body(['new_campaign' => ['name' => '  Autumn drop ']]))
        ->assertCreated();

    $campaign = Campaign::query()->where('name', 'Autumn drop')->firstOrFail();
    $res->assertJsonPath('data.relationships.campaign.data.id', $campaign->ulid)
        ->assertJsonPath('data.relationships.campaign.data.attributes.campaign_type', 'for_wave');
    expect($campaign->campaign_type)->toBe(CampaignType::ForWave)
        ->and($campaign->status->value)->toBe('draft')
        ->and($campaign->budget_minor_units)->toBe(0)
        ->and($campaign->creator_posts_content)->toBeFalse()
        ->and($campaign->brand_id)->toBe($w['brand']->id)
        ->and($campaign->budget_currency)->toBe(strtoupper($w['agency']->default_currency))
        ->and(Wave::query()->firstOrFail()->campaign_id)->toBe($campaign->id)
        ->and(Board::query()->where('campaign_id', $campaign->id)->firstOrFail()->columns()->whereNotNull('stage_key')->count())->toBe(8)
        ->and(AuditLog::query()->whereIn('action', ['campaign.created', 'wave.created'])->count())->toBe(2);
});

it('P3: new_campaign together with a campaign_id, or without a name, is 422 and writes nothing', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();
    $existing = WaveFixtures::waveCampaign($w['agency'], $w['brand']);
    $before = wlCounts();

    $this->actingAs($w['admin'], 'web')
        ->postJson(WaveFixtures::wavesUrl($w['agency'], $w['brand']), WaveFixtures::body(['new_campaign' => ['name' => 'X'], 'campaign_id' => $existing->ulid]))
        ->assertUnprocessable();
    $this->actingAs($w['admin'], 'web')
        ->postJson(WaveFixtures::wavesUrl($w['agency'], $w['brand']), WaveFixtures::body(['new_campaign' => ['name' => '']]))
        ->assertUnprocessable();
    $this->actingAs($w['admin'], 'web')
        ->postJson(WaveFixtures::wavesUrl($w['agency'], $w['brand']), WaveFixtures::body(['new_campaign' => []]))
        ->assertCreated();   // an empty object is "no new campaign": a plain wave

    expect(wlCounts()['campaigns'])->toBe($before['campaigns'])
        ->and(Wave::query()->count())->toBe(1);
});

it('L2: a failure at ANY of the three steps rolls back all three: no campaign, no board, no columns, no wave, no audit', function (string $failAt): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();
    $before = wlCounts();

    $model = ['campaign' => Campaign::class, 'board' => Board::class, 'wave' => Wave::class][$failAt];
    $model::creating(static function () use ($model): never {
        $model::flushEventListeners();
        throw new RuntimeException('injected');
    });

    $thrown = false;
    try {
        app(WaveService::class)->createWithNewCampaign($w['agency'], $w['brand'], $w['admin'], WaveFixtures::body(['new_campaign' => ['name' => 'Doomed']]));
    } catch (RuntimeException) {
        $thrown = true;
    }

    expect($thrown)->toBeTrue()
        ->and(wlCounts())->toBe($before);
})->with(['campaign', 'board', 'wave']);

// ───────────────────────────── P4 / L5: the campaign side ─────────────────────────────

it('L5: the campaign side links, changes and unlinks, writes the SAME wave.updated audit as the wave side, and returns the wave', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();
    $campaign = WaveFixtures::waveCampaign($w['agency'], $w['brand']);
    $waveA = Wave::factory()->forBrand($w['agency']->id, $w['brand']->id)->create();
    $waveB = Wave::factory()->forBrand($w['agency']->id, $w['brand']->id)->create();

    $this->actingAs($w['admin'], 'web')->getJson(wlCampaignUrl($campaign, 'wave-options'))->assertOk()
        ->assertJsonCount(2, 'data');

    $this->actingAs($w['admin'], 'web')->putJson(wlCampaignUrl($campaign, 'wave'), ['wave_id' => $waveA->ulid])->assertOk()
        ->assertJsonPath('data.id', $waveA->ulid);
    expect($waveA->fresh()?->campaign_id)->toBe($campaign->id);

    // Change: A is freed, B is linked; the options now offer A (and not B).
    $this->actingAs($w['admin'], 'web')->putJson(wlCampaignUrl($campaign, 'wave'), ['wave_id' => $waveB->ulid])->assertOk();
    expect($waveA->fresh()?->campaign_id)->toBeNull()
        ->and($waveB->fresh()?->campaign_id)->toBe($campaign->id);

    $this->actingAs($w['admin'], 'web')->deleteJson(wlCampaignUrl($campaign, 'wave'))->assertNoContent();
    expect($waveB->fresh()?->campaign_id)->toBeNull();

    $audits = AuditLog::query()->where('action', 'wave.updated')->orderBy('id')->get();
    expect($audits)->toHaveCount(4)
        ->and(array_keys($audits[0]->after))->toBe(array_keys($audits[3]->after))
        ->and($audits[0]->after['campaign_id'])->toBe($campaign->ulid)
        ->and($audits[3]->before['campaign_id'])->toBe($campaign->ulid)
        ->and($audits[3]->after['campaign_id'])->toBeNull();
});

it('L5: the campaign side obeys the same rules: normal campaign, another brand, another agency, an unknown wave are one refusal; a locked link is refused', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();
    $campaign = WaveFixtures::waveCampaign($w['agency'], $w['brand']);
    $normal = WaveFixtures::campaign($w['agency'], $w['brand']);
    $otherBrand = Brand::factory()->forAgency($w['agency']->id)->createOne();
    $waveOtherBrand = Wave::factory()->forBrand($w['agency']->id, $otherBrand->id)->create();
    $waveOtherAgency = Wave::factory()->forBrand($w['otherAgency']->id, $w['otherBrand']->id)->create();
    $free = Wave::factory()->forBrand($w['agency']->id, $w['brand']->id)->create();

    $bodies = [];
    foreach ([[$campaign, $waveOtherBrand->ulid], [$campaign, $waveOtherAgency->ulid], [$campaign, '01ARZ3NDEKTSV4RRFFQ69G5FAV'], [$normal, $free->ulid]] as [$c, $ulid]) {
        $res = $this->actingAs($w['admin'], 'web')->putJson(wlCampaignUrl($c, 'wave'), ['wave_id' => $ulid])->assertUnprocessable();
        $bodies[] = $res->json('errors.0.code');
    }
    expect(array_unique($bodies))->toBe(['wave.campaign_not_available'])
        ->and(Wave::query()->whereNotNull('campaign_id')->count())->toBe(0);

    // Locked: a card in a working column stops change and unlink from this side too.
    $this->actingAs($w['admin'], 'web')->putJson(wlCampaignUrl($campaign, 'wave'), ['wave_id' => $free->ulid])->assertOk();
    WaveFixtures::cardIn($campaign, 'filming');
    $other = Wave::factory()->forBrand($w['agency']->id, $w['brand']->id)->create();
    $this->actingAs($w['admin'], 'web')->putJson(wlCampaignUrl($campaign, 'wave'), ['wave_id' => $other->ulid])
        ->assertUnprocessable()->assertJsonPath('errors.0.code', 'wave.campaign_link_locked');
    $this->actingAs($w['admin'], 'web')->deleteJson(wlCampaignUrl($campaign, 'wave'))
        ->assertUnprocessable()->assertJsonPath('errors.0.code', 'wave.campaign_link_locked');
    expect($free->fresh()?->campaign_id)->toBe($campaign->id);
});

it('L5 / roles: admin and manager link and unlink; staff may read the options only', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();
    $campaign = WaveFixtures::waveCampaign($w['agency'], $w['brand']);
    $wave = Wave::factory()->forBrand($w['agency']->id, $w['brand']->id)->create();

    $this->actingAs($w['staff'], 'web')->getJson(wlCampaignUrl($campaign, 'wave-options'))->assertOk();
    $this->actingAs($w['staff'], 'web')->putJson(wlCampaignUrl($campaign, 'wave'), ['wave_id' => $wave->ulid])->assertForbidden();
    $this->actingAs($w['staff'], 'web')->deleteJson(wlCampaignUrl($campaign, 'wave'))->assertForbidden();
    $this->actingAs($w['manager'], 'web')->putJson(wlCampaignUrl($campaign, 'wave'), ['wave_id' => $wave->ulid])->assertOk();
    $this->actingAs($w['manager'], 'web')->deleteJson(wlCampaignUrl($campaign, 'wave'))->assertNoContent();
});

// ───────────────────────────── T1 / T2 ─────────────────────────────

it('T1: another agency gets 404 on all three campaign-side routes and nothing is written', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();
    $campaign = WaveFixtures::waveCampaign($w['agency'], $w['brand']);
    $wave = Wave::factory()->forBrand($w['agency']->id, $w['brand']->id)->create();
    $before = wlCounts();
    $url = static fn (string $s): string => wlCampaignUrl($campaign, $s, $w['otherAgency']->ulid);

    $this->actingAs($w['otherAdmin'], 'web')->getJson($url('wave-options'))->assertNotFound();
    $this->actingAs($w['otherAdmin'], 'web')->putJson($url('wave'), ['wave_id' => $wave->ulid])->assertNotFound();
    $this->actingAs($w['otherAdmin'], 'web')->deleteJson($url('wave'))->assertNotFound();

    expect(wlCounts())->toBe($before)->and($wave->fresh()?->campaign_id)->toBeNull();
});

it('T2: with waves_enabled OFF every campaign-side route is 404 not_found, and exactly three such routes exist', function (): void {
    $w = WaveFixtures::world();
    $campaign = WaveFixtures::waveCampaign($w['agency'], $w['brand']);
    $wave = Wave::factory()->forBrand($w['agency']->id, $w['brand']->id)->create();

    $this->actingAs($w['admin'], 'web')->getJson(wlCampaignUrl($campaign, 'wave-options'))->assertNotFound()->assertJsonPath('errors.0.code', 'not_found');
    $this->actingAs($w['admin'], 'web')->putJson(wlCampaignUrl($campaign, 'wave'), ['wave_id' => $wave->ulid])->assertNotFound()->assertJsonPath('errors.0.code', 'not_found');
    $this->actingAs($w['admin'], 'web')->deleteJson(wlCampaignUrl($campaign, 'wave'))->assertNotFound()->assertJsonPath('errors.0.code', 'not_found');
    expect($wave->fresh()?->campaign_id)->toBeNull();

    $routes = collect(Route::getRoutes()->getRoutes())->filter(static fn ($r): bool => str_contains($r->uri(), 'campaigns/{campaign}/wave'));
    expect($routes)->toHaveCount(3);
});

it('T2: the campaign resource carries no wave key with the flag OFF and a wave key (null or object) with it ON', function (): void {
    $w = WaveFixtures::world();
    $campaign = WaveFixtures::waveCampaign($w['agency'], $w['brand']);
    $url = "/api/v1/agencies/{$w['agency']->ulid}/campaigns/{$campaign->ulid}";

    $off = $this->actingAs($w['admin'], 'web')->getJson($url)->assertOk()->json('data.relationships');
    expect($off)->not->toHaveKey('wave');

    WaveFixtures::armed();
    $this->actingAs($w['admin'], 'web')->getJson($url)->assertOk()->assertJsonPath('data.relationships.wave', null);
    $wave = Wave::factory()->forBrand($w['agency']->id, $w['brand']->id)->linkedTo($campaign->id)->create();
    $this->actingAs($w['admin'], 'web')->getJson($url)->assertOk()
        ->assertJsonPath('data.relationships.wave.data.id', $wave->ulid)
        ->assertJsonPath('data.relationships.wave.data.attributes.link_locked', false);
});

// ───────────────────────────── L4: the lock is taken (PostgreSQL) ─────────────────────────────

it('L4: the wave-side update, the campaign-side link and a card move into a working column each SELECT the wave row FOR UPDATE', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('SQLite ignores row locks; this assertion is the PostgreSQL run (local and CI).');
    }
    $w = WaveFixtures::world();
    WaveFixtures::armed();
    $campaign = WaveFixtures::waveCampaign($w['agency'], $w['brand']);
    $wave = Wave::factory()->forBrand($w['agency']->id, $w['brand']->id)->linkedTo($campaign->id)->create();
    $card = WaveFixtures::cardIn($campaign, 'invited');
    $selected = $card->board->columns()->where('stage_key', 'selected')->firstOrFail();

    $lockedWaveSelects = static function (callable $act): int {
        $n = 0;
        DB::listen(static function ($q) use (&$n): void {
            if (str_contains(strtolower($q->sql), 'from "waves"') && str_contains(strtolower($q->sql), 'for update')) {
                $n++;
            }
        });
        $act();

        return $n;
    };

    $tests = [
        'wave update' => fn () => $this->actingAs($w['admin'], 'web')->patchJson(WaveFixtures::wavesUrl($w['agency'], $w['brand'], $wave->ulid), ['name' => 'Renamed'])->assertOk(),
        'campaign link' => fn () => $this->actingAs($w['admin'], 'web')->putJson(wlCampaignUrl($campaign, 'wave'), ['wave_id' => $wave->ulid])->assertOk(),
        'card move in' => fn () => $this->actingAs($w['admin'], 'web')->postJson(
            "/api/v1/agencies/{$w['agency']->ulid}/campaigns/{$campaign->ulid}/board/cards/{$card->ulid}/move",
            ['target_column_id' => $selected->ulid],
        )->assertOk(),
    ];
    foreach ($tests as $name => $act) {
        expect($lockedWaveSelects($act))->toBeGreaterThan(0, "{$name} did not lock the wave row");
    }
});
