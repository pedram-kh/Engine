<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * Sprint 8 Chunk 2 (D-12, correction #2) — wire the deferred
 * `creator_availability_blocks.assignment_id` foreign key.
 *
 * The column shipped in Sprint 3 (migration #10) as a bare nullable bigint
 * with NO FK, because `campaign_assignments` didn't exist yet (the FK was
 * explicitly deferred — see that migration's docblock). Sprint 7 couldn't add
 * it either; Chunk 1 created the target table without back-filling the
 * constraint. Chunk 2 — which creates the FIRST real consumer (the accept
 * auto-block listener) — adds it.
 *
 * `ON DELETE SET NULL` is the column's original documented intent: hard-
 * deleting a campaign assignment nulls the block's link rather than
 * cascade-deleting the (creator-owned) availability block. The block survives
 * the assignment, just losing its campaign attribution.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creator_availability_blocks', function (Blueprint $table): void {
            $table->foreign('assignment_id')
                ->references('id')
                ->on('campaign_assignments')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('creator_availability_blocks', function (Blueprint $table): void {
            $table->dropForeign(['assignment_id']);
        });
    }
};
