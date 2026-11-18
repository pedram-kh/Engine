<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * PROD-DATA RISK: LOW. One column added to a live table, schema only: no row is read, written or backfilled.
 *
 * PL-1.2 (docs/reviews/pl-1-2-plan.md section 8, M1). `campaigns.campaign_type` is `normal` or `for_wave`, NOT
 * NULL, DEFAULT 'normal'. On PostgreSQL 11 and later `ADD COLUMN ... DEFAULT <constant>` is a catalog-only
 * change (no table rewrite), so every existing row reads `normal` without being touched. The statement takes
 * ACCESS EXCLUSIVE on `campaigns` for that catalog update only; `lock_timeout` makes it give up quickly instead
 * of queueing behind a long transaction and blocking every campaign query behind it.
 *
 * The PHP enum is the constraint (no CHECK), so a new case needs no migration. No index: nothing filters a
 * large set by type.
 *
 * down(): drops the column; the inverse is exact only while no row is `for_wave`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }

        Schema::table('campaigns', function (Blueprint $table): void {
            $table->string('campaign_type', 20)->default('normal');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropColumn('campaign_type');
        });
    }
};
