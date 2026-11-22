<?php

declare(strict_types=1);

use App\Core\Tenancy\TenancyContext;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Strategy\Models\WavePersona;
use App\Modules\Strategy\Services\StrategyWaveMarketGuard;
use App\Modules\Waves\Contracts\WaveMarketGuard;
use App\Modules\Waves\Database\Factories\WaveFactory;
use App\Modules\Waves\Features\WavesEnabled;
use App\Modules\Waves\Models\Wave;
use App\Modules\Waves\Models\WaveMarket;
use App\Modules\Waves\Models\WaveMarketCountry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\StrategyFixtures as S;
use Tests\Support\WaveFixtures;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * PL-2.4 — markets as named groups of countries. Gates: the one create/edit contract (old shape refused), M2 in the API and
 * in the database, short code and name rules (Q4, Q5), limits, the version token (Q12), removal refusal (M6), roles (M7),
 * flags, tenancy, audit literal keys with no market name, nothing crosses a wave, and the persona code prefix (Q6).
 */

/**
 * @param  list<mixed>  $markets
 * @return TestResponse<Response>
 */
function mkCreate(array $w, array $markets, string $who = 'admin'): TestResponse
{
    return S::call($w[$who], 'POST', WaveFixtures::wavesUrl($w['agency'], $w['brand']), WaveFixtures::body(['markets' => $markets]));
}

/** @return array{markets: list<array<string, mixed>>, markets_version: string} the wave as the web reads it */
function mkRead(array $w, ?Wave $wave = null): array
{
    $wave ??= $w['wave'];
    $attrs = S::call($w['admin'], 'GET', WaveFixtures::wavesUrl($w['agency'], $w['brand'], $wave->ulid))->assertOk()->json('data.attributes');

    return ['markets' => $attrs['markets'], 'markets_version' => $attrs['markets_version']];
}

/**
 * @param  list<array<string, mixed>>  $markets
 * @return TestResponse<Response>
 */
function mkPatch(array $w, array $markets, ?string $version = null, string $who = 'admin', ?Wave $wave = null): TestResponse
{
    $wave ??= $w['wave'];

    return S::call($w[$who], 'PATCH', WaveFixtures::wavesUrl($w['agency'], $w['brand'], $wave->ulid), [
        'markets' => $markets, 'markets_version' => $version ?? mkRead($w, $wave)['markets_version'],
    ]);
}

function mkWorld(): array
{
    return S::world();
}

it('W1: create takes markets as objects, makes the rows and countries, and returns them with ulids and a version', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();

    $res = mkCreate($w, [
        ['name' => 'UK & Ireland', 'countries' => ['GB', 'IE']],
        ['name' => 'Slovakia', 'code' => 'SK', 'countries' => ['SK']],
    ])->assertCreated();

    $markets = $res->json('data.attributes.markets');
    expect($markets)->toHaveCount(2)
        ->and($markets[0])->toMatchArray(['name' => 'UK & Ireland', 'code' => 'UKI', 'countries' => ['GB', 'IE']])
        ->and($markets[0]['id'])->toHaveLength(26)
        ->and($markets[1])->toMatchArray(['name' => 'Slovakia', 'code' => 'SK', 'countries' => ['SK']])
        ->and(strlen((string) $res->json('data.attributes.markets_version')))->toBeGreaterThan(0)
        ->and(WaveMarket::query()->count())->toBe(2)
        ->and(WaveMarketCountry::query()->count())->toBe(3);
});

it('W2: the old shape (plain country codes) is refused on create and on edit, and nothing is written', function (): void {
    $w = mkWorld();
    $before = WaveMarket::withoutGlobalScopes()->count();

    mkCreate($w, ['SK', 'RO'])->assertUnprocessable()->assertJsonPath('errors.0.meta.field', 'markets.0');
    S::call($w['admin'], 'PATCH', WaveFixtures::wavesUrl($w['agency'], $w['brand'], $w['wave']->ulid), ['markets' => ['SK'], 'markets_version' => mkRead($w)['markets_version']])
        ->assertUnprocessable()->assertJsonPath('errors.0.meta.field', 'markets.0');

    expect(WaveMarket::withoutGlobalScopes()->count())->toBe($before);
});

it('W2b: a market object with a key outside name, code, countries (and id on an edit) is refused, on create and on edit', function (): void {
    $w = mkWorld();
    $before = WaveMarket::withoutGlobalScopes()->count();

    mkCreate($w, [['name' => 'X', 'countries' => ['SE'], 'position' => 1]])->assertUnprocessable();
    mkCreate($w, [['name' => 'X', 'countries' => ['SE'], 'agency_id' => 1]])->assertUnprocessable();
    $r = mkRead($w);
    $r['markets'][0]['wave_id'] = 1;
    mkPatch($w, $r['markets'], $r['markets_version'])->assertUnprocessable();

    expect(WaveMarket::withoutGlobalScopes()->count())->toBe($before);
});

it('W2c: markets of another agency are invisible to the model (tenancy scope), not only to the routes', function (): void {
    $w = mkWorld();
    $mine = WaveMarket::withoutGlobalScopes()->where('wave_id', $w['wave']->id)->count();
    expect($mine)->toBeGreaterThan(0);

    $seen = app(TenancyContext::class)->runAs($w['agency']->id + 1000, fn (): int => WaveMarket::query()->count());
    $own = app(TenancyContext::class)->runAs($w['agency']->id, fn (): int => WaveMarket::query()->where('wave_id', $w['wave']->id)->count());

    expect($seen)->toBe(0)->and($own)->toBe($mine);
});

it('W3 (M2): a country in two markets is 422 wave.country_in_two_markets, on create and on edit', function (): void {
    $w = mkWorld();

    mkCreate($w, [['name' => 'A', 'countries' => ['SK', 'CZ']], ['name' => 'B', 'countries' => ['CZ']]])
        ->assertUnprocessable()->assertJsonPath('errors.0.code', 'wave.country_in_two_markets');

    $r = mkRead($w);
    $r['markets'][1]['countries'] = ['RO', 'SK'];
    mkPatch($w, $r['markets'], $r['markets_version'])->assertUnprocessable()->assertJsonPath('errors.0.code', 'wave.country_in_two_markets');
    expect(WaveMarketCountry::query()->where('wave_id', $w['wave']->id)->count())->toBe(3);
});

it('W4 (M2 in the database): the key (wave, country) refuses a second market with the same country, on its own', function (): void {
    $w = mkWorld();
    $other = WaveMarket::query()->where('wave_id', $w['wave']->id)->where('code', 'RO')->firstOrFail();

    $insert = fn () => DB::transaction(fn () => DB::table('wave_market_countries')->insert([
        'agency_id' => $w['agency']->id, 'wave_id' => $w['wave']->id, 'market_id' => $other->id, 'country' => 'SK', 'created_at' => now(),
    ]));
    expect($insert)->toThrow(UniqueConstraintViolationException::class);

    // Another wave may use the same country.
    $sibling = Wave::factory()->forBrand($w['agency']->id, $w['brand']->id)->withMarkets(WaveFactory::singleCountryMarkets(['SK']))->create();
    expect(WaveMarketCountry::withoutGlobalScopes()->whereIn('wave_id', [$w['wave']->id, $sibling->id])->where('country', 'SK')->count())->toBe(2)->and($sibling->markets()->count())->toBe(1);
});

it('W5 (Q4, Q5): names are unique ignoring case and spacing; short codes are unique; an omitted code is derived and made unique', function (): void {
    $w = mkWorld();

    mkCreate($w, [['name' => 'Nordics', 'countries' => ['SE']], ['name' => '  nordics ', 'countries' => ['NO']]])
        ->assertUnprocessable()->assertJsonPath('errors.0.code', 'wave.market_name_taken');
    mkCreate($w, [['name' => 'One', 'code' => 'XX', 'countries' => ['SE']], ['name' => 'Two', 'code' => 'XX', 'countries' => ['NO']]])
        ->assertUnprocessable()->assertJsonPath('errors.0.code', 'wave.market_code_taken');

    $res = mkCreate($w, [['name' => 'Nordic North', 'countries' => ['SE', 'NO']], ['name' => 'Nordic Neighbours', 'countries' => ['DK']]])->assertCreated();
    expect($res->json('data.attributes.markets.*.code'))->toBe(['NN', 'DK']);

    $res = mkCreate($w, [['name' => 'Nordic North', 'countries' => ['SE', 'NO']], ['name' => 'North Nordic', 'countries' => ['DK', 'FI']]])->assertCreated();
    expect($res->json('data.attributes.markets.*.code'))->toBe(['NN', 'NN2']);
});

it('W6: code and name shape: 2 to 6 capitals or digits, a name of 1 to 60 characters', function (): void {
    $w = mkWorld();
    foreach ([['code' => 'a1'], ['code' => 'X'], ['code' => 'ABCDEFG'], ['name' => ''], ['name' => str_repeat('n', 61)]] as $bad) {
        mkCreate($w, [['name' => 'Ok', 'code' => 'OK', 'countries' => ['SK']] + [], array_merge(['name' => 'Bad', 'code' => 'BD', 'countries' => ['RO']], $bad)])->assertUnprocessable();
    }
    mkCreate($w, [['name' => str_repeat('n', 60), 'code' => 'A1B2C3', 'countries' => ['SK']]])->assertCreated();
});

it('W7: limits — at most 20 markets and 40 countries in one market, at least one of each', function (): void {
    $w = mkWorld();
    $many = fn (int $n): array => array_map(static fn (int $i): array => ['name' => 'M'.$i, 'code' => 'M'.chr(65 + intdiv($i, 26)).chr(65 + $i % 26), 'countries' => [chr(65 + intdiv($i, 26)).chr(65 + $i % 26)]], range(0, $n - 1));

    mkCreate($w, $many(21))->assertUnprocessable()->assertJsonPath('errors.0.meta.field', 'markets');
    mkCreate($w, $many(20))->assertCreated();

    $countries = fn (int $n): array => array_map(static fn (int $i): string => chr(65 + intdiv($i, 26)).chr(65 + $i % 26), range(0, $n - 1));
    mkCreate($w, [['name' => 'Big', 'countries' => $countries(41)]])->assertUnprocessable()->assertJsonPath('errors.0.meta.field', 'markets.0.countries');
    mkCreate($w, [['name' => 'Big', 'countries' => $countries(40)]])->assertCreated();
    mkCreate($w, [['name' => 'Empty', 'countries' => []]])->assertUnprocessable();
    mkCreate($w, [])->assertUnprocessable();
});

it('W8 (Q12): an edit carries the version; a stale or missing version is refused whole (409 wave.markets_changed) and writes nothing', function (): void {
    $w = mkWorld();
    $tabOne = mkRead($w);

    // A second tab renames Romania and saves.
    $r = mkRead($w);
    $r['markets'][1]['name'] = 'Romania (Wallachia)';
    mkPatch($w, $r['markets'], $r['markets_version'])->assertOk();
    $audit = AuditLog::query()->where('action', 'wave.updated')->count();

    // The first tab still holds the old list and the old version.
    $tabOne['markets'][0]['name'] = 'Slovak Republic';
    mkPatch($w, $tabOne['markets'], $tabOne['markets_version'])->assertStatus(409)->assertJsonPath('errors.0.code', 'wave.markets_changed');

    expect(WaveMarket::query()->where('wave_id', $w['wave']->id)->orderBy('position')->pluck('name')->all())->toBe(['Slovakia', 'Romania (Wallachia)', 'Czechia'])
        ->and(AuditLog::query()->where('action', 'wave.updated')->count())->toBe($audit);

    // No version at all: a validation error, not an overwrite.
    S::call($w['admin'], 'PATCH', WaveFixtures::wavesUrl($w['agency'], $w['brand'], $w['wave']->ulid), ['markets' => $tabOne['markets']])
        ->assertUnprocessable()->assertJsonPath('errors.0.meta.field', 'markets_version');
});

it('W9 (M6): removing a market with personas is 422 wave.market_has_personas with the market, its name and counts; nothing is removed', function (): void {
    $w = mkWorld();
    WavePersona::factory()->create(['wave_id' => $w['wave']->id, 'agency_id' => $w['agency']->id, 'market_id' => $w['mkid']['RO']]);
    $r = mkRead($w);
    $without = array_values(array_filter($r['markets'], static fn (array $m): bool => $m['code'] !== 'RO'));

    $res = mkPatch($w, $without, $r['markets_version'])->assertUnprocessable()->assertJsonPath('errors.0.code', 'wave.market_has_personas');
    expect($res->json('errors.0.meta.markets.0'))->toMatchArray(['market' => $w['mk']['RO'], 'name' => 'Romania', 'code' => 'RO', 'persona_count' => 1])
        ->and(WaveMarket::query()->where('wave_id', $w['wave']->id)->count())->toBe(3);

    // A market without personas can go, and its countries are free again.
    $withoutCz = array_values(array_filter($r['markets'], static fn (array $m): bool => $m['code'] !== 'CZ'));
    mkPatch($w, $withoutCz, $r['markets_version'])->assertOk();
    expect(WaveMarketCountry::query()->where('country', 'CZ')->count())->toBe(0);
});

it('W10: a market can change its countries, swap names and codes with another, and a country can move between markets in one save', function (): void {
    $w = mkWorld();
    $r = mkRead($w);
    [$sk, $ro, $cz] = $r['markets'];

    $swapped = [
        array_merge($sk, ['name' => 'Romania', 'code' => 'RO', 'countries' => ['SK', 'CZ']]),
        array_merge($ro, ['name' => 'Slovakia', 'code' => 'SK', 'countries' => ['RO']]),
        array_merge($cz, ['countries' => ['PL']]),
    ];
    mkPatch($w, $swapped, $r['markets_version'])->assertOk();

    expect(WaveMarket::query()->where('wave_id', $w['wave']->id)->orderBy('position')->get(['name', 'code'])->map(fn ($m) => [$m->name, $m->code])->all())
        ->toBe([['Romania', 'RO'], ['Slovakia', 'SK'], ['Czechia', 'CZ']])
        ->and(WaveMarketCountry::query()->where('market_id', $w['mkid']['SK'])->orderBy('country')->pluck('country')->all())->toBe(['CZ', 'SK']);
});

it('W11 (Q6): changing a market\'s short code leaves the codes of its existing personas alone; new personas take the new prefix', function (): void {
    $w = mkWorld();
    S::call($w['admin'], 'POST', S::u($w, 'personas'), ['market' => $w['mk']['SK'], 'title' => 'One', 'name' => 'A'])->assertCreated();

    $r = mkRead($w);
    $r['markets'][0]['code'] = 'SVK';
    mkPatch($w, $r['markets'], $r['markets_version'])->assertOk();
    S::call($w['admin'], 'POST', S::u($w, 'personas'), ['market' => $w['mk']['SK'], 'title' => 'Two', 'name' => 'B'])->assertCreated();

    expect(WavePersona::query()->where('market_id', $w['mkid']['SK'])->orderBy('slot')->pluck('code')->all())->toBe(['SK1', 'SVK2']);
});

it('W12 (M7): admins and managers edit markets; staff and other agencies cannot; a market of another wave is unknown', function (): void {
    $w = mkWorld();
    $r = mkRead($w);

    mkPatch($w, $r['markets'], $r['markets_version'], 'staff')->assertForbidden();
    mkCreate($w, [['name' => 'X', 'countries' => ['SE']]], 'staff')->assertForbidden();
    mkPatch($w, $r['markets'], $r['markets_version'], 'otherAdmin')->assertNotFound();
    mkPatch($w, $r['markets'], $r['markets_version'], 'manager')->assertOk();

    // A market ulid of another wave in this wave's list.
    $foreign = Wave::factory()->forBrand($w['agency']->id, $w['brand']->id)->create();
    $foreignId = WaveMarket::query()->where('wave_id', $foreign->id)->firstOrFail()->ulid;
    $r = mkRead($w);
    $r['markets'][] = ['id' => $foreignId, 'name' => 'Foreign', 'code' => 'FO', 'countries' => ['FR']];
    mkPatch($w, $r['markets'], $r['markets_version'])->assertUnprocessable()->assertJsonPath('errors.0.code', 'wave.market_unknown');
    expect(WaveMarket::query()->where('wave_id', $foreign->id)->count())->toBe(1);
});

it('W13: the markets answer 404 with the waves flag off, like every wave route', function (): void {
    $w = mkWorld();
    $r = mkRead($w);
    Feature::deactivate(WavesEnabled::NAME);

    mkPatch($w, $r['markets'], $r['markets_version'])->assertNotFound();
    mkCreate($w, [['name' => 'X', 'countries' => ['SE']]])->assertNotFound();
});

it('W14: audit carries market ulids, countries and renamed ulids as literal keys, and never a market name', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();
    $id = mkCreate($w, [['name' => 'Zebra Coast', 'countries' => ['SK']], ['name' => 'Quokka Land', 'countries' => ['RO']]])->assertCreated()->json('data.id');
    $wave = Wave::query()->where('ulid', $id)->firstOrFail();

    $r = mkRead($w, $wave);
    $r['markets'][0]['name'] = 'Zebra Shore';
    $r['markets'][1]['countries'] = ['RO', 'CZ'];
    mkPatch($w, $r['markets'], $r['markets_version'], 'admin', $wave)->assertOk();

    $created = AuditLog::query()->where('action', 'wave.created')->firstOrFail();
    $updated = AuditLog::query()->where('action', 'wave.updated')->firstOrFail();
    $ulids = $r['markets'];
    expect($created->after)->toMatchArray(['markets' => [$ulids[0]['id'], $ulids[1]['id']], 'market_countries' => [$ulids[0]['id'] => ['SK'], $ulids[1]['id'] => ['RO']]])
        ->and($updated->before)->toMatchArray(['market_countries' => [$ulids[0]['id'] => ['SK'], $ulids[1]['id'] => ['RO']]])
        ->and($updated->after)->toMatchArray(['market_countries' => [$ulids[0]['id'] => ['SK'], $ulids[1]['id'] => ['CZ', 'RO']], 'markets_renamed' => [$ulids[0]['id']]]);

    $dump = json_encode([$created->before, $created->after, $updated->before, $updated->after], JSON_THROW_ON_ERROR);
    foreach (['Zebra', 'Quokka'] as $name) {
        expect($dump)->not->toContain($name);
    }
});

it('W15: a refused market edit audits nothing and a no-op edit does not audit a change', function (): void {
    $w = mkWorld();
    $r = mkRead($w);
    $base = AuditLog::query()->where('action', 'wave.updated')->count();

    mkPatch($w, $r['markets'], 'stale', 'admin')->assertStatus(409);
    expect(AuditLog::query()->where('action', 'wave.updated')->count())->toBe($base);

    mkPatch($w, $r['markets'], $r['markets_version'])->assertOk();
    expect(AuditLog::query()->where('action', 'wave.updated')->count())->toBe($base);
});

it('W16: nothing crosses a wave — markets, personas and countries of a sibling wave are untouched by an edit', function (): void {
    $w = mkWorld();
    $sibling = Wave::factory()->forBrand($w['agency']->id, $w['brand']->id)->withMarkets(WaveFactory::singleCountryMarkets(['SK', 'RO']))->create();
    $before = WaveMarket::query()->where('wave_id', $sibling->id)->orderBy('id')->pluck('name', 'ulid')->all();

    $r = mkRead($w);
    $r['markets'][0]['name'] = 'Renamed here only';
    mkPatch($w, $r['markets'], $r['markets_version'])->assertOk();

    expect(WaveMarket::query()->where('wave_id', $sibling->id)->orderBy('id')->pluck('name', 'ulid')->all())->toBe($before);
});

it('W17: the wave resource lists markets in tab order with ulid, name, code and countries; a reorder changes the version', function (): void {
    $w = mkWorld();
    $r = mkRead($w);
    expect(array_column($r['markets'], 'code'))->toBe(['SK', 'RO', 'CZ']);

    $reordered = [$r['markets'][2], $r['markets'][0], $r['markets'][1]];
    mkPatch($w, $reordered, $r['markets_version'])->assertOk();
    $after = mkRead($w);
    expect(array_column($after['markets'], 'code'))->toBe(['CZ', 'SK', 'RO'])->and($after['markets_version'])->not->toBe($r['markets_version']);
});

/** A1: personas of a market with the given slot => code, made straight in the table. */
function a1Personas(array $w, string $market, array $codes): void
{
    foreach ($codes as $slot => $code) {
        WavePersona::factory()->create(['wave_id' => $w['wave']->id, 'agency_id' => $w['agency']->id, 'market_id' => $w['mkid'][$market], 'slot' => $slot, 'code' => $code]);
    }
}

/** A1: the persona codes of a market in slot order. */
function a1Codes(array $w, string $market): array
{
    return WavePersona::query()->where('market_id', $w['mkid'][$market])->orderBy('slot')->pluck('code')->all();
}

/**
 * A1: edit the short code of the first market (SK), ticked or not.
 *
 * @return TestResponse<Response>
 */
function a1Edit(array $w, string $code, ?bool $tick, string $who = 'admin'): TestResponse
{
    $r = mkRead($w);
    $r['markets'][0]['code'] = $code;
    if ($tick !== null) {
        $r['markets'][0]['rename_persona_codes'] = $tick;
    }

    return mkPatch($w, $r['markets'], $r['markets_version'], $who);
}

it('A1-1: ticked, personas with the standard code follow the new short code, same number', function (): void {
    $w = mkWorld();
    a1Personas($w, 'SK', [1 => 'SK1', 2 => 'SK2', 3 => 'SK3']);

    $res = a1Edit($w, 'SVK', true)->assertOk();

    expect(a1Codes($w, 'SK'))->toBe(['SVK1', 'SVK2', 'SVK3'])
        ->and($res->json('meta.persona_codes'))->toBe(['renamed' => 3, 'skipped' => 0, 'kept_custom' => 0]);
});

it('A1-2: a code typed by hand is never changed', function (): void {
    $w = mkWorld();
    a1Personas($w, 'SK', [1 => 'SK1', 2 => 'MYBOY', 3 => 'SK3A', 4 => 'SK9']);

    $res = a1Edit($w, 'SVK', true)->assertOk();

    expect(a1Codes($w, 'SK'))->toBe(['SVK1', 'MYBOY', 'SK3A', 'SK9'])
        ->and($res->json('meta.persona_codes'))->toBe(['renamed' => 1, 'skipped' => 0, 'kept_custom' => 3]);
});

it('A1-3: a rename that would collide with a code of the market is skipped and counted', function (): void {
    $w = mkWorld();
    a1Personas($w, 'SK', [1 => 'SK1', 2 => 'SK2', 3 => 'SVK2']);

    $res = a1Edit($w, 'SVK', true)->assertOk();

    expect(a1Codes($w, 'SK'))->toBe(['SVK1', 'SK2', 'SVK2'])
        ->and($res->json('meta.persona_codes'))->toBe(['renamed' => 1, 'skipped' => 1, 'kept_custom' => 1]);
});

it('A1-4: unticked (or not sent), the codes stay and no result is returned', function (): void {
    $w = mkWorld();
    a1Personas($w, 'SK', [1 => 'SK1', 2 => 'SK2']);

    expect(a1Edit($w, 'SVK', false)->assertOk()->json('meta'))->toBeNull();
    expect(a1Codes($w, 'SK'))->toBe(['SK1', 'SK2']);

    $w2 = mkWorld();
    a1Personas($w2, 'SK', [1 => 'SK1']);
    expect(a1Edit($w2, 'SVX', null)->assertOk()->json('meta'))->toBeNull();
    expect(a1Codes($w2, 'SK'))->toBe(['SK1']);
});

it('A1-5: ticked but the short code did not change, nothing happens; other markets are never touched', function (): void {
    $w = mkWorld();
    a1Personas($w, 'SK', [1 => 'SK1']);
    a1Personas($w, 'RO', [1 => 'RO1', 2 => 'SK2']);

    $r = mkRead($w);
    $r['markets'][0]['rename_persona_codes'] = true;
    $r['markets'][0]['name'] = 'Slovakia renamed';
    $res = mkPatch($w, $r['markets'], $r['markets_version'])->assertOk();
    expect($res->json('meta'))->toBeNull()->and(a1Codes($w, 'SK'))->toBe(['SK1']);

    a1Edit($w, 'SVK', true)->assertOk();
    expect(a1Codes($w, 'RO'))->toBe(['RO1', 'SK2']);
});

it('A1-6: staff are refused and another agency cannot see the wave; nothing changes', function (): void {
    $w = mkWorld();
    a1Personas($w, 'SK', [1 => 'SK1']);

    a1Edit($w, 'SVK', true, 'staff')->assertForbidden();
    a1Edit($w, 'SVK', true, 'otherAdmin')->assertNotFound();

    expect(a1Codes($w, 'SK'))->toBe(['SK1'])->and(WaveMarket::query()->where('code', 'SK')->exists())->toBeTrue();
});

it('A1-7: the audit carries ids and counts only, in one wave.updated entry', function (): void {
    $w = mkWorld();
    a1Personas($w, 'SK', [1 => 'SK1', 2 => 'MINE']);

    a1Edit($w, 'SVK', true)->assertOk();

    $updated = AuditLog::query()->where('action', 'wave.updated')->latest('id')->firstOrFail();
    expect($updated->after)->toMatchArray(['persona_codes_renamed' => 1, 'persona_codes_skipped' => 0, 'persona_codes_markets' => [$w['mk']['SK']]]);
    $dump = json_encode($updated->after, JSON_THROW_ON_ERROR);
    foreach (['SVK1', 'MINE', 'SK1'] as $code) {
        expect($dump)->not->toContain($code);
    }
});

it('A1-8: the flag must be a boolean and cannot be sent on create', function (): void {
    $w = mkWorld();
    $r = mkRead($w);
    $r['markets'][0]['rename_persona_codes'] = 'maybe';
    mkPatch($w, $r['markets'], $r['markets_version'])->assertUnprocessable();

    mkCreate($w, [['name' => 'X', 'countries' => ['SE'], 'rename_persona_codes' => true]])->assertUnprocessable();
});

it('A1-9: a failure after the persona renames rolls the market edit back with them', function (): void {
    $w = mkWorld();
    a1Personas($w, 'SK', [1 => 'SK1', 2 => 'SK2']);
    $before = mkRead($w);

    // The real guard renames the personas inside the wave edit's transaction, then something fails afterwards.
    app()->bind(WaveMarketGuard::class, static fn (): WaveMarketGuard => new class(new StrategyWaveMarketGuard) implements WaveMarketGuard
    {
        public function __construct(private readonly StrategyWaveMarketGuard $real) {}

        public function personaCountsByMarket(Wave $locked, array $removedIds): array
        {
            return $this->real->personaCountsByMarket($locked, $removedIds);
        }

        public function conceptCountsByMarket(Wave $locked, array $removedIds): array
        {
            return $this->real->conceptCountsByMarket($locked, $removedIds);
        }

        public function noteCountsByMarket(Wave $locked, array $removedIds): array
        {
            return $this->real->noteCountsByMarket($locked, $removedIds);
        }

        public function creatorCountsByMarket(Wave $locked, array $removedIds): array
        {
            return $this->real->creatorCountsByMarket($locked, $removedIds);
        }

        public function forgetMarkets(Wave $locked, array $removedIds): array
        {
            return $this->real->forgetMarkets($locked, $removedIds);
        }

        public function renamePersonaCodes(Wave $locked, int $marketId, string $oldCode, string $newCode): array
        {
            $done = $this->real->renamePersonaCodes($locked, $marketId, $oldCode, $newCode);
            if ($done['renamed'] !== 2) {
                throw new LogicException('the renames did not happen before the failure');
            }

            throw new RuntimeException('forced failure after the persona renames');
        }
    });

    $this->withoutExceptionHandling();
    $edit = $before['markets'];
    $edit[0]['code'] = 'SVK';
    $edit[0]['name'] = 'Slovakia renamed';
    $edit[0]['rename_persona_codes'] = true;

    expect(fn () => mkPatch($w, $edit, $before['markets_version']))->toThrow(RuntimeException::class, 'forced failure');

    expect(a1Codes($w, 'SK'))->toBe(['SK1', 'SK2'])
        ->and(mkRead($w))->toBe($before)
        ->and(AuditLog::query()->where('action', 'wave.updated')->count())->toBe(0);
});
