<?php

declare(strict_types=1);

namespace App\Modules\Creators\Features;

use App\Modules\Creators\CreatorsServiceProvider;
use Closure;

/**
 * Pennant feature flag — gates the scheduled incomplete-creator email nudge
 * (creators:send-incomplete-nudges): a one-time email to self-serve creators
 * sitting `application_status = incomplete` for 48+ hours who have never been
 * nudged (docs/reviews/incomplete-creator-nudge-review.md).
 *
 * Default scope = global (Phase 1 convention; per-user / per-tenant scoping is
 * a Phase 2+ capability). Default state = OFF — this gates an outbound email
 * side effect, so it ships OFF and an operator flips it globally via
 * `Feature::activate(self::NAME)` (or the admin Feature-flags page) AFTER
 * previewing volume with `creators:send-incomplete-nudges --dry-run`.
 *
 * The flag is checked INSIDE the nudge service (`Feature::active(self::NAME)`,
 * no scope arg — the null-scope pin in
 * {@see CreatorsServiceProvider::configurePennantScope()}
 * makes the console send and the admin toggle agree). Flag OFF → the command
 * is an explicit no-op (a "disabled" line, exit 0); `--dry-run` ignores the
 * flag so an operator can read the would-send counts before enabling.
 *
 * Invocation pattern:
 *
 *   use Laravel\Pennant\Feature;
 *   use App\Modules\Creators\Features\IncompleteCreatorNudgeEnabled;
 *
 *   if (Feature::active(IncompleteCreatorNudgeEnabled::NAME)) {
 *       // flag-ON path: the daily command sends + stamps.
 *   } else {
 *       // flag-OFF path: the command is a no-op.
 *   }
 *
 * The {@see CreatorsServiceProvider::registerFeatureFlags()}
 * registers this resolver via `Feature::define(self::NAME, self::default())`.
 */
final class IncompleteCreatorNudgeEnabled
{
    /**
     * Snake-cased registry name (docs/feature-flags.md). All
     * `Feature::active(...)` / `Feature::activate(...)` call sites MUST
     * reference this constant rather than re-typing the string, so a rename
     * is a single edit + a typed-out usages search surfaces every consumer.
     */
    public const NAME = 'incomplete_creator_nudge_enabled';

    /**
     * Default resolver. Phase 1 flags are operator-controlled and scope-less
     * (the `$scope` argument is ignored), so the default is a constant `false`
     * — the operator turns it on globally via `Feature::activate(self::NAME)`.
     *
     * Pennant's `Feature::define(string, mixed)` short-circuits if the second
     * argument is not a {@see Closure} (it stores the value as-is — see
     * Drivers/Decorator.php:153), so we MUST return a Closure here.
     */
    public static function default(): Closure
    {
        return static fn (mixed $scope = null): bool => false;
    }
}
