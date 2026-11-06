<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk medium
 *
 * AH-119 (Messages v2), B3 — one expression GIN index per message table for the agency message
 * search. Postgres only (the creators migration precedent): SQLite has no tsvector and the search
 * falls back to a substring match there. Not a generated column: that would rewrite the table under
 * an exclusive lock. `CREATE INDEX` (not CONCURRENTLY) inside the migration transaction: a
 * write-blocking lock on the table for the duration of the build (about 7,000 and 4,000 rows at the
 * 2026-10-20 read: milliseconds to seconds; the deploy entry re-reads the size first). Schema only,
 * no row is changed.
 *
 * The expression must match the query's exactly (`to_tsvector('simple', coalesce(body, ''))`, no
 * stemming) or the planner will not use the index. The index does not enforce tenancy; the query
 * filters `agency_id` on the thread join.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("CREATE INDEX idx_messages_body_fts ON messages USING gin (to_tsvector('simple', coalesce(body, '')))");
        DB::statement("CREATE INDEX idx_relationship_messages_body_fts ON relationship_messages USING gin (to_tsvector('simple', coalesce(body, '')))");
    }

    /**
     * Honest inverse: drops the two indexes (a rebuild restores them; nothing is lost but the
     * search speed, and search itself degrades to a sequential scan until it is rebuilt).
     */
    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS idx_messages_body_fts');
        DB::statement('DROP INDEX IF EXISTS idx_relationship_messages_body_fts');
    }
};
