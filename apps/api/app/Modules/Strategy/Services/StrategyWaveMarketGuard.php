<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Models\WaveConcept;
use App\Modules\Strategy\Models\WaveCreator;
use App\Modules\Strategy\Models\WavePersona;
use App\Modules\Strategy\Models\WaveScript;
use App\Modules\Strategy\Models\WaveStrategyCompletion;
use App\Modules\Strategy\Notes\NoteService;
use App\Modules\Waves\Contracts\WaveMarketGuard;
use App\Modules\Waves\Models\Wave;
use App\Modules\Waves\Models\WaveMarket;
use Illuminate\Support\Facades\Auth;

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

    public function creatorCountsByMarket(Wave $locked, array $removedIds): array
    {
        if ($removedIds === []) {
            return [];
        }

        /** @var array<int, int> $counts */
        $counts = WaveCreator::query()
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

    public function renamePersonaCodes(Wave $locked, int $marketId, string $oldCode, string $newCode): array
    {
        $personas = WavePersona::query()
            ->where('wave_id', $locked->id)->where('market_id', $marketId)
            ->orderBy('slot')->get(['id', 'slot', 'code']);

        $taken = [];
        foreach ($personas as $persona) {
            $taken[$persona->code] = true;
        }

        $renamed = 0;
        $skipped = 0;
        $custom = 0;
        foreach ($personas as $persona) {
            if ($persona->code !== $oldCode.$persona->slot) {
                $custom++;

                continue;
            }
            $target = $newCode.$persona->slot;
            if (isset($taken[$target])) {
                $skipped++;

                continue;
            }
            WavePersona::query()->whereKey($persona->id)->update(['code' => $target, 'updated_at' => now()]);
            unset($taken[$persona->code]);
            $taken[$target] = true;
            $renamed++;
        }

        return ['renamed' => $renamed, 'skipped' => $skipped, 'kept_custom' => $custom];
    }

    public function noteCountsByMarket(Wave $locked, array $removedIds): array
    {
        return NoteService::countsByMarket($locked, $removedIds);
    }

    public function forgetMarkets(Wave $locked, array $removedIds): array
    {
        if ($removedIds === []) {
            return ['completions' => 0, 'notes' => 0, 'scripts' => 0];
        }

        return [
            'completions' => WaveStrategyCompletion::query()->where('wave_id', $locked->id)->whereIn('market_id', $removedIds)->delete(),
            'notes' => NoteService::purgeMarkets($locked, $removedIds),
            'scripts' => $this->purgeScripts($locked, $removedIds),
        ];
    }

    public function scriptCountsByMarket(Wave $locked, array $removedIds): array
    {
        if ($removedIds === []) {
            return [];
        }

        /** @var array<int, int> $counts */
        $counts = WaveScript::query()
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

    /**
     * Delete the scripts (archived: a market with creators is refused first) of the removed markets, each with its hooks, texts and
     * audit row, in number order so the run is the same every time. The wave lock is already held.
     *
     * @param  list<int>  $removedIds
     */
    private function purgeScripts(Wave $locked, array $removedIds): int
    {
        $markets = WaveMarket::query()->where('wave_id', $locked->id)->whereIn('id', $removedIds)->pluck('ulid', 'id')->all();
        $actor = Auth::user();
        $scripts = WaveScript::query()->where('wave_id', $locked->id)->whereIn('market_id', $removedIds)->orderBy('market_id')->orderBy('id')->lockForUpdate()->get();
        foreach ($scripts as $script) {
            ScriptArchive::deleteOne($locked, $script, (string) ($markets[$script->market_id] ?? ''), $actor instanceof User ? $actor : null);
        }

        return $scripts->count();
    }
}
