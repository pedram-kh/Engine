<?php

declare(strict_types=1);

namespace App\Modules\Creators\Features;

use Closure;

/**
 * AH-120 — per-campaign brand exclusivity: "creators selected for this campaign can't apply to campaigns of these brands". The switch on the campaign form, the apply check, the creator-side `is_brand_locked` flag, the agency's conflict reads and the job-posted fan-out exclusion. OFF at deploy.
 *
 * Gates the write (a non-empty list or a date is refused with 422 `campaign.brand_exclusivity_disabled`; clearing is always allowed), the apply check, `is_brand_locked`, the conflict reads and the fan-out exclusion. Gates nothing that already exists: a campaign with a stored list behaves as unrestricted while the flag is off.
 *
 * Default state = OFF. Lives in the CREATORS feature registry despite gating a
 * CAMPAIGNS-module surface — the existing one-registry convention.
 */
final class CampaignBrandExclusivityEnabled
{
    /**
     * Snake-cased registry name (docs/feature-flags.md). Every
     * `Feature::active(...)` call site MUST reference this constant.
     */
    public const NAME = 'campaign_brand_exclusivity_enabled';

    /**
     * Default resolver. MUST return a Closure (Pennant stores a non-Closure
     * second argument of `define()` as the literal value).
     */
    public static function default(): Closure
    {
        return static fn (mixed $scope = null): bool => false;
    }
}
