<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Http\Resources;

use App\Modules\Agencies\Models\Agency;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON representation of an Agency's configurable settings.
 *
 * Sprint 2 scope: default_currency + default_language only.
 * Nothing more per the kickoff's pre-answered settings scope.
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
            ],
        ];
    }
}
