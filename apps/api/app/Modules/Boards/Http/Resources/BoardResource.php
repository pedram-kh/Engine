<?php

declare(strict_types=1);

namespace App\Modules\Boards\Http\Resources;

use App\Modules\Boards\Models\Board;
use App\Modules\Boards\Models\BoardAutomation;
use App\Modules\Boards\Models\BoardColumn;
use App\Modules\Boards\Support\BoardDefaults;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * JSON representation of a campaign board (docs/04-API-DESIGN.md §4 envelope).
 *
 * The full board payload (§10.3): the board + its columns, automations, and
 * cards in one response, so the Chunk 2 SPA renders the Kanban from a single
 * fetch (then polls this endpoint every 30s). The nested collections are
 * expected to be eager-loaded by the controller.
 *
 * AH-069 (D6) — a campaign that hands off at approval does not RENDER its
 * posting column. This is a render filter and never a deletion: the
 * `board_columns` row, its automations and any card sitting on it are all
 * untouched in the database, so flipping the toggle back restores the board
 * exactly as it was. {@see self::hiddenColumnIds()} for how the column is
 * identified.
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

        $hidden = $this->hiddenColumnIds($board);

        $columns = $hidden->isEmpty()
            ? $board->columns
            : $board->columns->reject(
                fn (BoardColumn $column): bool => $hidden->contains($column->id),
            )->values();

        // The automations go with it. A rule whose target column is not in the
        // payload would render as a rule pointing at nothing — the blank-target
        // display the plan set out to avoid.
        $automations = $hidden->isEmpty()
            ? $board->automations
            : $board->automations->reject(
                fn (BoardAutomation $automation): bool => $automation->target_column_id !== null
                    && $hidden->contains($automation->target_column_id),
            )->values();

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
            'columns' => BoardColumnResource::collection($columns)->resolve($request),
            'automations' => BoardAutomationResource::collection($automations)->resolve($request),
            'cards' => BoardCardResource::collection($board->cards)->resolve($request),
        ];
    }

    /**
     * The columns this board should not render (AH-069, D6).
     *
     * Empty for every campaign whose creators post the deliverable — which is
     * every campaign that exists today — so this method is a no-op on the
     * overwhelming majority of boards.
     *
     * For a hand-off campaign, a column is hidden when the posting family
     * ({@see BoardDefaults::postingFamilyEventKeys()}) targets it and NOTHING
     * ELSE does. Two consequences of writing the rule that way, both wanted:
     *
     *   - it survives a rename, because it never reads the column's name; and
     *   - it can never hide a column that is also somebody else's destination.
     *     "Approved" is targeted by the draft-approved verb as well as the
     *     resubmit verb, so it stays even though a resubmit is unreachable here.
     *
     * CARDS ARE NOT FILTERED, deliberately. A card can only be on a posting
     * column if it reached `posted`, which a hand-off campaign's assignment
     * cannot do (the machine refuses it) and which the flip-to-OFF refusal
     * prevents retroactively. Filtering cards would hide that invariant being
     * violated instead of surfacing it — and the SPA's `cardsByColumn` already
     * degrades safely by dropping cards with no visible column.
     *
     * @return Collection<int, int>
     */
    private function hiddenColumnIds(Board $board): Collection
    {
        $campaign = $board->campaign;

        if ($campaign === null || $campaign->creator_posts_content) {
            return collect();
        }

        $postingKeys = BoardDefaults::postingFamilyEventKeys();

        $targeted = $board->automations
            ->whereNotNull('target_column_id')
            ->groupBy('target_column_id');

        return $targeted
            ->filter(function (Collection $automations) use ($postingKeys): bool {
                $keys = $automations->pluck('event_key')->unique();

                return $keys->every(static fn (string $key): bool => in_array($key, $postingKeys, true));
            })
            ->keys()
            ->map(static fn (int|string $id): int => (int) $id)
            ->values();
    }
}
