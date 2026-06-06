<?php

declare(strict_types=1);

namespace App\Modules\Boards\Services;

use App\Modules\Boards\Enums\BoardAutomationActionType;
use App\Modules\Boards\Models\Board;
use App\Modules\Boards\Models\BoardColumn;
use App\Modules\Boards\Support\BoardDefaults;
use Illuminate\Support\Facades\DB;

/**
 * Seeds a board's default columns + automations (Sprint 12 Chunk 1, D-3). The
 * single home for "what a default board is" — it reads {@see BoardDefaults}
 * (§3.1 columns + §3.2 automations).
 *
 * Idempotent (the test obligation): a second call is a no-op. Columns are seeded
 * ONLY when the board has none (so a later rename / reorder / delete is never
 * clobbered by a re-provision); automations are firstOrCreate'd on the
 * `(board_id, event_key)` UNIQUE, so existing automations are left untouched and
 * any newly-catalogued event key is added without duplicating.
 *
 * All board/column writes carry the board's `agency_id` explicitly (D-2) so
 * provisioning works in any tenant context (including the queued / no-context
 * paths that reach the lazy heal).
 */
final class BoardProvisioningService
{
    public function provisionDefaults(Board $board): Board
    {
        DB::transaction(function () use ($board): void {
            $this->seedColumns($board);
            $this->seedAutomations($board);
        });

        return $board;
    }

    private function seedColumns(Board $board): void
    {
        // Seed columns only when the board has none — never clobber agency edits.
        if ($board->columns()->exists()) {
            return;
        }

        $position = 1;
        foreach (BoardDefaults::columns() as $column) {
            BoardColumn::query()->create([
                'board_id' => $board->id,
                'agency_id' => $board->agency_id,
                'name' => $column['name'],
                'position' => $position++,
                'color_token' => $column['color_token'],
                'is_terminal_success' => $column['is_terminal_success'],
                'is_terminal_failure' => $column['is_terminal_failure'],
            ]);
        }
    }

    private function seedAutomations(Board $board): void
    {
        // Map column NAME → id for the freshly-seeded (or existing) columns.
        $columnIdsByName = $board->columns()
            ->get(['id', 'name'])
            ->pluck('id', 'name');

        foreach (BoardDefaults::automations() as $automation) {
            $targetColumnId = $columnIdsByName[$automation['target_column_name']] ?? null;

            // firstOrCreate on the (board_id, event_key) UNIQUE — idempotent, and
            // it never overwrites an agency's existing automation for the key.
            $board->automations()->firstOrCreate(
                ['event_key' => $automation['event_key']],
                [
                    'action_type' => BoardAutomationActionType::MoveToColumn,
                    'target_column_id' => $targetColumnId,
                    'condition' => null,
                    'is_enabled' => true,
                ],
            );
        }
    }
}
