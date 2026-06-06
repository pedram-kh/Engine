<?php

declare(strict_types=1);

namespace App\Modules\Boards\Http\Controllers;

use App\Core\Errors\ErrorResponse;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Boards\Http\Controllers\Concerns\ResolvesBoardEntities;
use App\Modules\Boards\Http\Requests\CreateBoardColumnRequest;
use App\Modules\Boards\Http\Requests\DeleteBoardColumnRequest;
use App\Modules\Boards\Http\Requests\ReorderBoardColumnsRequest;
use App\Modules\Boards\Http\Requests\UpdateBoardColumnRequest;
use App\Modules\Boards\Http\Resources\BoardColumnResource;
use App\Modules\Boards\Models\Board;
use App\Modules\Boards\Models\BoardColumn;
use App\Modules\Boards\Services\BoardColumnService;
use App\Modules\Boards\Services\BoardService;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Column CRUD + reorder (Sprint 12 Chunk 1, D-10). Per
 * docs/10-BOARD-AUTOMATION.md §7 + §11. Board CONFIGURATION → gated on `update`
 * (admin + manager), the campaign-settings precedent. Reset-to-defaults is a
 * Chunk 2 surface (Q3).
 */
final class BoardColumnController
{
    use ResolvesBoardEntities;

    public function __construct(
        private readonly BoardService $boards,
        private readonly BoardColumnService $columns,
    ) {}

    public function store(CreateBoardColumnRequest $request, Agency $agency, Campaign $campaign): JsonResponse
    {
        $board = $this->authorizeConfig($campaign, $agency);
        $validated = $request->validated();

        $column = $this->columns->create(
            board: $board,
            name: $validated['name'],
            colorToken: $validated['color_token'],
            isTerminalSuccess: (bool) ($validated['is_terminal_success'] ?? false),
            isTerminalFailure: (bool) ($validated['is_terminal_failure'] ?? false),
        );

        return (new BoardColumnResource($column))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateBoardColumnRequest $request, Agency $agency, Campaign $campaign, BoardColumn $column): BoardColumnResource
    {
        $board = $this->authorizeConfig($campaign, $agency);
        $this->assertColumnOnBoard($column, $board);

        $updated = $this->columns->update($column, $request->validated());

        return new BoardColumnResource($updated);
    }

    public function destroy(DeleteBoardColumnRequest $request, Agency $agency, Campaign $campaign, BoardColumn $column): Response|JsonResponse
    {
        $board = $this->authorizeConfig($campaign, $agency);
        $this->assertColumnOnBoard($column, $board);

        // §7.5: a board keeps at least one column.
        if ($board->columns()->count() <= 1) {
            return ErrorResponse::single(
                request: $request,
                status: Response::HTTP_UNPROCESSABLE_ENTITY,
                code: 'board.column.last_column',
                title: 'Cannot delete the last column',
                detail: 'A board must keep at least one column.',
            );
        }

        $destination = $this->resolveDestination($request, $board, $column);
        if ($column->cards()->exists() && $destination === null) {
            return ErrorResponse::single(
                request: $request,
                status: Response::HTTP_UNPROCESSABLE_ENTITY,
                code: 'board.column.destination_required',
                title: 'Destination column required',
                detail: 'This column has cards — choose a destination column to move them to before deleting.',
            );
        }

        /** @var User $actor */
        $actor = $request->user();
        $this->columns->delete($column, $destination, $actor);

        return response()->noContent();
    }

    public function reorder(ReorderBoardColumnsRequest $request, Agency $agency, Campaign $campaign): JsonResponse
    {
        $board = $this->authorizeConfig($campaign, $agency);

        /** @var list<string> $orderedUlids */
        $orderedUlids = $request->validated()['column_ids'];

        $boardColumns = $board->columns()->get();
        $byUlid = $boardColumns->keyBy('ulid');

        // The list must EXACTLY match the board's columns (no foreign ulids, no
        // omissions) — otherwise positions would be left inconsistent.
        $matches = count($orderedUlids) === $boardColumns->count()
            && collect($orderedUlids)->every(fn (string $ulid): bool => $byUlid->has($ulid));

        if (! $matches) {
            return ErrorResponse::single(
                request: $request,
                status: Response::HTTP_UNPROCESSABLE_ENTITY,
                code: 'board.column.reorder_mismatch',
                title: 'Invalid column order',
                detail: 'The reorder list must contain exactly the board\'s columns.',
            );
        }

        $ordered = array_map(function (string $ulid) use ($byUlid): BoardColumn {
            $column = $byUlid->get($ulid);
            assert($column instanceof BoardColumn);

            return $column;
        }, $orderedUlids);
        $this->columns->reorder($ordered);

        return response()->json([
            'data' => BoardColumnResource::collection(
                $board->columns()->withCount('cards')->get(),
            )->resolve($request),
        ]);
    }

    private function authorizeConfig(Campaign $campaign, Agency $agency): Board
    {
        $this->assertCampaignBelongsToAgency($campaign, $agency);
        Gate::authorize('update', $campaign);

        return $this->boards->ensureBoard($campaign);
    }

    private function resolveDestination(DeleteBoardColumnRequest $request, Board $board, BoardColumn $column): ?BoardColumn
    {
        $destinationUlid = $request->validated()['destination_column_id'] ?? null;
        if (! is_string($destinationUlid) || $destinationUlid === '') {
            return null;
        }

        $destination = $board->columns()->where('ulid', $destinationUlid)->first();

        // A missing / cross-board / self destination is rejected as absent.
        if ($destination === null || $destination->id === $column->id) {
            abort(404);
        }

        return $destination;
    }
}
