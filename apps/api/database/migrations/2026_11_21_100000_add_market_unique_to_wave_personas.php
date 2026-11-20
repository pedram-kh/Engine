<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * PROD-DATA RISK: NONE for existing data. One unique constraint on `wave_personas`; no row is read, written or
 * backfilled (PL-2.1 tables are not in production, and the table is empty until a person types a persona). It takes an
 * ACCESS EXCLUSIVE lock on `wave_personas` (and builds the index under a SHARE lock) for the length of the migration
 * transaction: reads and writes of that one table wait. `unique (wave_id, id)` already makes `id` unique, so the new
 * key can never be violated by existing rows.
 *
 * PL-2.2 (docs/reviews/pl-2-2-plan.md section 8, ruling Q5). `unique (wave_id, market, id)` is the target of the
 * messaging-matrix cell's foreign key `(wave_id, market, persona_id)`: "this persona is in this wave AND this market"
 * is true in the DATABASE, not only in code. A persona's market never changes (it is not an editable field).
 *
 * `SET LOCAL lock_timeout` as in the PL-1 migrations (PostgreSQL only). down() drops the key (its dependent foreign key
 * is dropped first by the cells migration's own down()).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }

        Schema::table('wave_personas', function (Blueprint $table): void {
            $table->unique(['wave_id', 'market', 'id'], 'unique_wave_personas_wave_market_id');
        });
    }

    public function down(): void
    {
        Schema::table('wave_personas', function (Blueprint $table): void {
            $table->dropUnique('unique_wave_personas_wave_market_id');
        });
    }
};
