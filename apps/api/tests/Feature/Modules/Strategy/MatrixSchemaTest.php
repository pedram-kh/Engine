<?php

declare(strict_types=1);

use App\Modules\Strategy\Enums\MatrixLevel;
use App\Modules\Strategy\Models\WaveMatrixCell;
use App\Modules\Strategy\Models\WavePersona;
use App\Modules\Strategy\Models\WaveUsp;
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
 * PL-2.2 — the cells table and its database guards (plan section 1.1): one cell per persona and USP, the composite
 * foreign keys (same wave; the persona's market), RESTRICT, the CHECK on level (PostgreSQL), no user column. A statement
 * expected to fail runs inside a nested transaction (a savepoint). These run on SQLite and PostgreSQL.
 */

/** @return array<string, mixed> */
function cellRow(int $agencyId, int $waveId, string $market, int $personaId, int $uspId, string $level = 'priority'): array
{
    return [
        'ulid' => (string) Str::ulid(), 'agency_id' => $agencyId, 'wave_id' => $waveId, 'market' => $market,
        'persona_id' => $personaId, 'usp_id' => $uspId, 'level' => $level, 'source' => 'manual',
        'created_at' => now(), 'updated_at' => now(),
    ];
}

it('MS1: the cells table has the planned columns, no user column and the market key on personas', function (): void {
    expect(Schema::hasColumns('wave_matrix_cells', [
        'id', 'ulid', 'agency_id', 'wave_id', 'market', 'persona_id', 'usp_id', 'level', 'rationale', 'message_line', 'source', 'edited_at', 'created_at', 'updated_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumn('wave_matrix_cells', 'created_by_user_id'))->toBeFalse()
        ->and(Schema::hasColumn('wave_matrix_cells', 'updated_by_user_id'))->toBeFalse()
        ->and(Schema::hasColumn('wave_matrix_cells', 'edited_by_user_id'))->toBeFalse();

    $cell = WaveMatrixCell::query()->findOrFail(WaveMatrixCell::factory()->create()->id);
    expect($cell->source)->toBe('manual')->and($cell->edited_at)->toBeNull()->and($cell->level)->toBe(MatrixLevel::Priority);
});

it('MS2: unique (persona_id, usp_id) refuses a second cell of a persona on one USP', function (): void {
    $cell = WaveMatrixCell::factory()->create();

    expect(fn () => DB::transaction(fn () => WaveMatrixCell::factory()->create(['persona_id' => $cell->persona_id, 'usp_id' => $cell->usp_id])))
        ->toThrow(UniqueConstraintViolationException::class);
    expect(WaveMatrixCell::query()->count())->toBe(1);
});

it('MS3: the composite foreign keys refuse a USP of another wave, a persona of another wave and a market that is not the persona\'s', function (): void {
    $wave = Wave::factory()->create(['markets' => ['SK', 'RO']]);
    $persona = WavePersona::factory()->create(['wave_id' => $wave->id, 'agency_id' => $wave->agency_id, 'market' => 'SK']);
    $usp = WaveUsp::factory()->create(['wave_id' => $wave->id, 'agency_id' => $wave->agency_id]);
    $uspOfOtherWave = WaveUsp::factory()->create();
    $personaOfOtherWave = WavePersona::factory()->create();

    $insert = fn (array $row) => fn () => DB::transaction(fn () => DB::table('wave_matrix_cells')->insert($row));

    expect($insert(cellRow($wave->agency_id, $wave->id, 'SK', $persona->id, $uspOfOtherWave->id)))->toThrow(QueryException::class);
    expect($insert(cellRow($wave->agency_id, $wave->id, 'SK', $personaOfOtherWave->id, $usp->id)))->toThrow(QueryException::class);
    // The persona is in SK; a cell that says RO is refused (the market key on wave_personas).
    expect($insert(cellRow($wave->agency_id, $wave->id, 'RO', $persona->id, $usp->id)))->toThrow(QueryException::class);

    // The honest row is accepted.
    DB::table('wave_matrix_cells')->insert(cellRow($wave->agency_id, $wave->id, 'SK', $persona->id, $usp->id));
    expect(WaveMatrixCell::query()->count())->toBe(1);
});

it('MS4: RESTRICT: a persona, a USP or a wave that a cell uses cannot be hard-deleted', function (): void {
    $cell = WaveMatrixCell::factory()->create();

    foreach (['wave_personas' => $cell->persona_id, 'wave_usps' => $cell->usp_id, 'waves' => $cell->wave_id] as $table => $id) {
        expect(fn () => DB::transaction(fn () => DB::table($table)->where('id', $id)->delete()))->toThrow(QueryException::class);
    }
    expect(WaveMatrixCell::query()->count())->toBe(1);
});

it('MS5: PostgreSQL: the CHECK refuses a level that is not priority, support or low', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('The CHECK constraint is PostgreSQL only; the PHP enum is the constraint elsewhere.');
    }
    $cell = WaveMatrixCell::factory()->create();
    $row = cellRow($cell->agency_id, $cell->wave_id, $cell->market, WavePersona::factory()->create(['wave_id' => $cell->wave_id, 'agency_id' => $cell->agency_id, 'market' => $cell->market])->id, $cell->usp_id, 'urgent');

    expect(fn () => DB::transaction(fn () => DB::table('wave_matrix_cells')->insert($row)))->toThrow(QueryException::class);
});
