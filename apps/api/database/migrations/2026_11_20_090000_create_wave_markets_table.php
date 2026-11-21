<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * PROD-DATA RISK: NONE. One NEW, EMPTY table; no existing table is altered and no row is read, written or backfilled.
 * Its two foreign keys take a SHARE ROW EXCLUSIVE lock on `agencies` and `waves` for the length of the migration
 * transaction (writes to those tables wait, reads do not); `SET LOCAL lock_timeout` gives up after 5 s.
 *
 * PL-2.4 (docs/reviews/pl-2-4-plan.md section 1.1). A market of a wave is a NAMED GROUP of one or more countries (the
 * countries are in `wave_market_countries`). Personas, matrix cells, concepts and concept images belong to a market row.
 *
 *   - `name` is typed by the strategist (an agency-authored label, not a person). Unique within a wave, case-insensitive:
 *     a unique EXPRESSION index on `(wave_id, lower(name))`, which PostgreSQL and the SQLite test database both support.
 *   - `code` is the short code (2 to 6 characters of A-Z0-9, a service rule), the prefix of persona codes. Unique within
 *     a wave. It is editable, so it is never an identifier; `ulid` is.
 *   - `unique (wave_id, id)` is the parent key every composite foreign key points at, so a child row cannot name a market
 *     of another wave.
 *   - Every foreign key is RESTRICT. A market is hard-deleted by the service only when nothing hangs off it.
 *
 * Dated before the first table that references it (`wave_personas`). down() is lossy (drops the table) and a rollback
 * tool only before the flags are armed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }

        Schema::create('wave_markets', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->unsignedBigInteger('agency_id');
            $table->foreign('agency_id')->references('id')->on('agencies')->restrictOnDelete();

            $table->unsignedBigInteger('wave_id');
            $table->foreign('wave_id')->references('id')->on('waves')->restrictOnDelete();

            $table->string('name', 60);
            $table->string('code', 6);
            $table->unsignedSmallInteger('position');

            $table->timestamps();

            $table->unique(['wave_id', 'id'], 'unique_wave_markets_wave_id_id');
            $table->unique(['wave_id', 'code'], 'unique_wave_markets_wave_code');
            $table->index(['wave_id', 'position'], 'idx_wave_markets_wave_position');
            $table->index('agency_id', 'idx_wave_markets_agency_id');
        });

        // Case-insensitive name uniqueness within a wave (Q4). A raw expression index: it runs on PostgreSQL and SQLite.
        DB::statement('CREATE UNIQUE INDEX unique_wave_markets_wave_lower_name ON wave_markets (wave_id, lower(name))');
    }

    public function down(): void
    {
        // Lossy: destroys every market row. A rollback tool only before the flags are armed.
        Schema::dropIfExists('wave_markets');
    }
};
