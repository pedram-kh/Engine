<?php

declare(strict_types=1);

namespace App\Modules\Waves\Steps;

use App\Modules\Waves\Enums\WaveStepStatus;
use App\Modules\Waves\Enums\WaveStream;
use App\Modules\Waves\Models\WaveStep;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Everything the tracker derives from stored steps, in one pure place (PL-1.3 plan sections 1.5 and 1.6). Nothing
 * here is stored and nothing here writes: the display status, the stream roll-up, the wave health and the
 * six-segment progress object are computed from rows and "today".
 *
 * "Today" is a date in the REQUESTING USER's time zone (ruling Q12); callers pass it as a `Y-m-d` string.
 */
final class WaveTrackerCalculator
{
    public const UP_NEXT = 'up_next';

    public const IN_PROGRESS = 'in_progress';

    public const DUE_TODAY = 'due_today';

    public const DELAYED = 'delayed';

    public const DONE = 'done';

    /** The user's date (not time) for "now", in the given IANA time zone; an unknown zone falls back to UTC. */
    public static function todayFor(?string $timezone): string
    {
        try {
            return Carbon::now($timezone ?: 'UTC')->toDateString();
        } catch (\Throwable) {
            return Carbon::now('UTC')->toDateString();
        }
    }

    /**
     * Whole days a step is overdue (0 when done, no due date, or not yet past it). Derived from the due date only
     * (ruling Q3).
     */
    public static function daysOverdue(WaveStep $step, string $today): int
    {
        if ($step->status === WaveStepStatus::Done || $step->due_on === null) {
            return 0;
        }

        $due = $step->due_on->toDateString();
        if ($due >= $today) {
            return 0;
        }

        return (int) Carbon::parse($due)->diffInDays(Carbon::parse($today));
    }

    /**
     * Display status, in this order of precedence: done; delayed; due today; in progress; up next.
     */
    public static function displayStatus(WaveStep $step, string $today): string
    {
        if ($step->status === WaveStepStatus::Done) {
            return self::DONE;
        }

        if ($step->due_on !== null) {
            $due = $step->due_on->toDateString();
            if ($due < $today) {
                return self::DELAYED;
            }
            if ($due === $today) {
                return self::DUE_TODAY;
            }
        }

        return $step->status === WaveStepStatus::InProgress ? self::IN_PROGRESS : self::UP_NEXT;
    }

    /**
     * One entry per PRESENT stream (a stream with at least one step), in display order. The figure of a card is one
     * segment per step: `done` of `total`.
     *
     * @param  Collection<int, WaveStep>  $steps  every step of ONE wave
     * @return list<array<string, mixed>>
     */
    public static function streams(Collection $steps, string $today): array
    {
        $out = [];
        foreach (WaveStream::cases() as $stream) {
            $mine = $steps->filter(fn (WaveStep $s): bool => $s->stream === $stream)
                ->sortBy([['position', 'asc'], ['id', 'asc']])->values();
            if ($mine->isEmpty()) {
                continue;
            }

            $done = $mine->filter(fn (WaveStep $s): bool => $s->status === WaveStepStatus::Done)->count();
            $open = $mine->filter(fn (WaveStep $s): bool => $s->status !== WaveStepStatus::Done)->values();
            $daysDelayed = (int) $open->map(fn (WaveStep $s): int => self::daysOverdue($s, $today))->max();

            $status = match (true) {
                $open->isEmpty() => 'complete',
                $daysDelayed > 0 => 'delayed',
                default => 'on_track',
            };

            $next = $open->first();
            $last = $mine->last();

            $out[] = [
                'stream' => $stream->value,
                'colour' => $stream->colour(),
                'status' => $status,
                'days_delayed' => $daysDelayed,
                'done' => $done,
                'total' => $mine->count(),
                'next_step' => $next === null ? null : [
                    'id' => $next->ulid,
                    'name' => $next->name,
                    'due_on' => $next->due_on?->toDateString(),
                ],
                'final_delivery' => $last->due_on?->toDateString(),
                'owner_ids' => $mine->pluck('owner_user_id')->filter()->unique()->values()->map(fn ($id): int => (int) $id)->all(),
                'has_client' => $mine->contains(fn (WaveStep $s): bool => $s->owner_client),
            ];
        }

        return $out;
    }

    /**
     * Wave health: "At risk · N streams delayed" when at least one present stream is delayed, else "On track".
     *
     * @param  list<array<string, mixed>>  $streams  the output of {@see self::streams()}
     * @return array{status: string, delayed_streams: int}
     */
    public static function health(array $streams): array
    {
        $delayed = count(array_filter($streams, static fn (array $s): bool => $s['status'] === 'delayed'));

        return ['status' => $delayed > 0 ? 'at_risk' : 'on_track', 'delayed_streams' => $delayed];
    }

    /**
     * The Brand page / wave page bar (ruling Q1, plan section 1.6): six segments, one per STRATEGY step in strategy
     * order, NOT a ratio of all steps. Segment n is `done` when the nth strategy step is done, `in_progress` when it
     * is started, otherwise `empty`. The label is the first strategy step that is not done ("step" with its CURRENT
     * name), "complete" when all six are done, "not_started" when none has started or been done (which includes a
     * wave with no steps yet). The other 18 steps never matter.
     *
     * @param  iterable<object{step_key: string|null, name: string, status: WaveStepStatus|string}>  $strategySteps  rows of ONE wave (extra rows are ignored)
     * @return array{segments: list<string>, label_kind: string, step_name: string|null}
     */
    public static function progress(iterable $strategySteps): array
    {
        $byKey = [];
        foreach ($strategySteps as $row) {
            if ($row->step_key !== null && WaveStepKey::isStrategy($row->step_key)) {
                $byKey[$row->step_key] = $row;
            }
        }

        $segments = [];
        $firstNotDone = null;
        $found = false;
        $anyStarted = false;

        foreach (WaveStepKey::STRATEGY as $key) {
            $row = $byKey[$key] ?? null;
            $status = $row === null ? null : ($row->status instanceof WaveStepStatus ? $row->status->value : (string) $row->status);

            $segment = match ($status) {
                'done' => 'done',
                'in_progress' => 'in_progress',
                default => 'empty',
            };
            $segments[] = $segment;

            if ($segment !== 'empty') {
                $anyStarted = true;
            }
            if ($segment !== 'done' && ! $found) {
                $found = true;
                $firstNotDone = $row?->name;
            }
        }

        $allDone = ! in_array('in_progress', $segments, true) && ! in_array('empty', $segments, true);

        if ($allDone) {
            return ['segments' => $segments, 'label_kind' => 'complete', 'step_name' => null];
        }
        if (! $anyStarted) {
            return ['segments' => $segments, 'label_kind' => 'not_started', 'step_name' => null];
        }

        return ['segments' => $segments, 'label_kind' => 'step', 'step_name' => $firstNotDone];
    }

    /**
     * The progress object of many waves with ONE grouped query, no N+1, no write. A wave with no steps reads as six
     * empty segments and "not started".
     *
     * @param  list<int>  $waveIds
     * @return array<int, array{segments: list<string>, label_kind: string, step_name: string|null}>
     */
    public static function progressForWaves(array $waveIds): array
    {
        $byWave = [];
        if ($waveIds !== []) {
            $rows = WaveStep::query()
                ->whereIn('wave_id', $waveIds)
                ->whereIn('step_key', WaveStepKey::STRATEGY)
                ->get(['wave_id', 'step_key', 'name', 'status']);
            foreach ($rows as $row) {
                $byWave[$row->wave_id][] = $row;
            }
        }

        $out = [];
        foreach ($waveIds as $id) {
            $out[$id] = self::progress($byWave[$id] ?? []);
        }

        return $out;
    }
}
