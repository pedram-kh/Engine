<?php

declare(strict_types=1);

use App\Modules\Waves\Enums\WaveStepStatus;
use App\Modules\Waves\Models\Wave;
use App\Modules\Waves\Models\WaveStep;
use App\Modules\Waves\Services\WaveStepProvisioner;
use App\Modules\Waves\Steps\WaveStepKey;
use App\Modules\Waves\Steps\WaveTrackerCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\WaveFixtures;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * PL-1.3 — D1 (delayed is derived), PR1 (the stream card figure) and BAR1 (the Brand page bar rule).
 */

function provisionedWave(?Wave $wave = null): Wave
{
    $wave ??= Wave::factory()->create();
    app(WaveStepProvisioner::class)->ensure($wave);

    return $wave;
}

function stepOf(Wave $wave, string $key): WaveStep
{
    return WaveStep::query()->where('wave_id', $wave->id)->where('step_key', $key)->firstOrFail();
}

function setStatus(Wave $wave, string $key, string $status, ?string $name = null): void
{
    $step = stepOf($wave, $key);
    $step->status = WaveStepStatus::from($status);
    if ($name !== null) {
        $step->name = $name;
    }
    $step->save();
}

function steps(Wave $wave)
{
    return WaveStep::query()->where('wave_id', $wave->id)->get();
}

const TODAY = '2026-10-15';

it('D1: a done step is never delayed; an open step due yesterday is delayed with the right days; due today is due_today', function (): void {
    $wave = provisionedWave();
    $past = stepOf($wave, WaveStepKey::STATICS_DESIGN_V1);
    $past->update(['due_on' => '2026-10-13']);

    expect(WaveTrackerCalculator::displayStatus($past->refresh(), TODAY))->toBe('delayed')
        ->and(WaveTrackerCalculator::daysOverdue($past, TODAY))->toBe(2);

    $past->status = WaveStepStatus::Done;
    $past->save();
    expect(WaveTrackerCalculator::displayStatus($past->refresh(), TODAY))->toBe('done')
        ->and(WaveTrackerCalculator::daysOverdue($past, TODAY))->toBe(0);

    $today = stepOf($wave, WaveStepKey::STATICS_FINAL_EXPORT);
    $today->update(['due_on' => TODAY, 'status' => WaveStepStatus::InProgress]);
    // In progress AND due today shows "due today" (precedence).
    expect(WaveTrackerCalculator::displayStatus($today->refresh(), TODAY))->toBe('due_today')
        ->and(WaveTrackerCalculator::daysOverdue($today, TODAY))->toBe(0);

    $started = stepOf($wave, WaveStepKey::STILLS_SHOT_LIST);
    $started->status = WaveStepStatus::InProgress;
    $started->update(['due_on' => '2026-10-20']);
    expect(WaveTrackerCalculator::displayStatus($started->refresh(), TODAY))->toBe('in_progress')
        ->and(WaveTrackerCalculator::displayStatus(stepOf($wave, WaveStepKey::STILLS_SHOOT_DAY), TODAY))->toBe('up_next');
});

it('D1: "today" is the requesting user\'s day, and an unknown zone falls back to UTC', function (): void {
    Carbon::setTestNow('2026-11-18 23:30:00 UTC');

    expect(WaveTrackerCalculator::todayFor('UTC'))->toBe('2026-11-18')
        ->and(WaveTrackerCalculator::todayFor('Pacific/Auckland'))->toBe('2026-11-19')
        ->and(WaveTrackerCalculator::todayFor('America/Los_Angeles'))->toBe('2026-11-18')
        ->and(WaveTrackerCalculator::todayFor('Not/AZone'))->toBe('2026-11-18')
        ->and(WaveTrackerCalculator::todayFor(null))->toBe('2026-11-18');

    Carbon::setTestNow();
});

it('D1: the stream shows "N days" as the maximum, complete when all done, on track otherwise, and absent streams are not listed', function (): void {
    $wave = provisionedWave();
    $a = stepOf($wave, WaveStepKey::STATICS_DESIGN_V1);
    $a->update(['due_on' => '2026-10-13']);
    $b = stepOf($wave, WaveStepKey::STATICS_BRIEF_REFERENCES);
    $b->update(['due_on' => '2026-10-10']);
    WaveStep::query()->where('wave_id', $wave->id)->where('stream', 'stills')->update(['status' => 'done']);

    $streams = collect(WaveTrackerCalculator::streams(steps($wave), TODAY))->keyBy('stream');

    expect(data_get($streams, 'statics.status'))->toBe('delayed')
        ->and(data_get($streams, 'statics.days_delayed'))->toBe(5)
        ->and(data_get($streams, 'stills.status'))->toBe('complete')
        ->and(data_get($streams, 'ugc_video.status'))->toBe('on_track')
        ->and(WaveTrackerCalculator::health(array_values($streams->all())))->toBe(['status' => 'at_risk', 'delayed_streams' => 1]);

    WaveStep::query()->where('wave_id', $wave->id)->where('stream', 'statics')->delete();
    $without = WaveTrackerCalculator::streams(steps($wave), TODAY);
    expect(array_column($without, 'stream'))->toBe(['ugc_video', 'stills']);
});

it('D1: the health is on track with no delayed stream, and counts two delayed streams', function (): void {
    $wave = provisionedWave();
    expect(WaveTrackerCalculator::health(WaveTrackerCalculator::streams(steps($wave), TODAY)))->toBe(['status' => 'on_track', 'delayed_streams' => 0]);

    foreach ([WaveStepKey::UGC_EDITING, WaveStepKey::STATICS_DESIGN_V1] as $key) {
        $s = stepOf($wave, $key);
        $s->update(['due_on' => '2026-10-01']);
    }
    expect(WaveTrackerCalculator::health(WaveTrackerCalculator::streams(steps($wave), TODAY)))->toBe(['status' => 'at_risk', 'delayed_streams' => 2]);
});

it('PR1: the stream card figure is one segment per step; done fills, adding or removing a step changes the count only', function (): void {
    $wave = provisionedWave();
    foreach ([WaveStepKey::UGC_KICK_OFF_CALL, WaveStepKey::UGC_USPS, WaveStepKey::UGC_PERSONAS] as $key) {
        setStatus($wave, $key, 'done');
    }

    $ugc = fn () => collect(WaveTrackerCalculator::streams(steps($wave), TODAY))->keyBy('stream')['ugc_video'];
    expect(data_get($ugc(), 'total'))->toBe(13)->and(data_get($ugc(), 'done'))->toBe(3)
        ->and(collect(WaveTrackerCalculator::streams(steps($wave), TODAY))->pluck('total', 'stream')->all())->toBe(['ugc_video' => 13, 'statics' => 5, 'stills' => 6]);

    WaveStep::factory()->create(['wave_id' => $wave->id, 'stream' => 'ugc_video', 'position' => 500]);
    expect(data_get($ugc(), 'total'))->toBe(14)->and(data_get($ugc(), 'done'))->toBe(3);

    stepOf($wave, WaveStepKey::UGC_EDITING)->delete();
    expect(data_get($ugc(), 'total'))->toBe(13)->and(data_get($ugc(), 'done'))->toBe(3);
});

it('PR1: next step is the first open step and final delivery is the last step\'s due date', function (): void {
    $wave = provisionedWave();
    setStatus($wave, WaveStepKey::STATICS_BRIEF_REFERENCES, 'done');
    $last = stepOf($wave, WaveStepKey::STATICS_FINAL_EXPORT);
    $last->update(['due_on' => '2026-10-22']);

    $statics = collect(WaveTrackerCalculator::streams(steps($wave), TODAY))->keyBy('stream')['statics'];
    expect(data_get($statics, 'next_step.name'))->toBe('Design v1')->and(data_get($statics, 'final_delivery'))->toBe('2026-10-22');
});

it('BAR1: segment n is done when the nth strategy step is done, also out of order; the label is the first not-done step', function (): void {
    $wave = provisionedWave();

    expect(WaveTrackerCalculator::progress(steps($wave)))->toBe([
        'segments' => ['empty', 'empty', 'empty', 'empty', 'empty', 'empty'], 'label_kind' => 'not_started', 'step_name' => null,
    ]);

    setStatus($wave, WaveStepKey::UGC_USPS, 'done');
    setStatus($wave, WaveStepKey::UGC_PERSONAS, 'in_progress');
    expect(WaveTrackerCalculator::progress(steps($wave)))->toBe([
        'segments' => ['done', 'in_progress', 'empty', 'empty', 'empty', 'empty'], 'label_kind' => 'step', 'step_name' => 'Personas',
    ]);

    // Out of order: Concepts done, Personas still not done: segment 4 filled, the label stays Personas.
    setStatus($wave, WaveStepKey::UGC_CONCEPTS, 'done');
    expect(WaveTrackerCalculator::progress(steps($wave)))->toBe([
        'segments' => ['done', 'in_progress', 'empty', 'done', 'empty', 'empty'], 'label_kind' => 'step', 'step_name' => 'Personas',
    ]);

    foreach (WaveStepKey::STRATEGY as $key) {
        setStatus($wave, $key, 'done');
    }
    expect(WaveTrackerCalculator::progress(steps($wave)))->toBe([
        'segments' => ['done', 'done', 'done', 'done', 'done', 'done'], 'label_kind' => 'complete', 'step_name' => null,
    ]);
});

it('BAR1: a rename changes the label; the other 18 steps and added or removed steps change nothing', function (): void {
    $wave = provisionedWave();
    setStatus($wave, WaveStepKey::UGC_USPS, 'done', 'Value props');
    setStatus($wave, WaveStepKey::UGC_PERSONAS, 'in_progress', 'Audiences');
    $before = WaveTrackerCalculator::progress(steps($wave));
    expect($before['step_name'])->toBe('Audiences');

    WaveStep::query()->where('wave_id', $wave->id)->whereNotIn('step_key', WaveStepKey::STRATEGY)->update(['status' => 'done']);
    WaveStep::factory()->create(['wave_id' => $wave->id, 'stream' => 'ugc_video', 'position' => 900, 'status' => 'done']);
    stepOf($wave, WaveStepKey::UGC_EDITING)->delete();

    expect(WaveTrackerCalculator::progress(steps($wave)))->toBe($before);

    // A wave where nothing in the strategy has started is "not started" even if the other steps are done.
    $other = provisionedWave();
    WaveStep::query()->where('wave_id', $other->id)->whereNotIn('step_key', WaveStepKey::STRATEGY)->update(['status' => 'done']);
    expect(WaveTrackerCalculator::progress(steps($other))['label_kind'])->toBe('not_started');
});

it('BAR1: the wave list and show carry the progress object; an unprovisioned wave reads not started and the list never writes', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();
    $ready = Wave::factory()->forBrand($w['agency']->id, $w['brand']->id)->create();
    provisionedWave($ready);
    setStatus($ready, WaveStepKey::UGC_USPS, 'done');
    $bare = Wave::factory()->forBrand($w['agency']->id, $w['brand']->id)->create();

    $list = $this->actingAs($w['admin'], 'web')->getJson(WaveFixtures::wavesUrl($w['agency'], $w['brand']))->assertOk();
    /** @var list<array<string, mixed>> $rows */
    $rows = $list->json('data');
    $byId = collect($rows)->keyBy('id');

    expect(data_get($byId, $ready->ulid.'.attributes.progress'))->toBe([
        'segments' => ['done', 'empty', 'empty', 'empty', 'empty', 'empty'], 'label_kind' => 'step', 'step_name' => 'Personas',
    ])
        ->and(data_get($byId, $bare->ulid.'.attributes.progress'))->toBe([
            'segments' => ['empty', 'empty', 'empty', 'empty', 'empty', 'empty'], 'label_kind' => 'not_started', 'step_name' => null,
        ])
        ->and(WaveStep::query()->where('wave_id', $bare->id)->count())->toBe(0)
        ->and(Wave::query()->findOrFail($bare->id)->steps_provisioned_at)->toBeNull();

    $this->actingAs($w['admin'], 'web')->getJson(WaveFixtures::wavesUrl($w['agency'], $w['brand'], $ready->ulid))
        ->assertOk()->assertJsonPath('data.attributes.progress.label_kind', 'step');
});

it('BAR1: the list computes the progress of a whole page with the same number of step queries however many waves', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();
    $count = function () use ($w): int {
        $n = 0;
        DB::listen(function ($q) use (&$n): void {
            if (str_contains($q->sql, 'wave_steps')) {
                $n++;
            }
        });
        $this->actingAs($w['admin'], 'web')->getJson(WaveFixtures::wavesUrl($w['agency'], $w['brand']))->assertOk();

        return $n;
    };

    Wave::factory()->count(2)->forBrand($w['agency']->id, $w['brand']->id)->create();
    $two = $count();
    Wave::factory()->count(6)->forBrand($w['agency']->id, $w['brand']->id)->create();

    expect($two)->toBe(1)->and($count())->toBe(1);
});
