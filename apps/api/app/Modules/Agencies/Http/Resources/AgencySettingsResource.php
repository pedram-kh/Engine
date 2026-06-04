<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Http\Resources;

use App\Modules\Agencies\Models\Agency;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON representation of an Agency's configurable settings.
 *
 * Sprint 2: default_currency + default_language (top-level columns).
 * Sprint 7 (D-4): blacklist_notification_policy — the first key surfaced from
 * the `settings` jsonb. Default OFF (creators are not emailed unless opted in).
 *
 * @mixin Agency
 */
final class AgencySettingsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $agency = $this->resource;
        assert($agency instanceof Agency);

        return [
            'id' => $agency->ulid,
            'type' => 'agency_settings',
            'attributes' => [
                'default_currency' => $agency->default_currency,
                'default_language' => $agency->default_language,
                // Sprint 7 (D-4) — read from the settings jsonb; default OFF.
                'blacklist_notification_policy' => (bool) ($agency->settings['blacklist_notification_policy'] ?? false),
            ],
        ];
    }
}
