<?php

declare(strict_types=1);

namespace App\Modules\Creators\Features;

use Closure;

/**
 * Pennant feature flag — gates KYC verification across the wizard +
 * `/api/v1/integrations/kyc/*` surface (docs/feature-flags.md row 4).
 *
 * Default scope = global (Phase 1 convention; per-user / per-tenant
 * scoping is a Phase 2+ capability). Default state = OFF.
 *
 * Invocation pattern:
 *
 *   use Laravel\Pennant\Feature;
 *   use App\Modules\Creators\Features\KycVerificationEnabled;
 *
 *   if (Feature::active(KycVerificationEnabled::NAME)) {
 *       // flag-ON path: provider is Mock* (Sprint 3) or Real* (Sprint 4+)
 *   } else {
 *       // flag-OFF path: skip the wizard step; admin can manually approve
 *   }
 *
 * The {@see CreatorsServiceProvider::boot()} registers this resolver
 * via `Feature::define(self::NAME, self::default())`. Provider
 * binding (Mock when ON, Skipped when OFF) lives in the same service
 * provider (Sprint 3 Chunk 2 sub-step 8).
 */
final class KycVerificationEnabled
{
    /**
     * Snake-cased registry name (docs/feature-flags.md). All
     * `Feature::active(...)` / `Feature::activate(...)` call sites
     * MUST reference this constant rather than re-typing the string,
     * so a rename is a single edit + a typed-out usages search
     * surfaces every consumer.
     */
    public const NAME = 'kyc_verification_enabled';

    /**
     * Default resolver. Phase 1 flags are operator-controlled and
     * scope-less (the `$scope` argument is ignored), so the default
     * is a constant `false` — the operator turns it on globally
     * via `Feature::activate(self::NAME)` once the manual steps in
     * SPRINT-0-MANUAL-STEPS.md Batch 2 §2.8 are complete.
     *
     * Pennant's `Feature::define(string, mixed)` short-circuits if
     * the second argument is not a {@see Closure} (it stores the
     * value as-is — see Drivers/Decorator.php:153), so we MUST
     * return a Closure here. Phase 2+ scope-aware flags will read
     * the `$scope` argument; Phase 1 ignores it.
     */
    public static function default(): Closure
    {
        return static fn (mixed $scope = null): bool => false;
    }
}
