<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * PROD-DATA RISK: NONE for existing data. One extra UNIQUE key on `wave_creators` (a PL-3.1 table that no production database
 * has until the first merge of `pl`, so it is empty). No row is read, written or backfilled. ADD UNIQUE builds its index under a
 * SHARE lock and takes an ACCESS EXCLUSIVE lock on `wave_creators` for the length of the migration transaction (milliseconds on
 * an empty table). `SET LOCAL lock_timeout = '5s'`. docs/reviews/pl-3-2-migration-rehearsal.md has the measured locks.
 *
 * PL-3.2a (M1): the target of the four-column foreign key of `wave_scripts`
 * `(wave_id, market_id, wave_creator_id, creator_id)`. It is a superset of the primary key, so it is always unique; its only job
 * is to let the database say "a script points at a slot of its own wave, market AND creator".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }

        Schema::table('wave_creators', function (Blueprint $table): void {
            $table->unique(['wave_id', 'market_id', 'id', 'creator_id'], 'unique_wave_creators_wave_market_id_creator');
        });
    }

    public function down(): void
    {
        Schema::table('wave_creators', function (Blueprint $table): void {
            $table->dropUnique('unique_wave_creators_wave_market_id_creator');
        });
    }
};
