<?php

declare(strict_types=1);

namespace App\Modules\Creators\Features;

use Closure;

/**
 * Pennant feature flag — gates the per-campaign MANUAL contract flow
 * (agency attach + creator click-accept + the `accepted → contracted`
 * machine edge). Decoupled from {@see ContractSigningEnabled} (the
 * e-sign VENDOR flag / master-contract onboarding wizard) so the manual
 * per-campaign flow can ship to production WITHOUT enabling any vendor
 * path (docs/feature-flags.md — the contract-gate-decouple chunk).
 *
 * ⚠ DEFAULT = ON — the documented, principled exception to the
 * "every flag defaults OFF" convention. The convention's own rationale
 * is "no silent vendor calls": we never ship a *vendor-dependent*
 * feature ON by default. THIS flag gates NO vendor — the per-campaign
 * manual accept is an internal, legitimate e-signature (it stamps
 * `signed_signature_data`: method, IP, UA, timestamp; the master
 * agreement §10 declares this binding). The rationale therefore does
 * not apply, so default-ON is sound. See the Default-OFF convention
 * note in docs/feature-flags.md for the written justification (a future
 * reader hitting a default-ON flag MUST find the reasoning there).
 *
 * Default scope = global (Phase 1 convention; per-user / per-tenant
 * scoping is a Phase 2+ capability). Default state = ON.
 *
 * Invocation pattern (mirrors {@see ContractSigningEnabled} /
 * {@see SocialVerificationEnabled}):
 *
 *   use Laravel\Pennant\Feature;
 *   use App\Modules\Creators\Features\PerCampaignContractEnabled;
 *
 *   if (Feature::active(PerCampaignContractEnabled::NAME)) {
 *       // flag-ON path: the manual per-campaign contract feature is
 *       // available (agency attach / creator accept / contract() edge).
 *   } else {
 *       // flag-OFF path: attach / accept / contract() refuse with
 *       // 422 assignment.per_campaign_contract_disabled (break-revert).
 *   }
 */
final class PerCampaignContractEnabled
{
    public const NAME = 'per_campaign_contract_enabled';

    /**
     * Default resolver — must be a {@see Closure} for Pennant to invoke
     * it on every check (Pennant treats a non-Closure second argument to
     * `define()` as the literal stored value — see Drivers/Decorator.php).
     *
     * Returns `true`: this flag is the documented default-ON exception
     * (it gates no vendor — see the class docblock + docs/feature-flags.md).
     */
    public static function default(): Closure
    {
        return static fn (mixed $scope = null): bool => true;
    }
}
