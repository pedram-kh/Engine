<?php

declare(strict_types=1);

namespace App\Modules\Creators\Features;

use Closure;

/**
 * AH-118 item 1 — the per-campaign "only creators in the listed regions can apply" rule: the switch on the campaign form, the apply check and the creator-side `is_region_locked` flag. OFF at deploy.
 *
 * Gates the switch (a write that turns it on is refused with 422 `campaign.region_restriction_disabled`), the apply check, `is_region_locked` and the job-posted fan-out exclusion. Gates nothing that already exists: a campaign with the switch stored ON behaves as unrestricted while the flag is off.
 *
 * Default state = OFF. Lives in the CREATORS feature registry despite gating a
 * CAMPAIGNS-module surface — the existing one-registry convention.
 */
final class CampaignRegionRestrictionEnabled
{
    /**
     * Snake-cased registry name (docs/feature-flags.md). Every
     * `Feature::active(...)` call site MUST reference this constant.
     */
    public const NAME = 'campaign_region_restriction_enabled';

    /**
     * Default resolver. MUST return a Closure (Pennant stores a non-Closure
     * second argument of `define()` as the literal value).
     */
    public static function default(): Closure
    {
        return static fn (mixed $scope = null): bool => false;
    }
}
