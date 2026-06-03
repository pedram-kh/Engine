<?php

declare(strict_types=1);

namespace App\Modules\Creators\Http\Resources;

use App\Modules\Creators\Services\Availability\AvailabilityOccurrence;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Creator-facing view of one expanded availability occurrence.
 *
 * Wraps an {@see AvailabilityOccurrence} — the start/end are the concrete
 * occurrence instant (for a recurring block this is the per-week instance;
 * for a one-off it is the block's own window). The source block's ULID,
 * `is_recurring`, and `recurrence_rule` ride along so the calendar editor
 * knows which block to edit and with what rule.
 *
 * INCLUDES `reason` — this is the creator's OWN view (reason is creator-only
 * per docs/03-DATA-MODEL.md `:286`). The agency view uses the separate
 * {@see AgencyAvailabilityResource}, which omits it (D-a6).
 *
 * @mixin AvailabilityOccurrence
 */
final class AvailabilityOccurrenceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $occurrence = $this->resource;
        assert($occurrence instanceof AvailabilityOccurrence);

        $block = $occurrence->block;

        return [
            'id' => $block->ulid,
            'type' => 'availability_blocks',
            'attributes' => [
                'starts_at' => $occurrence->startsAt->toIso8601String(),
                'ends_at' => $occurrence->endsAt->toIso8601String(),
                'is_all_day' => $block->is_all_day,
                'block_type' => $block->block_type->value,
                'kind' => $block->kind->value,
                'reason' => $block->reason,
                'is_recurring' => $block->is_recurring,
                'recurrence_rule' => $block->recurrence_rule,
            ],
        ];
    }
}
