<?php

declare(strict_types=1);

namespace App\Modules\Boards\Http\Resources;

use App\Modules\Boards\Models\BoardAutomation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON representation of a board automation (docs/04-API-DESIGN.md §4 envelope).
 *
 * `target_column_id` is emitted as the target column's ULID (never the integer
 * id), or null when unmapped / the target column was deleted (§14.4).
 *
 * @mixin BoardAutomation
 */
final class BoardAutomationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $automation = $this->resource;
        assert($automation instanceof BoardAutomation);

        return [
            'id' => $automation->ulid,
            'type' => 'board_automations',
            'attributes' => [
                'event_key' => $automation->event_key,
                'action_type' => $automation->action_type->value,
                'is_enabled' => $automation->is_enabled,
                'condition' => $automation->condition,
                'target_column_id' => $automation->targetColumn?->ulid,
                'created_at' => $automation->created_at->toIso8601String(),
                'updated_at' => $automation->updated_at->toIso8601String(),
            ],
        ];
    }
}
