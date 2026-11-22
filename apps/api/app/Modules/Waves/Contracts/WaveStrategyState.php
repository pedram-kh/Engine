<?php

declare(strict_types=1);

namespace App\Modules\Waves\Contracts;

use App\Modules\Identity\Models\User;
use App\Modules\Waves\Models\Wave;
use Closure;

/**
 * PL-2.5: what the Waves module (tracker, wave edit) may ask of the Strategy module, and nothing else (the mirror of
 * {@see WaveMarketGuard}). Implemented by `StrategyWaveState`, bound in the Strategy provider whether or not the flag is
 * on; every method is a no-op or an empty answer while `strategy_enabled` (with `waves_enabled`) is OFF, so with strategy
 * OFF the tracker is exactly what PL-1 left.
 */
interface WaveStrategyState
{
    /** True when both `waves_enabled` and `strategy_enabled` are on. */
    public function active(): bool;

    /**
     * The real state of the four built steps of a wave, keyed by tracker step key (`ugc.usps`, `ugc.personas`,
     * `ugc.matrix`, `ugc.concepts`). Empty while strategy is OFF.
     *
     * @return array<string, array{state: string, markets_done: int, markets_total: int}>
     */
    public function real(Wave $wave): array;

    /**
     * Bring the four tracker rows in line with the real steps (the F1 table, catch-up case). Idempotent; writes only what
     * differs. A no-op while strategy is OFF. Takes the wave lock.
     */
    public function catchUp(Wave $wave, ?User $actor): void;

    /**
     * Run a write that can change a step's state inside the one choke point: the wave lock, the real state before and
     * after, and the tracker rows written in the same transaction. While strategy is OFF it only runs the closure.
     *
     * @template T
     *
     * @param  Closure(): T  $change
     * @return T
     */
    public function around(Wave $wave, ?User $actor, Closure $change): mixed;
}
