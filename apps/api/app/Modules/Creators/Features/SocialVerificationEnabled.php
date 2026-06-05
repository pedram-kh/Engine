<?php

declare(strict_types=1);

namespace App\Modules\Creators\Features;

use App\Modules\Campaigns\Services\CampaignAssignmentStateMachine;
use Closure;

/**
 * Pennant feature flag — gates social post verification, i.e. the
 * `posted → live_verified` transition (Sprint 9 Chunk 2, D-11). Mirrors
 * {@see ContractSigningEnabled} exactly (docs/feature-flags.md row 7).
 *
 * Default scope = global (Phase 1 convention). Default state = OFF.
 *
 * Production without a real social adapter stays gated (the
 * {@see CampaignAssignmentStateMachine::verifyLive()}
 * transition throws the vendor-gated exception when this flag is OFF — the
 * footgun guard, the break-revert anchor). The mock-verification dev/demo path
 * is flag-ON + the `MockSocialProvider` (driver=mock).
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
     */
    public static function default(): Closure
    {
        return static fn (mixed $scope = null): bool => false;
    }
}
