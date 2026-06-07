<?php

declare(strict_types=1);

namespace App\Modules\Boards\Services;

use App\Core\Tenancy\BelongsToAgencyScope;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Boards\Enums\BoardAutomationActionType;
use App\Modules\Boards\Models\Board;
use App\Modules\Boards\Models\BoardAutomation;
use App\Modules\Boards\Models\BoardCard;
use App\Modules\Boards\Models\BoardColumn;
use App\Modules\Boards\Support\BoardDefaults;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reset-to-defaults — the destructive re-seed (Sprint 12 Chunk 3, D-7/D-8/D-9).
 * Per docs/10-BOARD-AUTOMATION.md §8.1.
 *
 * The never-clobber {@see BoardProvisioningService} cannot be reused — reset
 * needs its own DESTRUCTIVE path. The whole thing runs in ONE
 * {@see DB::transaction} so a mid-reset failure rolls back to the ORIGINAL
 * custom board (never a half-reset both-old-and-new-columns state).
 *
 * ⚠ Ordering (D-7, Option A — re-automate BEFORE re-home). The literal kickoff
 * order (seed → re-home → delete → re-automate) is WRONG: placement
 * ({@see BoardCardService::resolveColumnForState()}) resolves a card's column via
 * the automations, so re-homing while the OLD automations still point at the
 * about-to-be-deleted custom columns would land cards on those columns →
 * RESTRICT violation + orphans. The correct order:
 *
 *   1. Seed the fresh default columns alongside the existing custom ones (both
 *      sets coexist mid-transaction; positions continue past the current max to
 *      avoid any transient collision).
 *   2. Delete the old automations + seed fresh defaults keyed to the FRESH
 *      column ids (the (board_id, event_key) UNIQUE forbids old+new coexistence,
 *      and reset keys to the fresh ids directly — never a board-wide name pluck
 *      that could collide if a custom column shares a default name).
 *   3. Re-home every card onto a fresh default column by its assignment's
 *      CURRENT STATE — reuse the §6.1 placement primitive, resolving against the
 *      FRESH columns + automations ONLY. A bulk `column_id` update grouped by
 *      target — NOT per-card {@see BoardCardMoveService} movements: a reset is
 *      conceptually ONE operation; routing ~100 cards through the manual-move
 *      path would fabricate 100 fake "manual move" audit + movement rows (D-8).
 *   4. Delete the old custom columns (now empty → the `board_cards.column_id`
 *      RESTRICT is satisfied) and renumber the fresh columns to 1..N.
 *   5. Write ONE `board.reset` audit row — the trail for the reset itself
 *      (movement history survives untouched: the bulk re-home writes no movement
 *      rows, and prior movements' SET-NULL column refs survive the delete, D-8).
 *
 * The fresh automations point at the fresh columns, never dangling (D-9 — the
 * §14.4 broken-automation state never arises from a reset).
 */
final class BoardResetService
{
    public function __construct(private readonly BoardCardService $cards) {}

    public function reset(Board $board, User $actor): Board
    {
        DB::transaction(function () use ($board, $actor): void {
            $oldColumnIds = $board->columns()->pluck('id')->all();

            // (1) Seed the fresh default columns alongside the existing custom ones.
            $freshColumns = $this->seedFreshColumns($board);

            // (2) Replace automations: delete old, seed fresh keyed to fresh ids.
            $board->automations()->delete();
            $freshAutomations = $this->seedFreshAutomations($board, $freshColumns);

            // (3) Re-home every card onto a fresh column by current state.
            $this->rehomeCards($board, $freshColumns, $freshAutomations);

            // (4) Delete the now-empty old columns + renumber the fresh set 1..N.
            BoardColumn::query()->whereIn('id', $oldColumnIds)->delete();
            $this->renumber($freshColumns);

            // (5) One audit row for the whole destructive operation (D-7).
            Audit::log(
                action: AuditAction::BoardReset,
                actor: $actor,
                subject: $board,
                metadata: [
                    'campaign_id' => $board->campaign_id,
                    'columns_removed' => count($oldColumnIds),
                    'columns_seeded' => $freshColumns->count(),
                ],
            );
        });

        return $board->refresh();
    }

    /**
     * Seed the 7 default columns at positions continuing past the current max
     * (so they coexist with the custom columns without a transient position
     * collision). Returns the freshly-created columns in seed order.
     *
     * @return Collection<int, BoardColumn>
     */
    private function seedFreshColumns(Board $board): Collection
    {
        $position = (int) $board->columns()->max('position');

        /** @var Collection<int, BoardColumn> $created */
        $created = new Collection;

        foreach (BoardDefaults::columns() as $column) {
            $created->push(BoardColumn::query()->create([
                'board_id' => $board->id,
                'agency_id' => $board->agency_id,
                'name' => $column['name'],
                'position' => ++$position,
                'color_token' => $column['color_token'],
                'is_terminal_success' => $column['is_terminal_success'],
                'is_terminal_failure' => $column['is_terminal_failure'],
            ]));
        }

        return $created;
    }

    /**
     * Seed the default automations, mapping each target column NAME to the
     * FRESH column's id (keyed off the just-created set, never a board-wide
     * pluck that could pick a custom column sharing a default name).
     *
     * @param  Collection<int, BoardColumn>  $freshColumns
     * @return Collection<int, BoardAutomation>
     */
    private function seedFreshAutomations(Board $board, Collection $freshColumns): Collection
    {
        $idsByName = $freshColumns->pluck('id', 'name');

        /** @var Collection<int, BoardAutomation> $created */
        $created = new Collection;

        foreach (BoardDefaults::automations() as $automation) {
            $created->push($board->automations()->create([
                'event_key' => $automation['event_key'],
                'action_type' => BoardAutomationActionType::MoveToColumn,
                'target_column_id' => $idsByName[$automation['target_column_name']] ?? null,
                'condition' => null,
                'is_enabled' => true,
            ]));
        }

        return $created;
    }

    /**
     * Re-home every card onto a fresh column by its assignment's current state.
     * A bulk `column_id` update grouped by target — NO movement rows (D-8).
     *
     * @param  Collection<int, BoardColumn>  $freshColumns
     * @param  Collection<int, BoardAutomation>  $freshAutomations
     */
    private function rehomeCards(Board $board, Collection $freshColumns, Collection $freshAutomations): void
    {
        // withTrashed so a card whose assignment is soft-deleted still resolves a
        // state; the scope bypass keeps the lookup context-independent.
        $cards = $board->cards()
            ->with(['assignment' => fn ($query) => $query
                ->withTrashed()
                ->withoutGlobalScope(BelongsToAgencyScope::class)])
            ->get();

        $cardIdsByTarget = [];

        foreach ($cards as $card) {
            $status = $card->assignment?->status;

            $targetId = $status !== null
                ? $this->cards->resolveColumnForState($freshColumns, $freshAutomations, $status)
                : (int) $freshColumns->sortBy('position')->firstOrFail()->id;

            $cardIdsByTarget[$targetId][] = $card->id;
        }

        foreach ($cardIdsByTarget as $targetId => $cardIds) {
            BoardCard::query()->whereIn('id', $cardIds)->update(['column_id' => $targetId]);
        }
    }

    /**
     * Renumber the fresh columns to a clean 1..N (matching a fresh provision),
     * now that the old custom columns are gone.
     *
     * @param  Collection<int, BoardColumn>  $freshColumns
     */
    private function renumber(Collection $freshColumns): void
    {
        $position = 1;
        foreach ($freshColumns as $column) {
            $column->update(['position' => $position++]);
        }
    }
}
