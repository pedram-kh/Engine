<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Services;

use App\Modules\Waves\Models\Wave;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * THE one door of every creator write (PL-3.1 plan 3.2, gate B37): one transaction whose FIRST statement is the wave row
 * lock, so two creator writes, a decision, a campaign accept (through the listener), and a wave edit serialise per wave and
 * cannot deadlock. A source-string test fails when a creator writer opens a bare `DB::transaction(`.
 *
 * PL-3.3 changes the body of {@see self::transaction()} (today: the lock) to `StrategyTrackerSync::transaction`.
 */
final class WaveCreatorGuard
{
    /** Must be called inside a transaction. */
    public static function lockWave(Wave $wave): Wave
    {
        return StrategyGuard::lockWave($wave);
    }

    /**
     * @template T
     *
     * @param  Closure(Wave): T  $change  receives the LOCKED wave
     * @return T
     */
    public static function transaction(Wave $wave, Closure $change): mixed
    {
        return DB::transaction(static function () use ($wave, $change): mixed {
            return $change(self::lockWave($wave));
        });
    }
}
