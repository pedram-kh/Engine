<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Http\Resources;

use App\Modules\Creators\Http\Resources\AvailabilityOccurrenceResource;
use App\Modules\Creators\Services\Availability\AvailabilityOccurrence;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Agency-facing view of one expanded availability occurrence (D-a6).
 *
 * DELIBERATELY OMITS `reason` — it is creator-only (docs/03-DATA-MODEL.md
 * `:286`, inventory B4). This is a dedicated resource rather than a reuse of
 * the creator-facing {@see AvailabilityOccurrenceResource}
 * precisely so `reason` can never leak to an agency through a shared shape.
 * Break-revert: adding `reason` here fails the agency-view no-reason test.
 *
 * Reads from the SAME {@see AvailabilityOccurrence} the conflict-detection
 * service consumes, so the agency view and conflict-detection agree on the
 * creator's availability for a given window (D-a4).
 *
 * @mixin AvailabilityOccurrence
 */
final class AgencyAvailabilityResource extends JsonResource
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
                'is_recurring' => $block->is_recurring,
                'recurrence_rule' => $block->recurrence_rule,
            ],
        ];
    }
}
