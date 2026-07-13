<?php

declare(strict_types=1);

namespace App\Modules\Boards\Http\Controllers;

use App\Modules\Agencies\Models\Agency;
use App\Modules\Boards\Http\Controllers\Concerns\ResolvesBoardEntities;
use App\Modules\Boards\Http\Requests\MoveBoardCardRequest;
use App\Modules\Boards\Http\Resources\BoardCardMovementResource;
use App\Modules\Boards\Http\Resources\BoardCardResource;
use App\Modules\Boards\Models\Board;
use App\Modules\Boards\Models\BoardCard;
use App\Modules\Boards\Models\BoardColumn;
use App\Modules\Boards\Services\BoardCardMoveService;
use App\Modules\Boards\Services\BoardService;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Manual card move + movement history (Sprint 12 Chunk 1, D-8/D-9/D-10). Per
 * docs/10-BOARD-AUTOMATION.md §5.4 + §11 + §13.
 *
 * The MOVE is "executing the board" → gated on `invite` (admin + manager +
 * staff, the execute ability). It is a VISUALIZATION change only (§4.4): it goes
 * through {@see BoardCardMoveService}, which has NO path to the assignment state
 * machine — a manual move to "Paid" does NOT release payment. The movements
 * READ is gated on `view` (any member).
 */
final class BoardCardController
{
    use ResolvesBoardEntities;

    public function __construct(
        private readonly BoardService $boards,
        private readonly BoardCardMoveService $moves,
    ) {}

    public function move(MoveBoardCardRequest $request, Agency $agency, Campaign $campaign, BoardCard $card): BoardCardResource
    {
        $this->assertCampaignBelongsToAgency($campaign, $agency);
        Gate::authorize('invite', $campaign);

        $board = $this->boards->ensureBoard($campaign);
        $this->assertCardOnBoard($card, $board);

        $validated = $request->validated();
        $target = $this->resolveColumn($validated['target_column_id'], $board);

        /** @var User $actor */
        $actor = $request->user();
        $this->moves->move($card, $target, $actor, $validated['reason'] ?? null);

        return new BoardCardResource(
            $card->fresh([
                'column:id,ulid',
                // Keep the moved-card face in parity with the board GET select
                // (board-card facelift + re-offer chunk) — otherwise the card
                // loses its avatar / fee / decline-history on move.
                'assignment:id,ulid,status,previously_declined,deliverables,posting_due_at,agreed_fee_minor_units,agreed_fee_currency,fee_per,creator_id',
                'assignment.creator:id,ulid,display_name,avatar_path',
            ]) ?? $card,
        );
    }

    public function movements(Request $request, Agency $agency, Campaign $campaign, BoardCard $card): JsonResponse
    {
        $this->assertCampaignBelongsToAgency($campaign, $agency);
        Gate::authorize('view', $campaign);

        $board = $this->boards->ensureBoard($campaign);
        $this->assertCardOnBoard($card, $board);

        $movements = $card->movements()
            ->with(['fromColumn:id,ulid', 'toColumn:id,ulid'])
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => BoardCardMovementResource::collection($movements)->resolve($request),
        ]);
    }

    private function resolveColumn(string $ulid, Board $board): BoardColumn
    {
        $column = $board->columns()->where('ulid', $ulid)->first();
        if ($column === null) {
            abort(404);
        }

        return $column;
    }
}
