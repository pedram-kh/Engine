<?php

declare(strict_types=1);

namespace App\Modules\Boards\Http\Resources;

use App\Modules\Boards\Models\BoardCardMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON representation of a board card movement (docs/04-API-DESIGN.md §4
 * envelope) — the "movement history" feed (§13). Column references are emitted
 * as ULIDs (null when the column was since deleted, §14.3).
 *
 * @mixin BoardCardMovement
 */
final class BoardCardMovementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $movement = $this->resource;
        assert($movement instanceof BoardCardMovement);

        return [
            'id' => (string) $movement->id,
            'type' => 'board_card_movements',
            'attributes' => [
                'from_column_id' => $movement->fromColumn?->ulid,
                'to_column_id' => $movement->toColumn?->ulid,
                'triggered_by' => $movement->triggered_by->value,
                'triggered_event_key' => $movement->triggered_event_key,
                'reason' => $movement->reason,
                'created_at' => $movement->created_at->toIso8601String(),
            ],
        ];
    }
}
