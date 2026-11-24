<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Services;

/**
 * The state of step 6 (Scripts) in PL-2.5's model (`not_started`, `in_progress`, `done`). There is no "mark complete" for
 * scripts (the workflow stages are PL-3.3), so a market is `not_started` with no live script and `in_progress` with one;
 * `done` is never reached in PL-3.2. A wave is `not_started` until any market has a script.
 */
final class WaveScriptStepState
{
    public const STEP = 'scripts';

    public static function market(int $liveScripts): string
    {
        return $liveScripts > 0 ? StrategyStepState::IN_PROGRESS : StrategyStepState::NOT_STARTED;
    }

    /**
     * @param  list<string>  $marketStates
     * @return array{state: string, markets_total: int, markets_started: int}
     */
    public static function wave(array $marketStates): array
    {
        $started = count(array_filter($marketStates, static fn (string $s): bool => $s !== StrategyStepState::NOT_STARTED));

        return [
            'state' => $started > 0 ? StrategyStepState::IN_PROGRESS : StrategyStepState::NOT_STARTED,
            'markets_total' => count($marketStates),
            'markets_started' => $started,
        ];
    }
}
