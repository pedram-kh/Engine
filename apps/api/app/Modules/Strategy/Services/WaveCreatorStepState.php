<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Services;

/**
 * The state of the Creators step (PL-3.1 plan 1.8, Q8), per market and for the wave. Pure: it reads slots the caller already
 * loaded, so it costs no query of its own.
 *
 *   not started   no creators in the market
 *   in progress   at least one creator and the market is not done
 *   done          at least one creator is Confirmed AND Approved by the client, and EVERY creator is Declined or
 *                 (Confirmed and Approved). Persona coverage is a warning, never a condition.
 *
 * The wave step is done when done in EVERY market, not started when not started in every market, else in progress. Not part of
 * {@see StrategyStepState::STEPS}: the tracker row `ugc.creators` stays hand-set in PL-3.1 (PL-3.3 connects it).
 */
final class WaveCreatorStepState
{
    public const STEP = 'creators';

    /**
     * @param  list<array{status: string, approved: bool}>  $slots  the slots of ONE market
     */
    public static function market(array $slots): string
    {
        if ($slots === []) {
            return StrategyStepState::NOT_STARTED;
        }

        $anyAgreed = false;
        foreach ($slots as $slot) {
            $agreed = $slot['status'] === WaveCreatorStatus::CONFIRMED && $slot['approved'];
            if ($agreed) {
                $anyAgreed = true;

                continue;
            }
            if ($slot['status'] !== WaveCreatorStatus::DECLINED) {
                return StrategyStepState::IN_PROGRESS;
            }
        }

        return $anyAgreed ? StrategyStepState::DONE : StrategyStepState::IN_PROGRESS;
    }

    /**
     * @param  list<string>  $marketStates
     * @return array{state: string, markets_done: int, markets_total: int}
     */
    public static function wave(array $marketStates): array
    {
        $total = count($marketStates);
        $done = count(array_filter($marketStates, static fn (string $s): bool => $s === StrategyStepState::DONE));
        $any = count(array_filter($marketStates, static fn (string $s): bool => $s !== StrategyStepState::NOT_STARTED));

        return [
            'state' => match (true) {
                $total === 0 => StrategyStepState::NOT_STARTED,
                $done === $total => StrategyStepState::DONE,
                $any === 0 => StrategyStepState::NOT_STARTED,
                default => StrategyStepState::IN_PROGRESS,
            },
            'markets_done' => $done,
            'markets_total' => $total,
        ];
    }
}
