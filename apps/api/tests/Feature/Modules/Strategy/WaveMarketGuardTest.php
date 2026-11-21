<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Strategy\Features\StrategyEnabled;
use App\Modules\Strategy\Models\WavePersona;
use App\Modules\Strategy\Services\StrategyWaveMarketGuard;
use App\Modules\Waves\Contracts\WaveMarketGuard;
use App\Modules\Waves\Database\Factories\WaveFactory;
use App\Modules\Waves\Models\Wave;
use App\Modules\Waves\Services\WaveMarketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\StrategyFixtures as S;
use Tests\Support\WaveFixtures;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * PL-2.1 Q5 / M3 — a wave edit that REMOVES a market which still has personas is 422 `wave.market_has_personas` with
 * the markets and counts in `meta`, even with `strategy_enabled` OFF (it is a data-integrity rule). The refusal sits in
 * WaveService::update, inside its transaction, behind the Waves\Contracts\WaveMarketGuard contract.
 */

function personaIn(array $w, string $market, int $n = 1): void
{
    for ($i = 0; $i < $n; $i++) {
        WavePersona::factory()->create(['wave_id' => $w['wave']->id, 'agency_id' => $w['agency']->id, 'market_id' => $w['mkid'][$market]]);
    }
}

/**
 * The edit the web sends: the wave's markets with these short codes, in this order (a code the wave does not have is added as a
 * one-country market named for the country), and the version token read with them.
 *
 * @param  list<string>  $codes
 * @return TestResponse<Response>
 */
function editMarkets(array $w, array $codes): TestResponse
{
    $url = WaveFixtures::wavesUrl($w['agency'], $w['brand'], $w['wave']->ulid);
    $attrs = S::call($w['admin'], 'GET', $url)->assertOk()->json('data.attributes');
    $byCode = array_column($attrs['markets'], null, 'code');
    $markets = array_map(static fn (string $c): array => $byCode[$c] ?? WaveFactory::singleCountryMarkets([$c])[0], $codes);

    return S::call($w['admin'], 'PATCH', $url, ['markets' => $markets, 'markets_version' => $attrs['markets_version']]);
}

/**
 * @param  TestResponse<Response>  $res
 * @return array<string, int> short code => persona count
 */
function refusedCounts(TestResponse $res): array
{
    $out = [];
    foreach ($res->json('errors.0.meta.markets') as $m) {
        $out[$m['code']] = $m['persona_count'];
    }
    ksort($out);

    return $out;
}

it('M3: removing a market that has personas is 422 wave.market_has_personas with the counts; nothing changes, nothing audited', function (): void {
    $w = S::world();
    personaIn($w, 'SK', 3);
    $auditBefore = AuditLog::query()->where('action', 'like', 'wave.%')->count();

    $res = editMarkets($w, ['RO', 'CZ'])->assertUnprocessable();

    expect($res->json('errors.0.code'))->toBe('wave.market_has_personas')
        ->and(refusedCounts($res))->toBe(['SK' => 3])
        ->and($res->json('errors.0.meta.markets.0.market'))->toBe($w['mk']['SK'])
        ->and($w['wave']->refresh()->markets->pluck('code')->all())->toBe(['SK', 'RO', 'CZ'])
        ->and(AuditLog::query()->where('action', 'like', 'wave.%')->count())->toBe($auditBefore);
});

it('M3: several markets with personas are all listed; one without is not', function (): void {
    $w = S::world();
    personaIn($w, 'SK', 2);
    personaIn($w, 'RO', 1);

    $res = editMarkets($w, ['CZ'])->assertUnprocessable();
    expect(refusedCounts($res))->toBe(['RO' => 1, 'SK' => 2]);
});

it('M3: removing a market with no personas is allowed, adding and re-ordering are allowed, and personas elsewhere stay', function (): void {
    $w = S::world();
    personaIn($w, 'SK', 2);

    editMarkets($w, ['SK', 'RO'])->assertOk();                // CZ had none
    editMarkets($w, ['RO', 'SK'])->assertOk();                // re-ordered
    editMarkets($w, ['RO', 'SK', 'PL'])->assertOk();          // added
    expect($w['wave']->refresh()->markets->pluck('code')->all())->toBe(['RO', 'SK', 'PL'])->and(WavePersona::query()->count())->toBe(2);
});

it('M3: after the personas of the market are deleted the market can be removed', function (): void {
    $w = S::world();
    personaIn($w, 'CZ', 1);
    editMarkets($w, ['SK', 'RO'])->assertUnprocessable();

    WavePersona::query()->delete();
    editMarkets($w, ['SK', 'RO'])->assertOk();
    expect($w['wave']->refresh()->markets->pluck('code')->all())->toBe(['SK', 'RO']);
});

it('M3: the refusal works with strategy_enabled OFF, and with no persona rows the edit is exactly PL-1', function (): void {
    $w = S::world();
    personaIn($w, 'SK', 1);
    Feature::deactivate(StrategyEnabled::NAME);

    editMarkets($w, ['RO', 'CZ'])->assertUnprocessable()->assertJsonPath('errors.0.code', 'wave.market_has_personas');

    WavePersona::query()->delete();
    editMarkets($w, ['RO', 'CZ'])->assertOk();
});

it('M3: a name-only edit and a same-markets edit never consult the guard', function (): void {
    $w = S::world();
    personaIn($w, 'SK', 1);

    S::call($w['admin'], 'PATCH', WaveFixtures::wavesUrl($w['agency'], $w['brand'], $w['wave']->ulid), ['name' => 'Renamed'])->assertOk();
    editMarkets($w, ['SK', 'RO', 'CZ'])->assertOk();
});

it('M3: the other order of the race: market removed first, then a persona create in it is 422 persona.market_not_in_wave', function (): void {
    $w = S::world();

    editMarkets($w, ['SK', 'RO'])->assertOk();
    S::call($w['admin'], 'POST', S::u($w, 'personas'), ['market' => $w['mk']['CZ'], 'title' => 'T', 'name' => 'N'])
        ->assertUnprocessable()->assertJsonPath('errors.0.code', 'persona.market_not_in_wave');
    expect(WavePersona::query()->count())->toBe(0);
});

it('M3: the guard is bound in the container to the Strategy implementation and WaveMarketService (which WaveService edits markets through) uses it', function (): void {
    expect(app(WaveMarketGuard::class))->toBeInstanceOf(StrategyWaveMarketGuard::class);
    $service = app(WaveMarketService::class);
    $prop = (new ReflectionClass($service))->getProperty('guard');
    expect($prop->getValue($service))->toBeInstanceOf(WaveMarketGuard::class);
});

it('M3: the guard only counts the personas of THIS wave', function (): void {
    $w = S::world();
    $sibling = Wave::factory()->forBrand($w['agency']->id, $w['brand']->id)->create();
    WavePersona::factory()->create(['wave_id' => $sibling->id, 'agency_id' => $w['agency']->id, 'market_id' => WaveFactory::marketId($sibling, 'SK')]);

    editMarkets($w, ['RO', 'CZ'])->assertOk();   // the sibling's SK personas are irrelevant
});
