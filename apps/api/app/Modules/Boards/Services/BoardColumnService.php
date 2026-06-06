<?php

declare(strict_types=1);

namespace App\Modules\Boards\Services;

use App\Modules\Boards\Models\Board;
use App\Modules\Boards\Models\BoardColumn;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Column lifecycle mechanics (Sprint 12 Chunk 1, D-10) — create / update /
 * reorder / delete-with-re-home. Per docs/10-BOARD-AUTOMATION.md §7.
 *
 * The terminal-uniqueness invariant (§7.5 — at most one terminal-success + one
 * terminal-failure per board; a second mark swaps the previous) is enforced
 * here. The column-delete safeguard (§14.3) re-homes non-empty cards through
 * {@see BoardCardMoveService} (manual movements, both trails) BEFORE deleting.
 *
 * Precondition guards (min-1 column, non-empty-requires-destination) live in the
 * controller so they map cleanly to 422s; this service is the mechanics.
 */
final class BoardColumnService
{
    public function __construct(private readonly BoardCardMoveService $moves) {}

    public function create(
        Board $board,
        string $name,
        string $colorToken,
        bool $isTerminalSuccess = false,
        bool $isTerminalFailure = false,
    ): BoardColumn {
        return DB::transaction(function () use ($board, $name, $colorToken, $isTerminalSuccess, $isTerminalFailure): BoardColumn {
            $nextPosition = (int) $board->columns()->max('position') + 1;

            $column = BoardColumn::query()->create([
                'board_id' => $board->id,
                'agency_id' => $board->agency_id,
                'name' => $name,
                'position' => $nextPosition,
                'color_token' => $colorToken,
                'is_terminal_success' => $isTerminalSuccess,
                'is_terminal_failure' => $isTerminalFailure,
            ]);

            $this->enforceTerminalUniqueness($board, $column);

            return $column;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(BoardColumn $column, array $attributes): BoardColumn
    {
        return DB::transaction(function () use ($column, $attributes): BoardColumn {
            $column->fill($attributes)->save();

            $board = $column->board;
            assert($board instanceof Board);
            $this->enforceTerminalUniqueness($board, $column);

            return $column->refresh();
        });
    }

    /**
     * Re-home a column's cards into $destination (as manual movements, both
     * trails) then delete the column. $destination is required by the caller
     * when the column is non-empty; an empty column passes null.
     */
    public function delete(BoardColumn $column, ?BoardColumn $destination, User $actor): void
    {
        DB::transaction(function () use ($column, $destination, $actor): void {
            if ($destination !== null) {
                $column->cards()->get()->each(
                    fn ($card) => $this->moves->move($card, $destination, $actor, null),
                );
            }

            $column->delete();
        });
    }

    /**
     * Reassign positions 1..n from the given ordered list of columns.
     *
     * @param  list<BoardColumn>  $orderedColumns
     */
    public function reorder(array $orderedColumns): void
    {
        DB::transaction(function () use ($orderedColumns): void {
            $position = 1;
            foreach ($orderedColumns as $column) {
                $column->update(['position' => $position++]);
            }
        });
    }

    /**
     * §7.5: a column may be marked terminal-success / terminal-failure, but at
     * most one of each per board. Marking a second swaps the previous (clears
     * the flag on all OTHER columns).
     */
    private function enforceTerminalUniqueness(Board $board, BoardColumn $column): void
    {
        if ($column->is_terminal_success) {
            $board->columns()
                ->whereKeyNot($column->id)
                ->where('is_terminal_success', true)
                ->update(['is_terminal_success' => false]);
        }

        if ($column->is_terminal_failure) {
            $board->columns()
                ->whereKeyNot($column->id)
                ->where('is_terminal_failure', true)
                ->update(['is_terminal_failure' => false]);
        }
    }
}
