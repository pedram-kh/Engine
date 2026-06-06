<?php

declare(strict_types=1);

namespace App\Modules\Boards\Http\Resources;

use App\Modules\Boards\Models\BoardColumn;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON representation of a board column (docs/04-API-DESIGN.md §4 envelope).
 *
 * @mixin BoardColumn
 */
final class BoardColumnResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $column = $this->resource;
        assert($column instanceof BoardColumn);

        return [
            'id' => $column->ulid,
            'type' => 'board_columns',
            'attributes' => [
                'name' => $column->name,
                'position' => $column->position,
                'color_token' => $column->color_token,
                'is_terminal_success' => $column->is_terminal_success,
                'is_terminal_failure' => $column->is_terminal_failure,
                'card_count' => $column->cards_count ?? null,
                'created_at' => $column->created_at->toIso8601String(),
                'updated_at' => $column->updated_at->toIso8601String(),
            ],
        ];
    }
}
