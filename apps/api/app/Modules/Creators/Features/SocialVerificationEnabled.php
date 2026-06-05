<?php

declare(strict_types=1);

namespace App\Modules\Creators\Features;

use App\Modules\Campaigns\Services\CampaignAssignmentStateMachine;
use App\Modules\Creators\Integrations\Stubs\SkippedSocialProvider;
use Closure;

/**
 * Pennant feature flag — gates social post verification, i.e. the
 * `posted → live_verified` transition (Sprint 9 Chunk 2, D-11). Mirrors
 * {@see ContractSigningEnabled} exactly (docs/feature-flags.md row 7).
 *
 * Default scope = global (Phase 1 convention).
 *
 * ⚠ DRIVER-BASED DEFAULT (a principled variation on "every flag defaults
 * OFF"). The default is computed from `integrations.social.driver`:
 *
 *   - driver = `mock` (the Phase-1 default)  → default ON
 *   - driver = a real adapter (meta/tiktok…) → default OFF
 *
 * Why: the "default OFF" convention exists to honour **"No silent vendor
 * calls"** — an un-provisioned instance must never reach a vendor. The MOCK
 * provider makes NO vendor calls, so while it is the bound driver the
 * rationale does not apply and default-ON is sound (it lets the
 * `posted → live_verified` arc + the failure→manual-resolution arc run out of
 * the box in dev/demo). The moment a REAL adapter is configured the default
 * flips back to OFF, restoring the no-silent-vendor-calls guarantee: the
 * operator must explicitly `Feature::activate()` it once secrets are
 * provisioned. See docs/feature-flags.md (the driver-based default note).
 *
 * Defense-in-depth still holds when OFF: the
 * {@see CampaignAssignmentStateMachine::verifyLive()} transition throws the
 * vendor-gated exception, and {@see SkippedSocialProvider}
 * is bound (the break-revert anchor).
 *
 * Invocation pattern:
 *
 *   use Laravel\Pennant\Feature;
 *   use App\Modules\Creators\Features\SocialVerificationEnabled;
 *
 *   if (Feature::active(SocialVerificationEnabled::NAME)) {
 *       // flag-ON path: verifyLive() advances posted → live_verified
 *       // once the verification job confirms the post (mock or, later,
 *       // a real Meta/TikTok/YouTube adapter).
 *   } else {
 *       // flag-OFF path: verifyLive() throws — no manual path to
 *       // live_verified without a verification adapter.
 *   }
 */
final class SocialVerificationEnabled
{
    public const NAME = 'social_verification_enabled';

    /**
     * Default resolver — must be a {@see Closure} for Pennant to invoke it on
     * every check (see KycVerificationEnabled::default() for the reasoning).
     *
     * Driver-based (see the class docblock): ON only while the bound social
     * driver is the no-vendor `mock`; OFF the moment a real adapter is wired,
     * so the "no silent vendor calls" guarantee survives an un-provisioned
     * real-driver instance.
     */
    public static function default(): Closure
    {
        return static fn (mixed $scope = null): bool => config('integrations.social.driver', 'mock') === 'mock';
    }
}
