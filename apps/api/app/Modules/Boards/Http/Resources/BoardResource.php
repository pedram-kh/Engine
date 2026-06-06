<?php

declare(strict_types=1);

namespace App\Modules\Boards\Http\Resources;

use App\Modules\Boards\Models\Board;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON representation of a campaign board (docs/04-API-DESIGN.md §4 envelope).
 *
 * The full board payload (§10.3): the board + its columns, automations, and
 * cards in one response, so the Chunk 2 SPA renders the Kanban from a single
 * fetch (then polls this endpoint every 30s). The nested collections are
 * expected to be eager-loaded by the controller.
 *
 * @mixin Board
 */
final class BoardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $board = $this->resource;
        assert($board instanceof Board);

        return [
            'id' => $board->ulid,
            'type' => 'boards',
            'attributes' => [
                'created_at' => $board->created_at->toIso8601String(),
                'updated_at' => $board->updated_at->toIso8601String(),
            ],
            'relationships' => [
                'campaign' => [
                    'data' => ['id' => $board->campaign?->ulid, 'type' => 'campaigns'],
                ],
            ],
            'columns' => BoardColumnResource::collection($board->columns)->resolve($request),
            'automations' => BoardAutomationResource::collection($board->automations)->resolve($request),
            'cards' => BoardCardResource::collection($board->cards)->resolve($request),
        ];
    }
}
