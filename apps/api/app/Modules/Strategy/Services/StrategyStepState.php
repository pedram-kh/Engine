<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Services;

use App\Modules\Strategy\Enums\ConceptStatus;
use App\Modules\Strategy\Models\WaveConcept;
use App\Modules\Strategy\Models\WaveMatrixCell;
use App\Modules\Strategy\Models\WavePersona;
use App\Modules\Strategy\Models\WaveStrategyCompletion;
use App\Modules\Strategy\Models\WaveUsp;
use App\Modules\Strategy\StrategyLimits;
use App\Modules\Waves\Models\Wave;
use App\Modules\Waves\Models\WaveMarket;
use App\Modules\Waves\Steps\WaveStepKey;

/**
 * The real state of a strategy step, in ONE place (PL-2.5 plan section 1.3). Pure reads: nothing here writes.
 *
 * State order: `not_started` < `in_progress` < `done`.
 *
 *   USPs      (wave)       none: not started; some and not marked: in progress; marked: done
 *   Personas  (per market) none in the market: not started; some, not marked: in progress; marked: done
 *   Matrix    (per market) no cell set: not started; some, not marked: in progress; marked: done
 *   Concepts  (per market) none: not started; some and not all approved: in progress; all approved: done (automatic)
 *
 * For the wave a per-market step is `done` when it is done in EVERY market, `not_started` when it is not started in
 * every market, else `in_progress` (with `markets_done` of `markets_total`).
 */
final class StrategyStepState
{
    public const USPS = 'usps';

    public const PERSONAS = 'personas';

    public const MATRIX = 'matrix';

    public const CONCEPTS = 'concepts';

    /** @var list<string> */
    public const STEPS = [self::USPS, self::PERSONAS, self::MATRIX, self::CONCEPTS];

    /** @var list<string> the steps that carry a stored "Mark as complete" */
    public const MARKABLE = [self::USPS, self::PERSONAS, self::MATRIX];

    public const NOT_STARTED = 'not_started';

    public const IN_PROGRESS = 'in_progress';

    public const DONE = 'done';

    /** @var list<string> */
    public const ORDER = [self::NOT_STARTED, self::IN_PROGRESS, self::DONE];

    /** The tracker row key of a step. */
    public static function rowKey(string $step): string
    {
        return match ($step) {
            self::USPS => WaveStepKey::UGC_USPS,
            self::PERSONAS => WaveStepKey::UGC_PERSONAS,
            self::MATRIX => WaveStepKey::UGC_MATRIX,
            self::CONCEPTS => WaveStepKey::UGC_CONCEPTS,
            default => throw new \InvalidArgumentException('Unknown strategy step.'),
        };
    }

    /** The step of a tracker row key, or null for a row with no real step (creators, scripts, everything else). */
    public static function stepOfRow(?string $key): ?string
    {
        return match ($key) {
            WaveStepKey::UGC_USPS => self::USPS,
            WaveStepKey::UGC_PERSONAS => self::PERSONAS,
            WaveStepKey::UGC_MATRIX => self::MATRIX,
            WaveStepKey::UGC_CONCEPTS => self::CONCEPTS,
            default => null,
        };
    }

    /** Rank of a state in the order (higher is further along). */
    public static function rank(string $state): int
    {
        $i = array_search($state, self::ORDER, true);

        return $i === false ? 0 : (int) $i;
    }

    /**
     * Read the real state of the given steps for one wave.
     *
     * @param  list<string>  $steps
     * @return array<string, array{state: string, count: int, marked: bool, markets: list<array{market: string, id: int, code: string, state: string, count: int, marked: bool}>, markets_done: int, markets_total: int}>
     */
    public static function read(Wave $wave, array $steps = self::STEPS): array
    {
        $markets = WaveMarket::query()->where('wave_id', $wave->id)->orderBy('position')->orderBy('id')->get(['id', 'ulid', 'code']);

        /** @var array<string, array<int|string, bool>> $marks step => market id (or 0) => true */
        $marks = [];
        if (array_intersect($steps, self::MARKABLE) !== []) {
            foreach (WaveStrategyCompletion::query()->where('wave_id', $wave->id)->get(['step', 'market_id']) as $c) {
                $marks[$c->step][(int) $c->market_id] = true;
            }
        }

        $out = [];
        foreach ($steps as $step) {
            if ($step === self::USPS) {
                $count = WaveUsp::query()->where('wave_id', $wave->id)->count();
                $marked = isset($marks[self::USPS][0]);
                $out[$step] = [
                    'state' => self::wave($count, $marked),
                    'count' => $count,
                    'marked' => $marked,
                    'markets' => [],
                    'markets_done' => 0,
                    'markets_total' => 0,
                ];

                continue;
            }

            $counts = self::countsByMarket($wave, $step);
            $rows = [];
            $done = 0;
            $total = 0;
            $any = 0;
            foreach ($markets as $market) {
                $c = $counts[$market->id] ?? ['count' => 0, 'approved' => 0];
                $count = $c['count'];
                if ($step === self::CONCEPTS) {
                    $marked = $count > 0 && $c['approved'] === $count;
                    $state = $count === 0 ? self::NOT_STARTED : ($marked ? self::DONE : self::IN_PROGRESS);
                } else {
                    $marked = isset($marks[$step][$market->id]);
                    $state = self::wave($count, $marked);
                }
                $rows[] = ['market' => $market->ulid, 'id' => (int) $market->id, 'code' => (string) $market->code, 'state' => $state, 'count' => $count, 'marked' => $marked];
                $total++;
                $done += $state === self::DONE ? 1 : 0;
                $any += $state === self::NOT_STARTED ? 0 : 1;
            }
            $waveState = match (true) {
                $total === 0 => self::NOT_STARTED,
                $done === $total => self::DONE,
                $any === 0 => self::NOT_STARTED,
                default => self::IN_PROGRESS,
            };
            $out[$step] = [
                'state' => $waveState,
                'count' => array_sum(array_column($rows, 'count')),
                'marked' => $total > 0 && $done === $total,
                'markets' => $rows,
                'markets_done' => $done,
                'markets_total' => $total,
            ];
        }

        return $out;
    }

    /**
     * The warnings of a step now (live, never stored). A warning never blocks anything.
     *
     * @return list<array{code: string, count?: int, recommended?: int, set?: int, total?: int}>
     */
    public static function warnings(Wave $wave, string $step, ?int $marketId): array
    {
        if ($step === self::USPS) {
            $n = WaveUsp::query()->where('wave_id', $wave->id)->count();

            return $n > 0 && $n < StrategyLimits::USP_WARN_BELOW
                ? [['code' => 'usps_under_recommended', 'count' => $n, 'recommended' => StrategyLimits::USP_WARN_BELOW]]
                : [];
        }
        if ($marketId === null) {
            return [];
        }
        $personas = WavePersona::query()->where('wave_id', $wave->id)->where('market_id', $marketId)->count();
        if ($step === self::PERSONAS) {
            return $personas > 0 && $personas < StrategyLimits::PERSONA_MIN
                ? [['code' => 'personas_under_minimum', 'count' => $personas, 'recommended' => StrategyLimits::PERSONA_MIN]]
                : [];
        }
        if ($step === self::MATRIX) {
            $usps = WaveUsp::query()->where('wave_id', $wave->id)->count();
            $total = $personas * $usps;
            $set = WaveMatrixCell::query()->where('wave_id', $wave->id)->where('market_id', $marketId)->count();

            return $set > 0 && $set < $total ? [['code' => 'cells_not_set', 'set' => $set, 'total' => $total]] : [];
        }

        return [];
    }

    /**
     * Where "Strategy & Scripts" opens (K3, Q3): the first step not done, and for a per-market step the first market not
     * done; all four done opens Concepts in its first market. Computed from the REAL states, never the stored rows.
     *
     * @param  array<string, array{state: string, markets: list<array{code: string, state: string}>}>  $read  all four steps
     * @return array{step: string, market: string|null}
     */
    public static function landing(array $read): array
    {
        foreach (self::STEPS as $step) {
            $r = $read[$step] ?? null;
            if ($r === null || $r['state'] === self::DONE) {
                continue;
            }
            if ($step === self::USPS) {
                return ['step' => $step, 'market' => null];
            }
            foreach ($r['markets'] as $m) {
                if ($m['state'] !== self::DONE) {
                    return ['step' => $step, 'market' => $m['code']];
                }
            }

            return ['step' => $step, 'market' => $r['markets'][0]['code'] ?? null];
        }

        $first = ($read[self::CONCEPTS]['markets'][0]['code'] ?? null);

        return ['step' => self::CONCEPTS, 'market' => $first];
    }

    private static function wave(int $count, bool $marked): string
    {
        if ($count === 0) {
            return self::NOT_STARTED;
        }

        return $marked ? self::DONE : self::IN_PROGRESS;
    }

    /**
     * @return array<int, array{count: int, approved: int}> market id => counts
     */
    private static function countsByMarket(Wave $wave, string $step): array
    {
        $out = [];
        if ($step === self::PERSONAS) {
            foreach (WavePersona::query()->where('wave_id', $wave->id)->selectRaw('market_id, count(*) as n')->groupBy('market_id')->get() as $row) {
                $out[(int) $row->getAttribute('market_id')] = ['count' => (int) $row->getAttribute('n'), 'approved' => 0];
            }
        } elseif ($step === self::MATRIX) {
            foreach (WaveMatrixCell::query()->where('wave_id', $wave->id)->selectRaw('market_id, count(*) as n')->groupBy('market_id')->get() as $row) {
                $out[(int) $row->getAttribute('market_id')] = ['count' => (int) $row->getAttribute('n'), 'approved' => 0];
            }
        } elseif ($step === self::CONCEPTS) {
            foreach (WaveConcept::query()->where('wave_id', $wave->id)->selectRaw('market_id, status, count(*) as n')->groupBy('market_id', 'status')->get() as $row) {
                $id = (int) $row->getAttribute('market_id');
                $n = (int) $row->getAttribute('n');
                $out[$id] ??= ['count' => 0, 'approved' => 0];
                $out[$id]['count'] += $n;
                if ((string) $row->getRawOriginal('status') === ConceptStatus::Approved->value) {
                    $out[$id]['approved'] += $n;
                }
            }
        }

        return $out;
    }
}
