<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Backfill for the "Cancelled / Rejected" board default (draft-reject wiring).
 * BoardDefaults only applies at provision/reset, so existing boards are
 * updated in place:
 *
 *   1. Default-named "Cancelled" columns are renamed to "Cancelled / Rejected"
 *      (an agency-renamed failure column is left untouched — the never-clobber
 *      provisioning rule).
 *   2. Each board gains an `assignment.draft_rejected` → failure-column
 *      automation when it doesn't already have one for that key. The target is
 *      the board's terminal-failure column; boards without one are skipped
 *      (the automation would be inert anyway).
 *
 * Down reverses only the rename; the added automations are harmless to keep
 * but are removed for symmetry.
 */
return new class extends Migration
{
    private const string EVENT_KEY = 'assignment.draft_rejected';

    public function up(): void
    {
        DB::table('board_columns')
            ->where('name', 'Cancelled')
            ->where('is_terminal_failure', true)
            ->update(['name' => 'Cancelled / Rejected', 'updated_at' => now()]);

        $boards = DB::table('boards')->pluck('id');

        foreach ($boards as $boardId) {
            $exists = DB::table('board_automations')
                ->where('board_id', $boardId)
                ->where('event_key', self::EVENT_KEY)
                ->exists();

            if ($exists) {
                continue;
            }

            $target = DB::table('board_columns')
                ->where('board_id', $boardId)
                ->where('is_terminal_failure', true)
                ->value('id');

            if ($target === null) {
                continue;
            }

            DB::table('board_automations')->insert([
                'ulid' => (string) Str::ulid(),
                'board_id' => $boardId,
                'event_key' => self::EVENT_KEY,
                'action_type' => 'move_to_column',
                'target_column_id' => $target,
                'condition' => null,
                'is_enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('board_columns')
            ->where('name', 'Cancelled / Rejected')
            ->where('is_terminal_failure', true)
            ->update(['name' => 'Cancelled', 'updated_at' => now()]);

        DB::table('board_automations')
            ->where('event_key', self::EVENT_KEY)
            ->delete();
    }
};
