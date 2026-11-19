<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * PROD-DATA RISK: NONE for existing data. One NULLABLE column with no default on `waves`: catalog-only (no table
 * rewrite), no row read, written or backfilled. It takes an ACCESS EXCLUSIVE lock on `waves` for the length of the
 * statement (milliseconds). `waves` is not in production yet (PL-1 is undeployed), so this runs in the deploy that
 * creates it. `lock_timeout` makes the catalog update give up quickly instead of queueing behind a long transaction.
 *
 * PL-2.1 (docs/reviews/pl-2-1-plan.md section 1.1): the audience title line above the USP grid, one per wave.
 *
 * down(): drops the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }

        Schema::table('waves', function (Blueprint $table): void {
            $table->string('usp_audience_title', 80)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('waves', function (Blueprint $table): void {
            $table->dropColumn('usp_audience_title');
        });
    }
};
