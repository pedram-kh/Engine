<?php

declare(strict_types=1);

use App\Modules\Strategy\Models\WavePersona;
use App\Modules\Waves\Database\Factories\WaveFactory;
use App\Modules\Waves\Models\Wave;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * PL-2.4 — the database keys of a market, each shown to refuse on its own (the application checks are the first line, these
 * are the belt): unique code and unique case-insensitive name per wave, one country per wave, the composite keys that tie a
 * persona to a market of the same wave, RESTRICT, and nothing writable twice.
 */

function marketRow(Wave $wave, string $name, string $code, int $position = 1): array
{
    return ['ulid' => (string) Str::ulid(), 'agency_id' => $wave->agency_id, 'wave_id' => $wave->id, 'name' => $name, 'code' => $code, 'position' => $position, 'created_at' => now(), 'updated_at' => now()];
}

/** A statement expected to fail, in a savepoint so PostgreSQL's aborted-transaction state does not leak. */
function marketFails(Closure $statement): Closure
{
    return function () use ($statement): void {
        DB::transaction(function () use ($statement): void {
            $statement();
        });
    };
}

it('MK1: wave_markets has the planned columns', function (): void {
    expect(Schema::hasColumns('wave_markets', ['id', 'ulid', 'agency_id', 'wave_id', 'name', 'code', 'position', 'created_at', 'updated_at']))->toBeTrue();
});

it('MK2: a short code is unique within a wave and free in another wave', function (): void {
    $a = Wave::factory()->withMarkets([])->create();
    $b = Wave::factory()->withMarkets([])->create();
    DB::table('wave_markets')->insert(marketRow($a, 'One', 'UKI'));

    expect(marketFails(fn () => DB::table('wave_markets')->insert(marketRow($a, 'Two', 'UKI', 2))))->toThrow(UniqueConstraintViolationException::class);
    DB::table('wave_markets')->insert(marketRow($b, 'One', 'UKI'));
    expect(DB::table('wave_markets')->count())->toBe(2);
});

it('MK3: a name is unique within a wave ignoring case, free in another wave', function (): void {
    $a = Wave::factory()->withMarkets([])->create();
    $b = Wave::factory()->withMarkets([])->create();
    DB::table('wave_markets')->insert(marketRow($a, 'Nordics', 'NOR'));

    expect(marketFails(fn () => DB::table('wave_markets')->insert(marketRow($a, 'NORDICS', 'NO2', 2))))->toThrow(UniqueConstraintViolationException::class);
    expect(marketFails(fn () => DB::table('wave_markets')->insert(marketRow($a, 'nordics', 'NO3', 3))))->toThrow(UniqueConstraintViolationException::class);
    DB::table('wave_markets')->insert(marketRow($b, 'Nordics', 'NOR'));
    expect(DB::table('wave_markets')->count())->toBe(2);
});

it('MK4 (M2): a country belongs to one market of a wave; the same country in another wave is free; the country\'s market must be of the same wave', function (): void {
    $a = Wave::factory()->withMarkets(WaveFactory::singleCountryMarkets(['SK', 'RO']))->create();
    $b = Wave::factory()->withMarkets(WaveFactory::singleCountryMarkets(['SK']))->create();
    $roId = WaveFactory::marketId($a, 'RO');
    $bSkId = WaveFactory::marketId($b, 'SK');
    $row = fn (Wave $w, int $marketId, string $country): array => ['agency_id' => $w->agency_id, 'wave_id' => $w->id, 'market_id' => $marketId, 'country' => $country, 'created_at' => now()];

    expect(marketFails(fn () => DB::table('wave_market_countries')->insert($row($a, $roId, 'SK'))))->toThrow(UniqueConstraintViolationException::class)
        ->and(marketFails(fn () => DB::table('wave_market_countries')->insert($row($a, $bSkId, 'CZ'))))->toThrow(QueryException::class);
    DB::table('wave_market_countries')->insert($row($a, $roId, 'CZ'));
    expect(DB::table('wave_market_countries')->where('wave_id', $a->id)->count())->toBe(3);
});

it('MK5: a persona\'s market must be a market of the persona\'s own wave (composite foreign key); no market_id is refused', function (): void {
    $a = Wave::factory()->withMarkets(WaveFactory::singleCountryMarkets(['SK']))->create();
    $b = Wave::factory()->withMarkets(WaveFactory::singleCountryMarkets(['SK']))->create();
    $bSk = WaveFactory::marketId($b, 'SK');

    expect(marketFails(fn () => WavePersona::factory()->create(['wave_id' => $a->id, 'agency_id' => $a->agency_id, 'market_id' => $bSk])))->toThrow(QueryException::class)
        ->and(marketFails(fn () => DB::table('wave_personas')->insert(['wave_id' => $a->id, 'agency_id' => $a->agency_id])))->toThrow(QueryException::class);
    WavePersona::factory()->create(['wave_id' => $a->id, 'agency_id' => $a->agency_id, 'market_id' => WaveFactory::marketId($a, 'SK')]);
    expect(WavePersona::withoutGlobalScopes()->count())->toBe(1);
});

it('MK6: RESTRICT: a market that has a persona, a country row or a wave behind it cannot be hard-deleted', function (): void {
    $wave = Wave::factory()->withMarkets(WaveFactory::singleCountryMarkets(['SK']))->create();
    $id = WaveFactory::marketId($wave, 'SK');
    WavePersona::factory()->create(['wave_id' => $wave->id, 'agency_id' => $wave->agency_id, 'market_id' => $id]);

    expect(marketFails(fn () => DB::table('wave_markets')->where('id', $id)->delete()))->toThrow(QueryException::class)
        ->and(marketFails(fn () => DB::table('waves')->where('id', $wave->id)->delete()))->toThrow(QueryException::class);
});

it('MK6b: RESTRICT on the wave alone: a wave with a market (and no country row, no persona) cannot be hard-deleted', function (): void {
    $wave = Wave::factory()->withMarkets([])->create();
    DB::table('wave_markets')->insert(marketRow($wave, 'Alone', 'AL'));

    expect(marketFails(fn () => DB::table('waves')->where('id', $wave->id)->delete()))->toThrow(QueryException::class);
});

it('MK8: the market migrations (new and edited) are schema-only (no data statement) and set a lock timeout first', function (): void {
    $files = array_merge(
        glob(database_path('migrations/2026_11_20_09000*.php')) ?: [],
        glob(database_path('migrations/2026_11_20_100002*.php')) ?: [],
        glob(database_path('migrations/2026_11_21_10000*.php')) ?: [],
    );
    expect(count($files))->toBeGreaterThanOrEqual(5);
    foreach ($files as $file) {
        $source = (string) file_get_contents($file);
        expect($source)->not->toMatch('/DB::table\(|->insert\(|->update\(|->delete\(|insertOrIgnore|upsert\(|Model::|(?<!Schema)::create\(|DB::unprepared\(/')
            ->and($source)->toContain("SET LOCAL lock_timeout = '5s'");
    }
});

it('MK7: the market ulid is unique', function (): void {
    $a = Wave::factory()->withMarkets([])->create();
    DB::table('wave_markets')->insert($one = marketRow($a, 'One', 'ON'));

    expect(marketFails(fn () => DB::table('wave_markets')->insert(array_merge(marketRow($a, 'Two', 'TW', 2), ['ulid' => $one['ulid']]))))->toThrow(UniqueConstraintViolationException::class);
});
