<?php

declare(strict_types=1);

use App\Modules\Strategy\Enums\UspColor;
use App\Modules\Strategy\Models\WavePersona;
use App\Modules\Strategy\Models\WavePersonaAngle;
use App\Modules\Strategy\Models\WaveUsp;
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
 * PL-2.1 — P1 (the four migrations, up and down), L2 and L4 (the unique-index belts), T3 and U1 (the composite foreign
 * keys), C5 (RESTRICT). A statement expected to fail runs inside a nested transaction (a savepoint): on PostgreSQL a
 * failed statement aborts the surrounding transaction, on SQLite it does not. These tests run on both.
 */

/**
 * @param  Closure(): mixed  $fn
 */
function strategySavepoint(Closure $fn): Closure
{
    return fn (): mixed => DB::transaction($fn);
}

it('P1: creates the three tables with the planned columns and the nullable audience title on waves', function (): void {
    expect(Schema::hasColumns('wave_usps', [
        'id', 'ulid', 'agency_id', 'wave_id', 'color_key', 'position', 'title', 'explainer', 'creative_angle', 'copy_seed', 'created_at', 'updated_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('wave_personas', [
            'id', 'ulid', 'agency_id', 'wave_id', 'market_id', 'slot', 'code', 'title', 'name', 'pronoun', 'summary', 'core_job', 'need',
            'drivers', 'goals', 'self_talk', 'barriers', 'created_at', 'updated_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('wave_persona_angles', [
            'id', 'ulid', 'agency_id', 'wave_id', 'persona_id', 'usp_id', 'explanation', 'origin', 'created_at', 'updated_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumn('waves', 'usp_audience_title'))->toBeTrue();

    // No user column anywhere (who did it is in the audit log), and no avatar column.
    foreach (['wave_usps', 'wave_personas', 'wave_persona_angles'] as $table) {
        expect(Schema::hasColumn($table, 'created_by_user_id'))->toBeFalse()
            ->and(Schema::hasColumn($table, 'updated_by_user_id'))->toBeFalse();
    }
    expect(Schema::hasColumn('wave_personas', 'avatar'))->toBeFalse();

    // Existing waves keep a null title: the column has no default.
    expect(Wave::query()->findOrFail(Wave::factory()->create()->id)->usp_audience_title)->toBeNull();
});

it('P1: the eleven migrations run down and up again', function (): void {
    $names = [
        '2026_11_20_100000_add_usp_audience_title_to_waves',
        '2026_11_20_100001_create_wave_usps_table',
        '2026_11_20_100002_create_wave_personas_table',
        '2026_11_20_100003_create_wave_persona_angles_table',
        // PL-2.2: the cells table depends on wave_personas and wave_usps, so it goes down first.
        '2026_11_21_100000_add_market_unique_to_wave_personas',
        '2026_11_21_100001_create_wave_matrix_cells_table',
        // PL-2.3: the concept tables depend on wave_personas and wave_usps (and the children on wave_concepts), so they go down first.
        '2026_11_21_200001_create_wave_concepts_table',
        '2026_11_21_200002_create_wave_concept_usps_table',
        '2026_11_21_200003_create_wave_concept_images_table',
        '2026_11_21_200004_create_wave_concept_links_table',
        '2026_11_21_200005_create_wave_concept_decisions_table',
        // PL-3.1: the creator slots reference wave_personas (composite foreign key), the decisions reference the slots.
        '2026_11_23_100000_create_wave_creators_table',
        '2026_11_23_100001_create_wave_creator_decisions_table',
        // PL-3.2a: the scripts reference the slots (composite foreign key, which needs the new unique key), the hooks the scripts, the texts the hooks.
        '2026_11_24_100000_add_creator_key_to_wave_creators',
        '2026_11_24_100002_create_wave_scripts_table',
        '2026_11_24_100003_create_wave_script_hooks_table',
        '2026_11_24_100004_create_wave_script_hook_texts_table',
    ];
    foreach (array_reverse($names) as $name) {
        (require database_path("migrations/{$name}.php"))->down();
    }
    expect(Schema::hasTable('wave_usps'))->toBeFalse()
        ->and(Schema::hasTable('wave_personas'))->toBeFalse()
        ->and(Schema::hasTable('wave_persona_angles'))->toBeFalse()
        ->and(Schema::hasTable('wave_concepts'))->toBeFalse()
        ->and(Schema::hasTable('wave_concept_decisions'))->toBeFalse()
        ->and(Schema::hasColumn('waves', 'usp_audience_title'))->toBeFalse();

    foreach ($names as $name) {
        (require database_path("migrations/{$name}.php"))->up();
    }
    expect(Schema::hasTable('wave_usps'))->toBeTrue()
        ->and(Schema::hasTable('wave_personas'))->toBeTrue()
        ->and(Schema::hasTable('wave_persona_angles'))->toBeTrue()
        ->and(Schema::hasTable('wave_concepts'))->toBeTrue()
        ->and(Schema::hasTable('wave_concept_decisions'))->toBeTrue()
        ->and(Schema::hasColumn('waves', 'usp_audience_title'))->toBeTrue();
});

it('L2: unique (wave_id, color_key) refuses a duplicate colour and a seventh row cannot exist; another wave is free', function (): void {
    $wave = Wave::factory()->create();
    foreach (UspColor::cases() as $i => $color) {
        WaveUsp::factory()->create(['wave_id' => $wave->id, 'color_key' => $color, 'position' => $i + 1]);
    }

    expect(strategySavepoint(fn () => WaveUsp::factory()->create(['wave_id' => $wave->id, 'color_key' => UspColor::Violet, 'position' => 7])))
        ->toThrow(UniqueConstraintViolationException::class);
    // Any seventh row needs a colour already taken (the six keys are all used).
    expect(WaveUsp::query()->where('wave_id', $wave->id)->count())->toBe(6);

    $other = Wave::factory()->create();
    WaveUsp::factory()->create(['wave_id' => $other->id, 'color_key' => UspColor::Violet]);
    expect(WaveUsp::query()->where('wave_id', $other->id)->count())->toBe(1);
});

it('L4: unique (wave_id, market, slot) refuses a duplicate slot; another market and another wave are free', function (): void {
    $wave = Wave::factory()->withMarkets(WaveFactory::singleCountryMarkets(['SK', 'RO']))->create();
    WavePersona::factory()->create(['wave_id' => $wave->id, 'market_id' => WaveFactory::marketId($wave, 'SK'), 'slot' => 3, 'code' => 'SK3']);

    expect(strategySavepoint(fn () => WavePersona::factory()->create(['wave_id' => $wave->id, 'market_id' => WaveFactory::marketId($wave, 'SK'), 'slot' => 3, 'code' => 'SKX'])))
        ->toThrow(UniqueConstraintViolationException::class);

    WavePersona::factory()->create(['wave_id' => $wave->id, 'market_id' => WaveFactory::marketId($wave, 'RO'), 'slot' => 3, 'code' => 'RO3']);
    WavePersona::factory()->create(['slot' => 3, 'code' => 'SK3']);
    expect(WavePersona::query()->count())->toBe(3);
});

it('L4: unique (wave_id, market, code) refuses a duplicate code in a market, the same code in another market is free', function (): void {
    $wave = Wave::factory()->withMarkets(WaveFactory::singleCountryMarkets(['SK', 'RO']))->create();
    WavePersona::factory()->create(['wave_id' => $wave->id, 'market_id' => WaveFactory::marketId($wave, 'SK'), 'slot' => 1, 'code' => 'B2B']);

    expect(strategySavepoint(fn () => WavePersona::factory()->create(['wave_id' => $wave->id, 'market_id' => WaveFactory::marketId($wave, 'SK'), 'slot' => 2, 'code' => 'B2B'])))
        ->toThrow(UniqueConstraintViolationException::class);

    WavePersona::factory()->create(['wave_id' => $wave->id, 'market_id' => WaveFactory::marketId($wave, 'RO'), 'slot' => 1, 'code' => 'B2B']);
    expect(WavePersona::query()->count())->toBe(2);
});

it('T3: the composite foreign keys refuse an angle whose USP or persona is in another wave (DB level)', function (): void {
    $waveA = Wave::factory()->create();
    $persona = WavePersona::factory()->create(['wave_id' => $waveA->id, 'agency_id' => $waveA->agency_id]);
    $uspOfB = WaveUsp::factory()->create();   // its own wave
    $uspOfA = WaveUsp::factory()->create(['wave_id' => $waveA->id, 'agency_id' => $waveA->agency_id]);

    $row = fn (int $uspId, int $personaId, int $waveId): array => [
        'ulid' => (string) Str::ulid(), 'agency_id' => $waveA->agency_id, 'wave_id' => $waveId,
        'persona_id' => $personaId, 'usp_id' => $uspId, 'explanation' => 'x', 'origin' => 'manual',
        'created_at' => now(), 'updated_at' => now(),
    ];

    expect(strategySavepoint(fn () => DB::table('wave_persona_angles')->insert($row($uspOfB->id, $persona->id, $waveA->id))))
        ->toThrow(QueryException::class);
    $personaOfB = WavePersona::factory()->create();
    expect(strategySavepoint(fn () => DB::table('wave_persona_angles')->insert($row($uspOfA->id, $personaOfB->id, $waveA->id))))
        ->toThrow(QueryException::class);

    // The honest row is accepted.
    DB::table('wave_persona_angles')->insert($row($uspOfA->id, $persona->id, $waveA->id));
    expect(WavePersonaAngle::query()->count())->toBe(1);
});

it('C5: RESTRICT: a USP an angle uses, a persona with angles and a wave with strategy rows cannot be hard-deleted', function (): void {
    $angle = WavePersonaAngle::factory()->create();

    expect(strategySavepoint(fn () => DB::table('wave_usps')->where('id', $angle->usp_id)->delete()))->toThrow(QueryException::class);
    expect(strategySavepoint(fn () => DB::table('wave_personas')->where('id', $angle->persona_id)->delete()))->toThrow(QueryException::class);
    expect(strategySavepoint(fn () => DB::table('waves')->where('id', $angle->wave_id)->delete()))->toThrow(QueryException::class);
    expect(WavePersonaAngle::query()->count())->toBe(1);
});

it('P1: unique (persona_id, usp_id) refuses a second angle of a persona on one USP', function (): void {
    $angle = WavePersonaAngle::factory()->create();

    expect(strategySavepoint(fn () => WavePersonaAngle::factory()->create(['persona_id' => $angle->persona_id, 'usp_id' => $angle->usp_id])))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('P1: a new persona defaults to pronoun they and no text; colours wrap the six for slots 7 and 8', function (): void {
    $fresh = WavePersona::query()->findOrFail(WavePersona::factory()->create()->id);

    expect($fresh->pronoun->value)->toBe('they')
        ->and($fresh->drivers)->toBeNull()
        ->and($fresh->ulid)->toHaveLength(26)
        ->and(UspColor::forSlot(1))->toBe(UspColor::Violet)
        ->and(UspColor::forSlot(6))->toBe(UspColor::Pink)
        ->and(UspColor::forSlot(7))->toBe(UspColor::Violet)
        ->and(UspColor::forSlot(8))->toBe(UspColor::Green);
});

it('L9: PL-3.2a: the live-number rule is a partial unique index on the scripts, and the old full unique key is gone', function (): void {
    $indexes = collect(Schema::getIndexes('wave_scripts'))->keyBy('name');

    expect($indexes->has('unique_wave_scripts_live_wave_creator_number'))->toBeTrue()
        ->and($indexes->get('unique_wave_scripts_live_wave_creator_number')['unique'])->toBeTrue()
        ->and($indexes->get('unique_wave_scripts_live_wave_creator_number')['columns'])->toBe(['wave_id', 'creator_id', 'number'])
        ->and($indexes->has('unique_wave_scripts_wave_creator_number'))->toBeFalse();

    $sql = DB::getDriverName() === 'pgsql'
        ? (string) DB::table('pg_indexes')->where('indexname', 'unique_wave_scripts_live_wave_creator_number')->value('indexdef')
        : (string) DB::table('sqlite_master')->where('name', 'unique_wave_scripts_live_wave_creator_number')->value('sql');
    expect(strtolower($sql))->toContain('where')->and(strtolower($sql))->toContain('archived_at is null');
});
