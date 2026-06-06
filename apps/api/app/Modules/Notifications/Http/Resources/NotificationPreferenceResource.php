<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Resources;

use App\Modules\Notifications\Models\NotificationPreference;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A single SPARSE preference row for `GET /me/notification-preferences` (S11.0
 * Chunk 3b, D-3).
 *
 * The read returns ONLY the rows that diverge from the channel default; the FE
 * composes display state as `row.is_enabled ?? defaults[channel]` using the
 * `defaults` block the controller ships alongside this collection — so the
 * channel-default contract is never hardcoded over the wire.
 *
 * @mixin NotificationPreference
 */
final class NotificationPreferenceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $preference = $this->resource;
        assert($preference instanceof NotificationPreference);

        return [
            'notification_type' => $preference->type->value,
            'channel' => $preference->channel->value,
            'is_enabled' => $preference->is_enabled,
        ];
    }
}
