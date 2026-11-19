<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Features;

use App\Modules\Waves\Features\WavesEnabled;
use Closure;
use Laravel\Pennant\Feature;

/**
 * PL-2 (Production Line, sprint 2): the Strategy & Scripts workspace — USPs, personas, and (in later PL-2 chunks) the
 * messaging matrix, concepts and the tracker wiring. OFF at deploy. It NEEDS `waves_enabled`: strategy work hangs off
 * a wave, so every strategy endpoint and `features.strategy` require both flags.
 *
 * Standing rule (docs/reviews/production-line-sprints.md §3): one flag per sprint, never reused. This flag gates PL-2
 * ONLY (all four PL-2 chunks ship behind it). Do not reuse this name.
 *
 * Default state = OFF.
 */
final class StrategyEnabled
{
    /** Snake-cased registry name (docs/feature-flags.md). Every `Feature::active(...)` MUST use it. */
    public const NAME = 'strategy_enabled';

    /** MUST return a Closure (Pennant stores a non-Closure second argument as the literal value). */
    public static function default(): Closure
    {
        return static fn (mixed $scope = null): bool => false;
    }

    /** Both flags: the one question every strategy gate asks. */
    public static function active(): bool
    {
        return Feature::active(WavesEnabled::NAME)
            && Feature::active(self::NAME);
    }
}
