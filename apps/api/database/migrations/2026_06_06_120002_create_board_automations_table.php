<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * Creates the `board_automations` table — the event-key → column-move mappings
 * of a board (Sprint 12 Chunk 1, D-1/D-6). Per docs/03-DATA-MODEL.md §10.
 *
 * Tenancy (D-2): automations scope transitively through `board_id` (no own
 * `agency_id`), matching the messaging design where messages scope through the
 * thread. They are never route-model-bound directly without their board.
 *
 * `event_key` is the AuditAction verb string (e.g. `assignment.draft_approved`)
 * — the SAME value `AssignmentTransitioned::eventKey()` returns (D-6). The
 * `(board_id, event_key)` UNIQUE keeps one automation per event per board.
 *
 * `action_type` is `move_to_column` | `none` (D-1); the listener honors it —
 * `none` automations are inert by design. `condition` (jsonb) is the P1
 * condition seam (§5.3) — present-but-unwritten this chunk (no default seeds a
 * condition; the evaluator returns true). `target_column_id` SET NULL on column
 * delete (§14.4: a deleted target leaves the automation broken-but-present for
 * the agency to fix).
 *
 * FK delete rules: `board_id` CASCADE; `target_column_id` SET NULL (nullable).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('board_automations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->unsignedBigInteger('board_id');
            $table->foreign('board_id')
                ->references('id')
                ->on('boards')
                ->cascadeOnDelete();

            $table->string('event_key', 64);
            $table->string('action_type', 16)->default('move_to_column');

            $table->unsignedBigInteger('target_column_id')->nullable();
            $table->foreign('target_column_id')
                ->references('id')
                ->on('board_columns')
                ->nullOnDelete();

            $table->jsonb('condition')->nullable();
            $table->boolean('is_enabled')->default(true);

            $table->timestamps();

            $table->unique(['board_id', 'event_key'], 'unique_board_automations_event');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('board_automations');
    }
};
