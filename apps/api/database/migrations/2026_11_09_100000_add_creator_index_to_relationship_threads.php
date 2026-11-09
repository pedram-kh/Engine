<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * AH-115 L4 — the creator inbox reads `relationship_threads` by `creator_id` alone. The only
 * composite index (`unique_relationship_threads_pair`) leads with the agency, so the creator
 * filter could not seek on it (EXPLAIN on Postgres 16 showed a Seq Scan, Filter creator_id).
 * Additive, one plain btree on a small table: no rewrite, no backfill, no data command.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('relationship_threads', function (Blueprint $table): void {
            $table->index('creator_id', 'idx_relationship_threads_creator');
        });
    }

    /** Pure inverse: drops the index only; no data is touched. */
    public function down(): void
    {
        Schema::table('relationship_threads', function (Blueprint $table): void {
            $table->dropIndex('idx_relationship_threads_creator');
        });
    }
};
