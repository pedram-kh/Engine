<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * PROD-DATA RISK: NONE for existing data. One NEW, EMPTY table; no row is read, written or backfilled. Adding its
 * foreign keys takes a SHARE ROW EXCLUSIVE lock on `agencies` and `waves` (live tables) and on `wave_usps` and
 * `wave_personas` (new in the same release): writes to those tables wait, reads do not, for the length of the
 * migration transaction.
 *
 * PL-2.2 (docs/reviews/pl-2-2-plan.md section 1.1). One row per SET cell of the messaging matrix; "Not set" is no row.
 *
 *   - `unique (persona_id, usp_id)`: one cell per USP and persona.
 *   - `(wave_id, usp_id) -> wave_usps (wave_id, id)` and `(wave_id, market, persona_id) -> wave_personas (wave_id,
 *     market, id)`, both RESTRICT: the USP and the persona are in the SAME wave and the cell carries its persona's
 *     market, true in the database.
 *   - `level` is a plain string; the PHP enum is the constraint on every driver, plus a CHECK on PostgreSQL.
 *   - `source` (`manual` now) and `edited_at` (unused now) are what PL-9 needs; no user column (ruling Q11).
 *
 * `SET LOCAL lock_timeout` as in the PL-1 migrations (PostgreSQL only). down() is lossy and a rollback tool only before
 * the flag is armed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }

        Schema::create('wave_matrix_cells', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->unsignedBigInteger('agency_id');
            $table->foreign('agency_id')->references('id')->on('agencies')->restrictOnDelete();

            $table->unsignedBigInteger('wave_id');
            $table->foreign('wave_id')->references('id')->on('waves')->restrictOnDelete();

            $table->unsignedBigInteger('market_id');
            $table->unsignedBigInteger('persona_id');
            $table->unsignedBigInteger('usp_id');
            $table->foreign(['wave_id', 'market_id', 'persona_id'], 'fk_wave_matrix_cells_persona')
                ->references(['wave_id', 'market_id', 'id'])->on('wave_personas')->restrictOnDelete();
            $table->foreign(['wave_id', 'usp_id'], 'fk_wave_matrix_cells_usp')
                ->references(['wave_id', 'id'])->on('wave_usps')->restrictOnDelete();

            $table->string('level', 8);
            $table->string('rationale', 140)->nullable();
            $table->string('message_line', 140)->nullable();
            $table->string('source', 16)->default('manual');
            $table->timestamp('edited_at')->nullable();

            $table->timestamps();

            $table->unique(['persona_id', 'usp_id'], 'unique_wave_matrix_cells_persona_usp');
            $table->index(['wave_id', 'market_id'], 'idx_wave_matrix_cells_wave_market');
            $table->index('usp_id', 'idx_wave_matrix_cells_usp_id');
            $table->index('agency_id', 'idx_wave_matrix_cells_agency_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE wave_matrix_cells ADD CONSTRAINT chk_wave_matrix_cells_level CHECK (level IN ('priority', 'support', 'low'))");
        }
    }

    public function down(): void
    {
        // Lossy: destroys every cell row. A rollback tool only before the flag is armed.
        Schema::dropIfExists('wave_matrix_cells');
    }
};
