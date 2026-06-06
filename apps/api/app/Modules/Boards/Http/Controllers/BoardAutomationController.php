<?php

declare(strict_types=1);

namespace App\Modules\Boards\Http\Controllers;

use App\Modules\Agencies\Models\Agency;
use App\Modules\Boards\Http\Controllers\Concerns\ResolvesBoardEntities;
use App\Modules\Boards\Http\Requests\UpdateBoardAutomationRequest;
use App\Modules\Boards\Http\Resources\BoardAutomationResource;
use App\Modules\Boards\Models\Board;
use App\Modules\Boards\Models\BoardAutomation;
use App\Modules\Boards\Services\BoardService;
use App\Modules\Campaigns\Models\Campaign;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Automation config (Sprint 12 Chunk 1, D-10) — list + enable/disable +
 * set-target-column. Per docs/10-BOARD-AUTOMATION.md §8 + §11. Board
 * CONFIGURATION → gated on `update` (admin + manager) for the write; the list
 * read needs only `view`.
 */
final class BoardAutomationController
{
    use ResolvesBoardEntities;

    public function __construct(private readonly BoardService $boards) {}

    public function index(Request $request, Agency $agency, Campaign $campaign): JsonResponse
    {
        $this->assertCampaignBelongsToAgency($campaign, $agency);
        Gate::authorize('view', $campaign);

        $board = $this->boards->forCampaign($campaign);

        return response()->json([
            'data' => BoardAutomationResource::collection(
                $board->automations()->with('targetColumn:id,ulid')->get(),
            )->resolve($request),
        ]);
    }

    public function update(UpdateBoardAutomationRequest $request, Agency $agency, Campaign $campaign, BoardAutomation $automation): BoardAutomationResource
    {
        $this->assertCampaignBelongsToAgency($campaign, $agency);
        Gate::authorize('update', $campaign);

        $board = $this->boards->ensureBoard($campaign);
        $this->assertAutomationOnBoard($automation, $board);

        $validated = $request->validated();
        $updates = [];

        if (array_key_exists('is_enabled', $validated)) {
            $updates['is_enabled'] = (bool) $validated['is_enabled'];
        }

        if (array_key_exists('action_type', $validated)) {
            $updates['action_type'] = $validated['action_type'];
        }

        // target_column_id: a column ULID (resolved + board-checked) or explicit
        // null ("No automation"). Absent key leaves it untouched.
        if (array_key_exists('target_column_id', $validated)) {
            $updates['target_column_id'] = $this->resolveTargetColumnId($validated['target_column_id'], $board);
        }

        $automation->fill($updates)->save();

        return new BoardAutomationResource($automation->load('targetColumn:id,ulid'));
    }

    private function resolveTargetColumnId(?string $ulid, Board $board): ?int
    {
        if ($ulid === null || $ulid === '') {
            return null;
        }

        $column = $board->columns()->where('ulid', $ulid)->first();
        if ($column === null) {
            abort(404);
        }

        return $column->id;
    }
}
