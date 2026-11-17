<?php

declare(strict_types=1);

namespace App\Modules\Waves\Features;

use Closure;

/**
 * PL-1 (Production Line, sprint 1): brand client files and waves — the Client files and Waves panels on the
 * Brand page, the New wave dialog, the wave page and every endpoint behind them. OFF at deploy.
 *
 * Standing rule (docs/reviews/production-line-sprints.md §3): this flag gates PL-1 ONLY. Each later sprint
 * ships behind its own flag, so a deploy never switches new work on. Do not reuse this name.
 *
 * Default state = OFF.
 */
final class WavesEnabled
{
    /** Snake-cased registry name (docs/feature-flags.md). Every `Feature::active(...)` MUST use it. */
    public const NAME = 'waves_enabled';

    /** MUST return a Closure (Pennant stores a non-Closure second argument as the literal value). */
    public static function default(): Closure
    {
        return static fn (mixed $scope = null): bool => false;
    }
}
