<?php

declare(strict_types=1);

namespace App\Modules\Boards\Http\Resources;

use App\Modules\Boards\Models\BoardCard;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON representation of a board card (docs/04-API-DESIGN.md §4 envelope).
 *
 * A card IS a CampaignAssignment (§4.1) — the card-face data (creator, status,
 * deliverables) is surfaced from the eager-loaded assignment so the Chunk 2 SPA
 * renders the card without a second fetch. `position` is present per §10 but
 * INERT in P1 (intra-column ordering is P2).
 *
 * @mixin BoardCard
 */
final class BoardCardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $card = $this->resource;
        assert($card instanceof BoardCard);

        $assignment = $card->assignment;
        $creator = $assignment?->creator;

        return [
            'id' => $card->ulid,
            'type' => 'board_cards',
            'attributes' => [
                'position' => $card->position,
                'created_at' => $card->created_at->toIso8601String(),
                'updated_at' => $card->updated_at->toIso8601String(),
            ],
            'relationships' => [
                'column' => [
                    'data' => ['id' => $card->column?->ulid, 'type' => 'board_columns'],
                ],
                'assignment' => [
                    'data' => $assignment === null ? null : [
                        'id' => $assignment->ulid,
                        'type' => 'campaign_assignments',
                        'status' => $assignment->status->value,
                        'deliverables' => $assignment->deliverables,
                        'posting_due_at' => $assignment->posting_due_at?->toIso8601String(),
                        'creator' => $creator === null ? null : [
                            'id' => $creator->ulid,
                            'display_name' => $creator->display_name,
                        ],
                    ],
                ],
            ],
        ];
    }
}
