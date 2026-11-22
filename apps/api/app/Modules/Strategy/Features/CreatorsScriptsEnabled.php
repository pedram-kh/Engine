<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Features;

use App\Modules\Waves\Features\WavesEnabled;
use Closure;
use Laravel\Pennant\Feature;

/**
 * PL-3 (Production Line, sprint 3): Creators and Scripts. One flag for the whole sprint (PL-3.1 Creators, PL-3.2 Script
 * editor, PL-3.3 Workflow, PL-3.4 Release), never reused. It NEEDS `strategy_enabled` and `waves_enabled`: creators hang off
 * a wave's Strategy workspace, so every endpoint, listener and payload key behind it requires all three.
 *
 * Standing rule (docs/reviews/production-line-sprints.md §3): one flag per sprint. Do not reuse this name for another sprint.
 *
 * Default state = OFF. Reads are ordered cheapest-first: this flag alone answers "off" without reading the other two.
 */
final class CreatorsScriptsEnabled
{
    /** Snake-cased registry name (docs/feature-flags.md). Every `Feature::active(...)` MUST use it. */
    public const NAME = 'creators_scripts_enabled';

    /** MUST return a Closure (Pennant stores a non-Closure second argument as the literal value). */
    public static function default(): Closure
    {
        return static fn (mixed $scope = null): bool => false;
    }

    /** All three flags: the one question every PL-3 gate asks. This flag is read FIRST and short-circuits. */
    public static function active(): bool
    {
        return Feature::active(self::NAME)
            && StrategyEnabled::active();
    }

    /** The two it needs, without this one (for the "needs" tests). */
    public static function prerequisites(): bool
    {
        return Feature::active(WavesEnabled::NAME) && Feature::active(StrategyEnabled::NAME);
    }
}
