<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Http\Resources;

use App\Modules\Agencies\Models\AgencyMembership;
use App\Modules\Strategy\Models\WaveStrategyCompletion;
use App\Modules\Strategy\Notes\NoteService;
use App\Modules\Strategy\Services\StrategyStepState;
use App\Modules\Waves\Models\Wave;

/**
 * The PL-2.5 part of the strategy summary: the real state of each step (per wave and per market), the mark with who and
 * when, the live warnings, where "Strategy & Scripts" opens, and the note counts (this is the agency response; counts only,
 * never a body). Every figure comes from {@see StrategyStepState}; nothing here is stored.
 */
final class StepsPresenter
{
    /**
     * @return array{steps: array<string, array<string, mixed>>, landing: array{step: string, market: string|null, market_id: string|null}, note_counts: array<string, mixed>}
     */
    public static function build(Wave $wave): array
    {
        $read = StrategyStepState::read($wave);

        /** @var array<string, array<int, WaveStrategyCompletion>> $marks */
        $marks = [];
        $completions = WaveStrategyCompletion::query()->where('wave_id', $wave->id)->with('completedBy')->get();
        $userIds = $completions->pluck('completed_by_user_id')->filter()->unique()->values()->all();
        $active = $userIds === [] ? [] : AgencyMembership::query()
            ->where('agency_id', $wave->agency_id)
            ->whereIn('user_id', $userIds)
            ->whereNotNull('accepted_at')
            ->whereNull('deleted_at')
            ->pluck('user_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        foreach ($completions as $c) {
            $marks[$c->step][(int) $c->market_id] = $c;
        }

        $markOf = static function (?WaveStrategyCompletion $c) use ($active): array {
            if ($c === null) {
                return ['completed_by' => null, 'completed_at' => null];
            }
            $user = $c->completedBy;
            $isActive = $user !== null && in_array((int) $user->id, $active, true);

            return [
                'completed_by' => ['name' => $isActive ? (string) $user->name : null, 'former' => ! $isActive],
                'completed_at' => $c->completed_at->toIso8601String(),
            ];
        };

        $steps = [];
        foreach (StrategyStepState::STEPS as $step) {
            $r = $read[$step];
            if ($step === StrategyStepState::USPS) {
                $steps[$step] = [
                    'state' => $r['state'],
                    'count' => $r['count'],
                    'marked' => $r['marked'],
                    'warnings' => $r['marked'] ? StrategyStepState::warnings($wave, $step, null) : [],
                ] + $markOf($marks[$step][0] ?? null);

                continue;
            }

            $markets = [];
            foreach ($r['markets'] as $m) {
                $stored = $step === StrategyStepState::CONCEPTS ? null : ($marks[$step][$m['id']] ?? null);
                $markets[] = [
                    'market' => $m['market'],
                    'code' => $m['code'],
                    'state' => $m['state'],
                    'count' => $m['count'],
                    'marked' => $m['marked'],
                    // The warnings are live and are shown on a market that is marked complete (K1, Q7).
                    'warnings' => ($stored !== null) ? StrategyStepState::warnings($wave, $step, $m['id']) : [],
                ] + $markOf($stored);
            }
            $steps[$step] = [
                'state' => $r['state'],
                'markets_done' => $r['markets_done'],
                'markets_total' => $r['markets_total'],
                'markets' => $markets,
            ];
        }

        $landing = StrategyStepState::landing($read);
        $marketUlid = null;
        foreach ($read[StrategyStepState::CONCEPTS]['markets'] as $m) {
            if ($m['code'] === $landing['market']) {
                $marketUlid = $m['market'];
            }
        }

        return [
            'steps' => $steps,
            'landing' => $landing + ['market_id' => $marketUlid],
            'note_counts' => NoteService::counts($wave),
        ];
    }
}
