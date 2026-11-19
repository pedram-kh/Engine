<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * PROD-DATA RISK: NONE for existing data. One NEW, EMPTY table; no row is read, written or backfilled. Adding its two
 * foreign keys takes a SHARE ROW EXCLUSIVE lock on `agencies` and `waves` (ACCESS EXCLUSIVE only on the new table):
 * writes to those tables wait, reads do not, for the length of the migration transaction.
 *
 * PL-2.1 (docs/reviews/pl-2-1-plan.md section 1.2). One row per persona of one market of a wave.
 *
 *   - `market` is a code from `waves.markets`. There is NO markets table, so no foreign key: "the market belongs to the
 *     wave" is checked in service code under the wave-row lock (and a wave edit that removes a market with personas is
 *     refused, WaveMarketGuard).
 *   - `slot` (1..8) is the first free slot at creation; `unique (wave_id, market, slot)` is the belt for the cap of
 *     eight per market. `code` is the editable chip; `unique (wave_id, market, code)`.
 *   - `name` is an INVENTED persona name, not a person (erasure registry says so). No avatar column.
 *   - The four list columns are jsonb arrays of strings (limits in the request class).
 *   - `unique (wave_id, id)` is the target of the composite foreign key of `wave_persona_angles`.
 *   - Every foreign key is RESTRICT. No user column.
 *
 * `SET LOCAL lock_timeout` as in the PL-1 migrations (PostgreSQL only). down() is lossy and a rollback tool only
 * before the flag is armed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }

        Schema::create('wave_personas', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->unsignedBigInteger('agency_id');
            $table->foreign('agency_id')->references('id')->on('agencies')->restrictOnDelete();

            $table->unsignedBigInteger('wave_id');
            $table->foreign('wave_id')->references('id')->on('waves')->restrictOnDelete();

            $table->char('market', 2);
            $table->unsignedSmallInteger('slot');
            $table->string('code', 8);
            $table->string('title', 60);
            $table->string('name', 40);
            $table->string('pronoun', 8)->default('they');
            $table->text('summary')->nullable();
            $table->text('core_job')->nullable();
            $table->text('need')->nullable();
            $table->jsonb('drivers')->nullable();
            $table->jsonb('goals')->nullable();
            $table->jsonb('self_talk')->nullable();
            $table->jsonb('barriers')->nullable();

            $table->timestamps();

            $table->unique(['wave_id', 'market', 'slot'], 'unique_wave_personas_wave_market_slot');
            $table->unique(['wave_id', 'market', 'code'], 'unique_wave_personas_wave_market_code');
            $table->unique(['wave_id', 'id'], 'unique_wave_personas_wave_id_id');
            $table->index('agency_id', 'idx_wave_personas_agency_id');
        });
    }

    public function down(): void
    {
        // Lossy: destroys every persona row. A rollback tool only before the flag is armed.
        Schema::dropIfExists('wave_personas');
    }
};
