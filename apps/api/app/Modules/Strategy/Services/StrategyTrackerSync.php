<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Services;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Features\StrategyEnabled;
use App\Modules\Strategy\Models\WaveStrategyCompletion;
use App\Modules\Waves\Models\Wave;
use App\Modules\Waves\Services\WaveStrategyRowService;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * THE choke point (PL-2.5 plan section 1.5, addition A1). Every write that can change the state of a strategy step runs
 * through {@see self::transaction()}, which, in ONE transaction:
 *
 *   1. takes the wave row lock FIRST (the lock every strategy write and every wave edit takes);
 *   2. reads the real state of the steps the write can touch;
 *   3. runs the write;
 *   4. drops a completion mark whose step lost its last record (rule E of plan 1.4);
 *   5. reads the real state again and writes the four tracker rows by the F1 table ({@see WaveStrategyRowService}).
 *
 * A failure anywhere rolls the data and the rows back together. With strategy OFF nothing here runs except the write
 * itself, so no system write touches a tracker row. {@see self::catchUp()} is the same F1 table without a "before", run
 * when a tracker or a strategy summary is opened (it covers what no hook sees: the flag turned ON again, markets edited
 * while it was OFF).
 *
 * An architecture test (`StrategyChokePointTest`) fails when a service method that writes a step-changing model does not
 * go through this class.
 */
final class StrategyTrackerSync
{
    /**
     * Replaces `DB::transaction()` for a write that can change a step's state.
     *
     * @template T
     *
     * @param  list<string>  $steps  the steps the write can change (`StrategyStepState::USPS` and so on)
     * @param  Closure(): T  $change
     * @return T
     */
    public static function transaction(Wave $wave, ?User $actor, array $steps, Closure $change): mixed
    {
        return DB::transaction(function () use ($wave, $actor, $steps, $change): mixed {
            $locked = StrategyGuard::lockWave($wave);

            if (! StrategyEnabled::active()) {
                return $change();
            }

            $before = StrategyStepState::read($locked, $steps);
            $result = $change();
            $after = self::dropEmptied($locked, $actor, $steps);

            $transitions = [];
            foreach ($steps as $step) {
                $transitions[StrategyStepState::rowKey($step)] = ['before' => $before[$step]['state'], 'after' => $after[$step]['state']];
            }
            app(WaveStrategyRowService::class)->apply($locked, $transitions, $actor, false);

            return $result;
        });
    }

    /** The catch-up on open: all four rows, no "before". A no-op while strategy is OFF. */
    public static function catchUp(Wave $wave, ?User $actor): void
    {
        if (! StrategyEnabled::active()) {
            return;
        }

        DB::transaction(function () use ($wave, $actor): void {
            $locked = StrategyGuard::lockWave($wave);
            $read = StrategyStepState::read($locked, StrategyStepState::STEPS);

            $transitions = [];
            foreach (StrategyStepState::STEPS as $step) {
                $transitions[StrategyStepState::rowKey($step)] = ['before' => null, 'after' => $read[$step]['state']];
            }
            app(WaveStrategyRowService::class)->apply($locked, $transitions, $actor, true);
        });
    }

    /**
     * Rule E: a mark is dropped, in the same transaction, when its step has no record left (the last USP; the last persona
     * or the last set cell of a market). Returns the real state read AFTER the drop.
     *
     * @param  list<string>  $steps
     * @return array<string, array{state: string, count: int, marked: bool, markets: list<array{market: string, id: int, code: string, state: string, count: int, marked: bool}>, markets_done: int, markets_total: int}>
     */
    private static function dropEmptied(Wave $locked, ?User $actor, array $steps): array
    {
        $after = StrategyStepState::read($locked, $steps);
        $markable = array_values(array_intersect($steps, StrategyStepState::MARKABLE));
        if ($markable === []) {
            return $after;
        }

        $dropped = false;
        foreach (WaveStrategyCompletion::query()->where('wave_id', $locked->id)->whereIn('step', $markable)->orderBy('id')->get() as $mark) {
            $count = $mark->step === StrategyStepState::USPS
                ? $after[StrategyStepState::USPS]['count']
                : self::marketCount($after[$mark->step]['markets'], (int) $mark->market_id);
            if ($count > 0) {
                continue;
            }
            $marketUlid = $mark->market?->ulid;
            $mark->delete();
            $dropped = true;
            Audit::log(
                action: AuditAction::WaveStrategyReopened,
                actor: $actor,
                subject: $locked,
                // Literal keys on purpose (AuditPayloadKeysGuardTest reads them statically).
                metadata: ['wave' => $locked->ulid, 'step' => $mark->step, 'market' => $marketUlid, 'reason' => 'emptied'],
                agencyId: $locked->agency_id,
            );
        }

        return $dropped ? StrategyStepState::read($locked, $steps) : $after;
    }

    /**
     * @param  list<array{id: int, count: int}>  $markets
     */
    private static function marketCount(array $markets, int $marketId): int
    {
        foreach ($markets as $m) {
            if ($m['id'] === $marketId) {
                return $m['count'];
            }
        }

        return 0;
    }
}
