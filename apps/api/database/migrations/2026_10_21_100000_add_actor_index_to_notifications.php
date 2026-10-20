<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * AH-104a Stage 4d: an index for the erasure's notification scrub.
 *
 * The scrub finds the rows other people received from the erased account with
 * `WHERE actor_user_id = ?`. `notifications` had indexes on the recipient and
 * on the subject but none on the actor, so that lookup read the whole table
 * (288,083 rows at the 2026-10-20 production read-back). One index, so it reads
 * the subject's own rows only.
 *
 * Postgres builds it CONCURRENTLY (docs/08-DATABASE-EVOLUTION.md §3.6): no lock
 * on a table the app writes to all day, hence no transaction. Additive; no data
 * is read or written; the app does not need it to run.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_notifications_actor ON notifications (actor_user_id)');

            return;
        }

        Schema::table('notifications', function ($table): void {
            $table->index('actor_user_id', 'idx_notifications_actor');
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_notifications_actor');

            return;
        }

        Schema::table('notifications', function ($table): void {
            $table->dropIndex('idx_notifications_actor');
        });
    }
};
