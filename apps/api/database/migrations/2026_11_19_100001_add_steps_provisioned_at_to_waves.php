<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * PROD-DATA RISK: NONE. One NULLABLE column with no default on `waves`: a catalog-only change in every
 * PostgreSQL version (no table rewrite), no row read, written or backfilled. Existing waves keep a null marker and
 * get their steps on first open, by application code behind the flag (docs/reviews/pl-1-3-plan.md section 3.1).
 * `lock_timeout` makes the catalog update give up quickly instead of queueing behind a long transaction.
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
            $table->timestamp('steps_provisioned_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('waves', function (Blueprint $table): void {
            $table->dropColumn('steps_provisioned_at');
        });
    }
};
