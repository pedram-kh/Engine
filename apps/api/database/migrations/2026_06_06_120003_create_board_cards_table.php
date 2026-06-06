<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * Creates the `board_cards` table — one card per CampaignAssignment (Sprint 12
 * Chunk 1, D-1/D-5). Per docs/03-DATA-MODEL.md §10.
 *
 * Tenancy (D-2): `board_cards` is directly addressable via route-model binding
 * (the move + movements endpoints), so it carries a denormalized `agency_id`
 * and uses BelongsToAgency — the D-2-authorized superset of the §10 column list,
 * mirroring message_threads.
 *
 * The `assignment_id` UNIQUE backs firstOrCreate idempotency across the two card
 * create sites (the CreateBoardCard invite listener + the lazy GET card-heal,
 * D-5) — a concurrent double-create collides on this unique rather than
 * duplicating.
 *
 * `position` exists per §10 but is INERT in P1: intra-column ordering is P2
 * (column present, unused) — the move path ignores it. Tracked in tech-debt.
 *
 * FK delete rules: `board_id` CASCADE; `column_id` RESTRICT (a column holding
 * cards cannot be deleted — this is the DB-level backstop for the column-delete
 * re-home safeguard, §14.3); `assignment_id` CASCADE (a card cannot outlive its
 * assignment); `agency_id` RESTRICT (the tenancy anchor).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('board_cards', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->unsignedBigInteger('board_id');
            $table->foreign('board_id')
                ->references('id')
                ->on('boards')
                ->cascadeOnDelete();

            $table->unsignedBigInteger('column_id');
            $table->foreign('column_id')
                ->references('id')
                ->on('board_columns')
                ->restrictOnDelete();

            // Denormalized tenant scope (D-2, BelongsToAgency), RESTRICT.
            $table->unsignedBigInteger('agency_id');
            $table->foreign('agency_id')
                ->references('id')
                ->on('agencies')
                ->restrictOnDelete();

            $table->unsignedBigInteger('assignment_id');
            $table->foreign('assignment_id')
                ->references('id')
                ->on('campaign_assignments')
                ->cascadeOnDelete();
            $table->unique('assignment_id', 'unique_board_cards_assignment');

            // Present per §10 but INERT in P1 (intra-column ordering is P2).
            $table->integer('position')->default(0);

            $table->timestamps();
        });

        Schema::table('board_cards', function (Blueprint $table): void {
            $table->index(['board_id', 'column_id'], 'idx_board_cards_board_column');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('board_cards');
    }
};
