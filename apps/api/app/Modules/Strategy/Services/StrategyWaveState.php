<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Features\StrategyEnabled;
use App\Modules\Waves\Contracts\WaveStrategyState;
use App\Modules\Waves\Models\Wave;
use Closure;

/**
 * The Strategy side of {@see WaveStrategyState}: a thin adapter over {@see StrategyStepState} and
 * {@see StrategyTrackerSync}. Every method is inert while strategy is OFF.
 */
final class StrategyWaveState implements WaveStrategyState
{
    public function active(): bool
    {
        return StrategyEnabled::active();
    }

    public function real(Wave $wave): array
    {
        if (! StrategyEnabled::active()) {
            return [];
        }

        $out = [];
        foreach (StrategyStepState::read($wave) as $step => $r) {
            $out[StrategyStepState::rowKey($step)] = ['state' => $r['state'], 'markets_done' => $r['markets_done'], 'markets_total' => $r['markets_total']];
        }

        return $out;
    }

    public function catchUp(Wave $wave, ?User $actor): void
    {
        StrategyTrackerSync::catchUp($wave, $actor);
    }

    public function around(Wave $wave, ?User $actor, Closure $change): mixed
    {
        return StrategyTrackerSync::transaction($wave, $actor, StrategyStepState::STEPS, $change);
    }
}
