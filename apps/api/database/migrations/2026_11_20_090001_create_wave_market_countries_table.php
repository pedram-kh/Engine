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
 * Its foreign keys take a SHARE ROW EXCLUSIVE lock on `agencies`, `waves` and `wave_markets` (the last is new in the same
 * release); `SET LOCAL lock_timeout` gives up after 5 s.
 *
 * PL-2.4 (docs/reviews/pl-2-4-plan.md section 1.2, ruling M2). The countries of a market, one row each.
 *
 *   - `unique (wave_id, country)`: a country belongs to ONE market within a wave, in the DATABASE. The same country may
 *     be in markets of different waves. A child table (not a jsonb column) because only a row can carry this key on both
 *     PostgreSQL and the SQLite test database.
 *   - `(wave_id, market_id) -> wave_markets (wave_id, id)`: a country row cannot name a market of another wave.
 *   - `country` is two capital letters (ISO 3166-1 alpha-2); a request rule, as for creators (tech-debt row 50).
 *   - Every foreign key is RESTRICT; the service deletes a market's country rows before the market.
 *
 * down() is lossy and a rollback tool only before the flags are armed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }

        Schema::create('wave_market_countries', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('agency_id');
            $table->foreign('agency_id')->references('id')->on('agencies')->restrictOnDelete();

            $table->unsignedBigInteger('wave_id');
            $table->foreign('wave_id')->references('id')->on('waves')->restrictOnDelete();

            $table->unsignedBigInteger('market_id');
            $table->foreign(['wave_id', 'market_id'], 'fk_wave_market_countries_market')
                ->references(['wave_id', 'id'])->on('wave_markets')->restrictOnDelete();

            $table->char('country', 2);
            $table->timestamp('created_at')->nullable();

            $table->unique(['wave_id', 'country'], 'unique_wave_market_countries_wave_country');
            $table->index('market_id', 'idx_wave_market_countries_market_id');
            $table->index('agency_id', 'idx_wave_market_countries_agency_id');
        });
    }

    public function down(): void
    {
        // Lossy: destroys every country row. A rollback tool only before the flags are armed.
        Schema::dropIfExists('wave_market_countries');
    }
};
