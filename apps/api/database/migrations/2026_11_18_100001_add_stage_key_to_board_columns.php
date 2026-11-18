<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * PROD-DATA RISK: LOW. One nullable column added to a live table, schema only: no row is read, written or
 * backfilled. `ADD COLUMN ... NULL` with no default is catalog-only on every PostgreSQL version. Existing rows
 * read NULL, which is what every column of a normal board keeps.
 *
 * PL-1.2 (docs/reviews/pl-1-2-plan.md section 8, M2). `board_columns.stage_key` is set only on the eight data
 * columns of a for-a-wave board. It is what the link lock and the acceptance rule read, so neither depends on a
 * column's name or position. `lock_timeout` as in M1.
 *
 * down(): drops the column; no information outside the wave boards is lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }

        Schema::table('board_columns', function (Blueprint $table): void {
            $table->string('stage_key', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('board_columns', function (Blueprint $table): void {
            $table->dropColumn('stage_key');
        });
    }
};
