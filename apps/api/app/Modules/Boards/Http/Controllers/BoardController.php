<?php

declare(strict_types=1);

namespace App\Modules\Boards\Http\Controllers;

use App\Modules\Agencies\Models\Agency;
use App\Modules\Boards\Http\Controllers\Concerns\ResolvesBoardEntities;
use App\Modules\Boards\Http\Resources\BoardResource;
use App\Modules\Boards\Services\BoardService;
use App\Modules\Campaigns\Models\Campaign;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * The campaign board read surface (Sprint 12 Chunk 1, D-10). Per
 * docs/10-BOARD-AUTOMATION.md §11.
 *
 *   GET …/campaigns/{campaign}/board — the full board (columns + automations +
 *   cards) in one payload (§10.3). LAZILY provisions the board + heals cards on
 *   first fetch (D-4), so this endpoint is also what brings an existing
 *   board-less campaign to life — no backfill migration. The Chunk 2 SPA polls
 *   this every 30s.
 */
final class BoardController
{
    use ResolvesBoardEntities;

    public function __construct(private readonly BoardService $boards) {}

    public function show(Request $request, Agency $agency, Campaign $campaign): JsonResponse
    {
        $this->assertCampaignBelongsToAgency($campaign, $agency);
        Gate::authorize('view', $campaign);

        $board = $this->boards->forCampaign($campaign);

        $board->load([
            'campaign:id,ulid',
            'columns' => fn ($q) => $q->withCount('cards'),
            'automations.targetColumn:id,ulid',
            'cards.column:id,ulid',
            'cards.assignment:id,ulid,status,deliverables,posting_due_at,creator_id',
            'cards.assignment.creator:id,ulid,display_name',
        ]);

        // Force 200: the board may have been lazily CREATED on this GET (D-4),
        // which would otherwise make JsonResource emit a 201 for a read.
        return (new BoardResource($board))->response()->setStatusCode(Response::HTTP_OK);
    }
}
