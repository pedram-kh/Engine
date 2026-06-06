<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * Creates the `board_card_movements` table — the append-only log of every card
 * move (Sprint 12 Chunk 1, D-7/D-9). Per docs/03-DATA-MODEL.md §10.
 *
 * Tenancy (D-2): movements scope transitively through `card_id` (no own
 * `agency_id`), matching the messaging design.
 *
 * `triggered_by` is `event` | `user` (Q1 — the §10 schema note + §5.2 sketch +
 * §13 win over D-9's "automation" wording; automation moves carry `event`,
 * manual moves carry `user`). `triggered_event_key` holds the AuditAction verb
 * for event-driven moves (null for manual). `triggered_by_user_id` attributes a
 * manual move. `reason` is optional on manual moves (§ schema — NOT
 * requiresReason()).
 *
 * §10 drift reconciled (build note): `from_column_id` AND `to_column_id` are
 * both nullable + SET NULL on column delete. §10 drafted `to_column_id`
 * non-null, but the in-scope column-delete-with-history flow (§14.3) can leave
 * historical movements pointing at a now-deleted column; nulling the column ref
 * preserves the append-only movement row instead of blocking the delete. The
 * card↔movement spine (`card_id`) is the durable history anchor.
 *
 * FK delete rules: `card_id` CASCADE; `from_column_id` / `to_column_id` SET NULL
 * (history survives column deletion); `triggered_by_user_id` RESTRICT (the
 * messages.sender_user_id precedent — a manual mover is a real, retained user).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('board_card_movements', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('card_id');
            $table->foreign('card_id')
                ->references('id')
                ->on('board_cards')
                ->cascadeOnDelete();

            $table->unsignedBigInteger('from_column_id')->nullable();
            $table->foreign('from_column_id')
                ->references('id')
                ->on('board_columns')
                ->nullOnDelete();

            $table->unsignedBigInteger('to_column_id')->nullable();
            $table->foreign('to_column_id')
                ->references('id')
                ->on('board_columns')
                ->nullOnDelete();

            $table->string('triggered_by', 16);
            $table->string('triggered_event_key', 64)->nullable();

            $table->unsignedBigInteger('triggered_by_user_id')->nullable();
            $table->foreign('triggered_by_user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();

            $table->text('reason')->nullable();

            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::table('board_card_movements', function (Blueprint $table): void {
            $table->index(['card_id', 'created_at'], 'idx_board_card_movements_card_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('board_card_movements');
    }
};
