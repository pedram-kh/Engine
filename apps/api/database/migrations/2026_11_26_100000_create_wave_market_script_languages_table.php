<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * PROD-DATA RISK: NONE for existing data. One NEW, EMPTY table; no row is read, written or backfilled; no existing table is
 * altered. Its foreign keys take SHARE ROW EXCLUSIVE locks on `agencies`, `waves` and `wave_markets` for the length of the
 * migration transaction (milliseconds on an empty child). `SET LOCAL lock_timeout = '5s'` makes a blocked migration fail fast.
 * docs/reviews/pl-3-2-migration-rehearsal.md ("Rehearsal 3").
 *
 * PL-3.2b (M6): the LOCAL languages a market's scripts are written in, set once in the workspace. English is always there and is
 * never stored (`language <> 'en'`). At most three per market is a service rule (a count under the wave lock), not a column.
 * Removing a language deletes a row of THIS table only: the texts written in it stay in the text tables, hidden, and return when
 * the language is added back (ruling 10).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }

        Schema::create('wave_market_script_languages', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('agency_id');
            $table->foreign('agency_id')->references('id')->on('agencies')->restrictOnDelete();

            $table->unsignedBigInteger('wave_id');
            $table->foreign('wave_id')->references('id')->on('waves')->restrictOnDelete();

            $table->unsignedBigInteger('market_id');
            $table->foreign(['wave_id', 'market_id'], 'fk_wave_market_script_languages_market')
                ->references(['wave_id', 'id'])->on('wave_markets')->restrictOnDelete();

            $table->char('language', 2);
            $table->unsignedSmallInteger('position');

            $table->timestamps();

            $table->unique(['market_id', 'language'], 'unique_wave_market_script_languages_market_language');
            $table->index(['market_id', 'position'], 'idx_wave_market_script_languages_market_position');
            $table->index('agency_id', 'idx_wave_market_script_languages_agency_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE wave_market_script_languages ADD CONSTRAINT chk_wave_market_script_languages_code CHECK (language ~ '^[a-z]{2}$' AND language <> 'en')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wave_market_script_languages');
    }
};
