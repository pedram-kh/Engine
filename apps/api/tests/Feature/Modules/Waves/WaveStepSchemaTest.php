<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Waves\Enums\WaveStepStatus;
use App\Modules\Waves\Enums\WaveStream;
use App\Modules\Waves\Models\Wave;
use App\Modules\Waves\Models\WaveStep;
use App\Modules\Waves\Steps\WaveStepDefaults;
use App\Modules\Waves\Steps\WaveStepKey;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * On PostgreSQL a failed statement aborts the surrounding transaction (RefreshDatabase wraps each test in one), so a
 * statement expected to fail runs inside a nested transaction: a savepoint, rolled back on the exception.
 */
/**
 * @param  Closure(): mixed  $fn
 */
function inSavepoint(Closure $fn): Closure
{
    return fn (): mixed => DB::transaction($fn);
}

/*
 * PL-1.3 — M1 (schema, both migrations, up and down) and K1 (the default list is pinned).
 */

it('M1: creates wave_steps with the planned columns and the nullable marker on waves', function (): void {
    expect(Schema::hasColumns('wave_steps', [
        'id', 'ulid', 'agency_id', 'wave_id', 'stream', 'step_key', 'name', 'position', 'status', 'owner_user_id',
        'owner_client', 'status_source', 'starts_on', 'due_on', 'completed_on', 'created_at', 'updated_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumn('waves', 'steps_provisioned_at'))->toBeTrue();

    // Existing rows keep a null marker: the column has no default.
    $wave = Wave::factory()->create();
    expect(Wave::query()->findOrFail($wave->id)->steps_provisioned_at)->toBeNull();
});

it('M1: both migrations run down and up again', function (): void {
    foreach (['2026_11_19_100001_add_steps_provisioned_at_to_waves', '2026_11_19_100000_create_wave_steps_table'] as $name) {
        $migration = require database_path("migrations/{$name}.php");
        $migration->down();
    }
    expect(Schema::hasTable('wave_steps'))->toBeFalse()
        ->and(Schema::hasColumn('waves', 'steps_provisioned_at'))->toBeFalse();

    foreach (['2026_11_19_100000_create_wave_steps_table', '2026_11_19_100001_add_steps_provisioned_at_to_waves'] as $name) {
        $migration = require database_path("migrations/{$name}.php");
        $migration->up();
    }
    expect(Schema::hasTable('wave_steps'))->toBeTrue()
        ->and(Schema::hasColumn('waves', 'steps_provisioned_at'))->toBeTrue();
});

it('M1: unique (wave_id, step_key) refuses a duplicate key, and nulls never collide', function (): void {
    $wave = Wave::factory()->create();
    WaveStep::factory()->create(['wave_id' => $wave->id, 'step_key' => 'ugc.usps']);
    WaveStep::factory()->count(3)->create(['wave_id' => $wave->id, 'step_key' => null]);

    expect(inSavepoint(fn () => WaveStep::factory()->create(['wave_id' => $wave->id, 'step_key' => 'ugc.usps'])))
        ->toThrow(UniqueConstraintViolationException::class);
    expect(WaveStep::query()->where('wave_id', $wave->id)->count())->toBe(4);
});

it('M1: unique (wave_id, stream, position) refuses a duplicate position, another stream is free', function (): void {
    $wave = Wave::factory()->create();
    WaveStep::factory()->create(['wave_id' => $wave->id, 'stream' => WaveStream::Statics, 'position' => 10]);
    WaveStep::factory()->create(['wave_id' => $wave->id, 'stream' => WaveStream::Stills, 'position' => 10]);

    expect(inSavepoint(fn () => WaveStep::factory()->create(['wave_id' => $wave->id, 'stream' => WaveStream::Statics, 'position' => 10])))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('M1: the foreign keys are restrictive: a wave with steps cannot be hard-deleted; a deleted owner is nulled', function (): void {
    $step = WaveStep::factory()->create();

    expect(inSavepoint(fn () => DB::table('waves')->where('id', $step->wave_id)->delete()))->toThrow(QueryException::class);

    $owner = User::factory()->create();
    $step->forceFill(['owner_user_id' => $owner->id])->save();
    DB::table('users')->where('id', $owner->id)->delete();
    expect(WaveStep::query()->findOrFail($step->id)->owner_user_id)->toBeNull();
});

it('M1: a new step defaults to not_started, system, no owner and no dates', function (): void {
    $fresh = WaveStep::query()->findOrFail(WaveStep::factory()->create()->id);

    expect($fresh->status)->toBe(WaveStepStatus::NotStarted)
        ->and($fresh->status_source->value)->toBe('system')
        ->and($fresh->owner_client)->toBeFalse()
        ->and($fresh->due_on)->toBeNull()
        ->and($fresh->ulid)->toHaveLength(26);
});

it('K1: the default list is exactly the table of the plan (24 steps, keys, names, owners, positions)', function (): void {
    $expected = [
        'ugc_video' => [
            ['ugc.kick_off_call', 'Kick-off call', false], ['ugc.usps', 'USPs', false], ['ugc.personas', 'Personas', false],
            ['ugc.matrix', 'Messaging matrix', false], ['ugc.concepts', 'Concepts', false], ['ugc.creators', 'Creators', false],
            ['ugc.scripts', 'Scripts', false], ['ugc.footage_received', 'Footage received', false], ['ugc.editing', 'Editing', false],
            ['ugc.internal_review', 'Internal review', false], ['ugc.editing_feedback_1', '1st editing feedback', false],
            ['ugc.editing_feedback_2', '2nd editing feedback', false], ['ugc.client_approval', 'Client approval', true],
        ],
        'statics' => [
            ['statics.brief_references', 'Static brief & references', false], ['statics.design_v1', 'Design v1', false],
            ['statics.internal_review', 'Internal review', false], ['statics.client_feedback', 'Client feedback round', true],
            ['statics.final_export', 'Final export & resizes', false],
        ],
        'stills' => [
            ['stills.shot_list', 'Shot list', false], ['stills.shoot_prep', 'Shoot prep', false], ['stills.shoot_day', 'Shoot day', false],
            ['stills.retouching_selects', 'Retouching & selects', false], ['stills.internal_review', 'Internal review', false],
            ['stills.client_approval', 'Client approval', true],
        ],
    ];

    $actual = [];
    foreach (WaveStepDefaults::steps() as $s) {
        $actual[$s['stream']->value][] = [$s['key'], $s['name'], $s['owner_client']];
    }
    expect($actual)->toBe($expected)->and(count(WaveStepDefaults::steps()))->toBe(24);

    foreach (WaveStepDefaults::steps() as $s) {
        $index = array_search($s['key'], array_column(array_filter(WaveStepDefaults::steps(), fn ($x) => $x['stream'] === $s['stream']), 'key'), true);
        expect($s['position'])->toBe(($index + 1) * 10);
    }

    $keys = array_column(WaveStepDefaults::steps(), 'key');
    expect(array_unique($keys))->toBe($keys)
        ->and(WaveStepKey::STRATEGY)->toBe(['ugc.usps', 'ugc.personas', 'ugc.matrix', 'ugc.concepts', 'ugc.creators', 'ugc.scripts'])
        ->and(array_diff(WaveStepKey::STRATEGY, $keys))->toBe([])
        ->and(array_column(array_filter(WaveStepDefaults::steps(), fn ($s) => $s['owner_client']), 'key'))
        ->toBe(['ugc.client_approval', 'statics.client_feedback', 'stills.client_approval']);
});
