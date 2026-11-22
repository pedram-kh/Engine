<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * @migration-risk low
 *
 * PROD-DATA RISK: NONE for existing data. PostgreSQL only: drops and re-adds two CHECK constraints on `wave_strategy_notes`
 * (a PL-2.5 table that no production database has: the first merge of `pl` creates it). No row is read, written or
 * backfilled. DROP/ADD CONSTRAINT takes an ACCESS EXCLUSIVE lock on `wave_strategy_notes` for the length of the migration
 * (an empty table). `SET LOCAL lock_timeout = '5s'`.
 *
 * PL-3.1 (M3): the internal notes thread gains a per-market `creators` step (the frame's "Notes (n)" button on step 5).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement('ALTER TABLE wave_strategy_notes DROP CONSTRAINT chk_wave_strategy_notes_step');
        DB::statement("ALTER TABLE wave_strategy_notes ADD CONSTRAINT chk_wave_strategy_notes_step CHECK (step IN ('usps', 'personas', 'matrix', 'concepts', 'creators'))");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement('ALTER TABLE wave_strategy_notes DROP CONSTRAINT chk_wave_strategy_notes_step');
        DB::statement("ALTER TABLE wave_strategy_notes ADD CONSTRAINT chk_wave_strategy_notes_step CHECK (step IN ('usps', 'personas', 'matrix', 'concepts'))");
    }
};
