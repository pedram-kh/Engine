<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Services;

use App\Modules\Strategy\Models\WavePersona;
use App\Modules\Waves\Contracts\WaveMarketGuard;
use App\Modules\Waves\Models\Wave;

/** The Strategy side of {@see WaveMarketGuard}: personas per market of a wave. Read-only, no lock of its own. */
final class StrategyWaveMarketGuard implements WaveMarketGuard
{
    public function personaCountsByMarket(Wave $locked, array $removedCodes): array
    {
        if ($removedCodes === []) {
            return [];
        }

        /** @var array<string, int> $counts */
        $counts = WavePersona::query()
            ->where('wave_id', $locked->id)
            ->whereIn('market', $removedCodes)
            ->selectRaw('market, count(*) as n')
            ->groupBy('market')
            ->orderBy('market')
            ->pluck('n', 'market')
            ->map(static fn (mixed $n): int => (int) $n)
            ->all();

        return $counts;
    }
}
