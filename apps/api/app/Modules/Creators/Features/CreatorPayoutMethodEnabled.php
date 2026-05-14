<?php

declare(strict_types=1);

namespace App\Modules\Creators\Features;

use Closure;

/**
 * Pennant feature flag — gates Stripe Connect Express onboarding for
 * creators + payout-method UI (docs/feature-flags.md row 5).
 *
 * Default scope = global (Phase 1 convention). Default state = OFF.
 *
 * Invocation pattern:
 *
 *   use Laravel\Pennant\Feature;
 *   use App\Modules\Creators\Features\CreatorPayoutMethodEnabled;
 *
 *   if (Feature::active(CreatorPayoutMethodEnabled::NAME)) {
 *       // flag-ON path: provider is Mock* (Sprint 3) or Real Stripe (Sprint 7)
 *   } else {
 *       // flag-OFF path: skip the payout-method step; profile shows
 *       // "payout setup pending" placeholder.
 *   }
 *
 * Stripe Connect onboarding-completion uses status-poll only in
 * Sprint 3 (no webhook); the `account.updated` webhook handler is
 * deferred to Sprint 10 per docs/06-INTEGRATIONS.md § 2.3 + Q-stripe-
 * no-webhook-acceptable in the chunk-2 plan.
 */
final class CreatorPayoutMethodEnabled
{
    public const NAME = 'creator_payout_method_enabled';

    /**
     * Default resolver — must be a {@see Closure} for Pennant to
     * invoke it on every check (see KycVerificationEnabled::default()
     * for the docblock-level reasoning).
     */
    public static function default(): Closure
    {
        return static fn (mixed $scope = null): bool => false;
    }
}
