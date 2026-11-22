<?php

declare(strict_types=1);

use App\Modules\Creators\Models\Creator;
use App\Modules\Strategy\Enums\CreatorDecisionStatus;
use App\Modules\Strategy\Models\WaveCreator;
use App\Modules\Strategy\Models\WaveCreatorDecision;
use App\Modules\Strategy\Models\WavePersona;
use App\Modules\Waves\Database\Factories\WaveFactory;
use App\Modules\Waves\Models\Wave;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * PL-3.1 M1/M2 — the two tables and their database guards: composite foreign keys (same wave, same market, persona of that
 * market), unique keys (one creator per wave; a slot is unique in a market), RESTRICT, and the PostgreSQL CHECKs. These run
 * on SQLite and PostgreSQL; the CHECK cases run on PostgreSQL only.
 */

function wcFailing(Closure $statement): Closure
{
    return function () use ($statement): void {
        DB::transaction(function () use ($statement): void {
            $statement();
        });
    };
}

/** @return array<string, mixed> */
function wcRow(Wave $wave, int $marketId, array $extra = []): array
{
    return array_merge([
        'ulid' => (string) Str::ulid(), 'agency_id' => $wave->agency_id, 'wave_id' => $wave->id, 'market_id' => $marketId,
        'creator_id' => Creator::factory()->create()->id, 'persona_id' => null, 'slot' => 1, 'slot_code' => 'SK-C1',
        'created_at' => now(), 'updated_at' => now(),
    ], $extra);
}

it('M1: the tables have the planned columns and no status column on the slot', function (): void {
    expect(Schema::hasColumns('wave_creators', ['id', 'ulid', 'agency_id', 'wave_id', 'market_id', 'creator_id', 'persona_id', 'slot', 'slot_code', 'added_by_user_id', 'created_at', 'updated_at']))->toBeTrue()
        ->and(Schema::hasColumn('wave_creators', 'status'))->toBeFalse()
        ->and(Schema::hasColumns('wave_creator_decisions', ['id', 'ulid', 'agency_id', 'wave_id', 'wave_creator_id', 'status', 'client_name', 'note', 'comment', 'recorded_by_user_id', 'recorded_at']))->toBeTrue();

    $slot = WaveCreator::factory()->create();
    expect($slot->slot)->toBe(1)->and($slot->slot_code)->toBe('SK-C1');
});

it('M1: one creator per wave (unique wave_id + creator_id)', function (): void {
    $wave = Wave::factory()->withMarkets(WaveFactory::singleCountryMarkets(['SK', 'RO']))->create();
    $sk = WaveFactory::marketId($wave, 'SK');
    $ro = WaveFactory::marketId($wave, 'RO');
    $creator = Creator::factory()->create();
    DB::table('wave_creators')->insert(wcRow($wave, $sk, ['creator_id' => $creator->id]));

    expect(wcFailing(fn () => DB::table('wave_creators')->insert(wcRow($wave, $ro, ['creator_id' => $creator->id, 'slot_code' => 'RO-C1']))))
        ->toThrow(QueryException::class);
});

it('M1: a slot and a slot code are unique inside a market', function (): void {
    $wave = Wave::factory()->withMarkets(WaveFactory::singleCountryMarkets(['SK']))->create();
    $sk = WaveFactory::marketId($wave, 'SK');
    DB::table('wave_creators')->insert(wcRow($wave, $sk));

    expect(wcFailing(fn () => DB::table('wave_creators')->insert(wcRow($wave, $sk, ['slot_code' => 'SK-C9']))))->toThrow(QueryException::class)
        ->and(wcFailing(fn () => DB::table('wave_creators')->insert(wcRow($wave, $sk, ['slot' => 2]))))->toThrow(QueryException::class);
    DB::table('wave_creators')->insert(wcRow($wave, $sk, ['slot' => 2, 'slot_code' => 'SK-C2']));
});

it('M1: the market must be a market of the SAME wave and the persona one of that wave and market (composite foreign keys)', function (): void {
    $wave = Wave::factory()->withMarkets(WaveFactory::singleCountryMarkets(['SK', 'RO']))->create();
    $sk = WaveFactory::marketId($wave, 'SK');
    $skPersona = WavePersona::factory()->create(['wave_id' => $wave->id, 'agency_id' => $wave->agency_id, 'market_id' => $sk]);
    $roPersona = WavePersona::factory()->create(['wave_id' => $wave->id, 'agency_id' => $wave->agency_id, 'market_id' => WaveFactory::marketId($wave, 'RO')]);
    $otherWave = Wave::factory()->withMarkets(WaveFactory::singleCountryMarkets(['SK']))->create();

    expect(wcFailing(fn () => DB::table('wave_creators')->insert(wcRow($wave, WaveFactory::marketId($otherWave, 'SK')))))->toThrow(QueryException::class)
        ->and(wcFailing(fn () => DB::table('wave_creators')->insert(wcRow($wave, $sk, ['persona_id' => $roPersona->id]))))->toThrow(QueryException::class);
    DB::table('wave_creators')->insert(wcRow($wave, $sk, ['persona_id' => $skPersona->id]));
    expect(DB::table('wave_creators')->count())->toBe(1);
});

it('M1: the creator row and the wave cannot be deleted under a slot (RESTRICT)', function (): void {
    $slot = WaveCreator::factory()->create();

    expect(wcFailing(fn () => DB::table('creators')->where('id', $slot->creator_id)->delete()))->toThrow(QueryException::class)
        ->and(wcFailing(fn () => DB::table('wave_markets')->where('id', $slot->market_id)->delete()))->toThrow(QueryException::class);
});

it('M1: PostgreSQL CHECKs: slot 1-99 and a slot code of 2-10 characters', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL CHECK constraints.');
    }
    $wave = Wave::factory()->withMarkets(WaveFactory::singleCountryMarkets(['SK']))->create();
    $sk = WaveFactory::marketId($wave, 'SK');

    expect(wcFailing(fn () => DB::table('wave_creators')->insert(wcRow($wave, $sk, ['slot' => 0]))))->toThrow(QueryException::class)
        ->and(wcFailing(fn () => DB::table('wave_creators')->insert(wcRow($wave, $sk, ['slot' => 100]))))->toThrow(QueryException::class)
        ->and(wcFailing(fn () => DB::table('wave_creators')->insert(wcRow($wave, $sk, ['slot_code' => 'X']))))->toThrow(QueryException::class)
        ->and(wcFailing(fn () => DB::table('wave_creators')->insert(wcRow($wave, $sk, ['slot_code' => 'ABCDEFGHIJK']))))->toThrow(QueryException::class);
});

it('M2: a decision belongs to a slot of the SAME wave; the slot cannot be deleted under it (RESTRICT)', function (): void {
    $slot = WaveCreator::factory()->create();
    $other = Wave::factory()->create();
    $row = ['ulid' => (string) Str::ulid(), 'agency_id' => $slot->agency_id, 'status' => 'approved', 'client_name' => 'Anna', 'recorded_at' => now(), 'created_at' => now(), 'updated_at' => now()];

    expect(wcFailing(fn () => DB::table('wave_creator_decisions')->insert($row + ['wave_id' => $other->id, 'wave_creator_id' => $slot->id])))->toThrow(QueryException::class);
    $d = WaveCreatorDecision::factory()->create(['wave_creator_id' => $slot->id, 'status' => CreatorDecisionStatus::Approved]);

    expect($d->status)->toBe(CreatorDecisionStatus::Approved)
        ->and(wcFailing(fn () => DB::table('wave_creators')->where('id', $slot->id)->delete()))->toThrow(QueryException::class);
});

it('M2: PostgreSQL CHECKs: status values, a comment only on suggest-another, a note only on approved', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL CHECK constraints.');
    }
    $slot = WaveCreator::factory()->create();
    $base = ['ulid' => (string) Str::ulid(), 'agency_id' => $slot->agency_id, 'wave_id' => $slot->wave_id, 'wave_creator_id' => $slot->id, 'client_name' => 'Anna', 'recorded_at' => now(), 'created_at' => now(), 'updated_at' => now()];
    $ins = fn (array $x) => wcFailing(fn () => DB::table('wave_creator_decisions')->insert(['ulid' => (string) Str::ulid()] + $x + $base));

    expect($ins(['status' => 'maybe']))->toThrow(QueryException::class)
        ->and($ins(['status' => 'approved', 'comment' => 'x']))->toThrow(QueryException::class)
        ->and($ins(['status' => 'suggest_another', 'note' => 'x']))->toThrow(QueryException::class);
    DB::table('wave_creator_decisions')->insert(['status' => 'suggest_another', 'comment' => null] + $base);
    expect(DB::table('wave_creator_decisions')->count())->toBe(1);
});
