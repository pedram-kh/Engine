<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * Creates the `creator_availability_blocks` table per docs/03-DATA-MODEL.md §5.
 * Sprint 3 Chunk 1 migration #10.
 *
 * TABLE ONLY. CRUD endpoints + UI ship in Sprint 5 (calendar). Chunk 1
 * ships the migration + model + Creator hasMany relationship so any
 * cross-cutting code (e.g. campaign assignment auto-blocks in Sprint 7)
 * has the schema available.
 *
 * Deferred FK: assignment_id references `campaign_assignments.id` which
 * doesn't ship until Sprint 7 (migration #19). Column is added without
 * an FK constraint here; Sprint 7 will add the FK once the target table
 * exists. Same pattern as creators.signed_master_contract_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creator_availability_blocks', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->unsignedBigInteger('creator_id');
            $table->foreign('creator_id')
                ->references('id')
                ->on('creators')
                ->cascadeOnDelete();

            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->boolean('is_all_day')->default(false);

            $table->string('kind', 16);
            $table->string('block_type', 8);
            $table->string('reason', 255)->nullable();

            // Deferred FK — see docblock.
            $table->unsignedBigInteger('assignment_id')->nullable();

            // P2 columns — present from P1 with nullable defaults so Phase 2
            // activation doesn't require expand/migrate/contract.
            $table->boolean('is_recurring')->default(false);
            $table->string('recurrence_rule', 255)->nullable();
            $table->string('external_calendar_id', 255)->nullable();
            $table->string('external_event_id', 255)->nullable();

            $table->timestamps();
        });

        Schema::table('creator_availability_blocks', function (Blueprint $table): void {
            $table->index(['creator_id', 'starts_at', 'ends_at'], 'idx_availability_creator_dates');
            $table->index(['creator_id', 'kind'], 'idx_availability_creator_kind');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creator_availability_blocks');
    }
};
