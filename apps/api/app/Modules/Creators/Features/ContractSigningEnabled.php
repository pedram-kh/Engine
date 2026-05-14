<?php

declare(strict_types=1);

namespace App\Modules\Creators\Features;

use Closure;

/**
 * Pennant feature flag — gates e-sign envelope creation for the
 * master contract step + `/api/v1/integrations/esign/*` surface
 * (docs/feature-flags.md row 6).
 *
 * Default scope = global (Phase 1 convention). Default state = OFF.
 *
 * Invocation pattern:
 *
 *   use Laravel\Pennant\Feature;
 *   use App\Modules\Creators\Features\ContractSigningEnabled;
 *
 *   if (Feature::active(ContractSigningEnabled::NAME)) {
 *       // flag-ON path: e-sign envelope created via Mock* (Sprint 3)
 *       // or Real* (Sprint 9).
 *   } else {
 *       // flag-OFF path: click-through acceptance fallback — creator
 *       // POSTs to /wizard/contract/click-through-accept which writes
 *       // creators.click_through_accepted_at (migration #38, sub-step 7).
 *   }
 */
final class ContractSigningEnabled
{
    public const NAME = 'contract_signing_enabled';

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
