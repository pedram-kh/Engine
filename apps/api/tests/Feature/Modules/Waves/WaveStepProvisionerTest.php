<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Waves\Models\Wave;
use App\Modules\Waves\Models\WaveStep;
use App\Modules\Waves\Services\WaveStepProvisioner;
use App\Modules\Waves\Steps\WaveStepDefaults;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\WaveFixtures;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * PL-1.3 — P1 (provisioning runs once), P2 (at creation, in the creation transaction) and P3 (PostgreSQL: the lock
 * SQL and the unique-index loser path).
 */

it('P1: an existing wave (no marker) gets exactly the 24 default steps once, then nothing more', function (): void {
    $wave = Wave::factory()->create();
    expect($wave->steps_provisioned_at)->toBeNull();

    $first = app(WaveStepProvisioner::class)->ensure($wave);
    $second = app(WaveStepProvisioner::class)->ensure($wave);

    expect($first)->toBeTrue()->and($second)->toBeFalse();

    $steps = WaveStep::query()->where('wave_id', $wave->id)->orderBy('id')->get();
    expect($steps)->toHaveCount(24)
        ->and($steps->pluck('step_key')->all())->toBe(array_column(WaveStepDefaults::steps(), 'key'))
        ->and($steps->pluck('name')->all())->toBe(array_column(WaveStepDefaults::steps(), 'name'))
        ->and($steps->every(fn (WaveStep $s): bool => $s->status->value === 'not_started' && $s->status_source->value === 'system'))->toBeTrue()
        ->and($steps->where('owner_client', true)->pluck('step_key')->all())
        ->toBe(['ugc.client_approval', 'statics.client_feedback', 'stills.client_approval'])
        ->and($steps->every(fn (WaveStep $s): bool => $s->owner_user_id === null && $s->due_on === null && $s->agency_id === $wave->agency_id))->toBeTrue()
        ->and(Wave::query()->findOrFail($wave->id)->steps_provisioned_at)->not->toBeNull();

    expect(AuditLog::query()->where('action', AuditAction::WaveStepsProvisioned->value)->count())->toBe(1);
});

it('P1: a wave whose steps were all deleted stays empty (the marker is what makes it once)', function (): void {
    $wave = Wave::factory()->create();
    app(WaveStepProvisioner::class)->ensure($wave);
    WaveStep::query()->where('wave_id', $wave->id)->delete();

    expect(app(WaveStepProvisioner::class)->ensure($wave))->toBeFalse()
        ->and(WaveStep::query()->where('wave_id', $wave->id)->count())->toBe(0);
});

it('P2: creating a wave through the API provisions the 24 steps and the marker in the creation transaction', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();

    $res = $this->actingAs($w['admin'], 'web')->postJson(WaveFixtures::wavesUrl($w['agency'], $w['brand']), WaveFixtures::body())->assertCreated();

    $wave = Wave::query()->where('ulid', $res->json('data.id'))->firstOrFail();
    expect(WaveStep::query()->where('wave_id', $wave->id)->count())->toBe(24)
        ->and($wave->steps_provisioned_at)->not->toBeNull();
});

it('P2: the campaign-and-wave creation path provisions the steps too', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();

    $res = $this->actingAs($w['admin'], 'web')->postJson(WaveFixtures::wavesUrl($w['agency'], $w['brand']), WaveFixtures::body(['new_campaign' => ['name' => 'C']]))->assertCreated();

    $wave = Wave::query()->where('ulid', $res->json('data.id'))->firstOrFail();
    expect(WaveStep::query()->where('wave_id', $wave->id)->count())->toBe(24);
});

it('P2: a failure while provisioning rolls back the wave with it', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();
    Schema::drop('wave_steps');

    $this->withoutExceptionHandling();
    expect(fn () => $this->actingAs($w['admin'], 'web')->postJson(WaveFixtures::wavesUrl($w['agency'], $w['brand']), WaveFixtures::body()))
        ->toThrow(QueryException::class);

    expect(Wave::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('P3: provisioning locks the wave row (the for update SQL) [PostgreSQL]', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('SQLite ignores row locks; the lock SQL is asserted on PostgreSQL.');
    }
    $wave = Wave::factory()->create();

    $sql = [];
    DB::listen(function ($q) use (&$sql): void {
        $sql[] = $q->sql;
    });
    app(WaveStepProvisioner::class)->ensure($wave);

    expect(collect($sql)->contains(fn (string $s): bool => str_contains($s, 'from "waves"') && str_contains($s, 'for update')))->toBeTrue();
});

it('P3: the loser of a first-open race writes nothing and returns without error (the unique index is the belt)', function (): void {
    $wave = Wave::factory()->create();
    // The winner's rows have landed but this request still sees a null marker.
    $defaults = WaveStepDefaults::steps();
    WaveStep::factory()->create([
        'wave_id' => $wave->id, 'stream' => $defaults[1]['stream'], 'step_key' => $defaults[1]['key'], 'position' => 20,
    ]);

    $result = app(WaveStepProvisioner::class)->ensure($wave);

    expect($result)->toBeFalse()
        ->and(WaveStep::query()->where('wave_id', $wave->id)->count())->toBe(1)
        ->and(Wave::query()->findOrFail($wave->id)->steps_provisioned_at)->toBeNull()
        ->and(AuditLog::query()->where('action', AuditAction::WaveStepsProvisioned->value)->count())->toBe(0);

    // The database still works after the refusal (on PostgreSQL the savepoint was rolled back, not the whole test).
    expect(Wave::query()->count())->toBe(1);
});
