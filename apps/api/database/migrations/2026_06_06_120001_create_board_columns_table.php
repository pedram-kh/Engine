<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * Creates the `board_columns` table — the user-defined, ordered columns of a
 * board (Sprint 12 Chunk 1, D-1). Per docs/03-DATA-MODEL.md §10.
 *
 * Tenancy (D-2): `board_columns` is directly addressable via route-model
 * binding (the column CRUD endpoints), so it carries a denormalized `agency_id`
 * and uses BelongsToAgency for automatic scope enforcement on those direct
 * queries. This is a deliberate, D-2-authorized superset of the §10 column list
 * (which defers the tenancy denormalization to D-2) — mirroring the
 * message_threads.agency_id precedent.
 *
 * `color_token` stores the design-system status token in the §3.1 spelling
 * (`status-todefine`, `status-progress`, …); the Chunk 2 SPA maps `status-<x>`
 * to the `boardStatus` palette in packages/design-tokens.
 *
 * `is_terminal_success` / `is_terminal_failure` mark the success / failure
 * terminals (§7.5: at most one of each per board; the service swaps on a second
 * mark).
 *
 * FK delete rules: `board_id` CASCADE (columns die with their board);
 * `agency_id` RESTRICT (the tenancy anchor).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('board_columns', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->unsignedBigInteger('board_id');
            $table->foreign('board_id')
                ->references('id')
                ->on('boards')
                ->cascadeOnDelete();

            // Denormalized tenant scope (D-2, BelongsToAgency), RESTRICT.
            $table->unsignedBigInteger('agency_id');
            $table->foreign('agency_id')
                ->references('id')
                ->on('agencies')
                ->restrictOnDelete();

            $table->string('name', 64);
            $table->integer('position');
            $table->string('color_token', 32);
            $table->boolean('is_terminal_success')->default(false);
            $table->boolean('is_terminal_failure')->default(false);

            $table->timestamps();
        });

        Schema::table('board_columns', function (Blueprint $table): void {
            $table->index(['board_id', 'position'], 'idx_board_columns_board_position');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('board_columns');
    }
};
