<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Services;

use App\Modules\Strategy\Models\WaveConcept;
use App\Modules\Strategy\Models\WavePersona;
use App\Modules\Waves\Contracts\WaveMarketGuard;
use App\Modules\Waves\Models\Wave;

/** The Strategy side of {@see WaveMarketGuard}: personas per market of a wave. Read-only, no lock of its own. */
final class StrategyWaveMarketGuard implements WaveMarketGuard
{
    public function personaCountsByMarket(Wave $locked, array $removedIds): array
    {
        if ($removedIds === []) {
            return [];
        }

        /** @var array<int, int> $counts */
        $counts = WavePersona::query()
            ->where('wave_id', $locked->id)
            ->whereIn('market_id', $removedIds)
            ->selectRaw('market_id, count(*) as n')
            ->groupBy('market_id')
            ->orderBy('market_id')
            ->pluck('n', 'market_id')
            ->map(static fn (mixed $n): int => (int) $n)
            ->all();

        return $counts;
    }

    public function conceptCountsByMarket(Wave $locked, array $removedIds): array
    {
        if ($removedIds === []) {
            return [];
        }

        /** @var array<int, int> $counts */
        $counts = WaveConcept::query()
            ->where('wave_id', $locked->id)
            ->whereIn('market_id', $removedIds)
            ->selectRaw('market_id, count(*) as n')
            ->groupBy('market_id')
            ->orderBy('market_id')
            ->pluck('n', 'market_id')
            ->map(static fn (mixed $n): int => (int) $n)
            ->all();

        return $counts;
    }
}
