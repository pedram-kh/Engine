<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * Adds `is_discoverable` to `creators` — the creator-side opt-out flag for the
 * agency discovery surface (Sprint 6.6a, D-2).
 *
 * Default TRUE: every approved creator is discoverable today (the decision),
 * and the column future-proofs the GDPR opt-out (Sprint 6.6b+) without a later
 * schema change. The discovery gate is a WHITELIST —
 * `application_status = 'approved' AND is_discoverable = true` (+ the implicit
 * SoftDeletes global scope) — so mid-onboarding / pending / rejected creators
 * are excluded by construction (the KYC-approve-gate discipline). There is NO
 * write path to this column this chunk: discovery is read-only (D-9); the
 * opt-out toggle lands with the two-sided lifecycle.
 *
 * `is_active` is deliberately NOT used / NOT added — it does not exist on
 * `creators` (it is an `agencies` column; the kickoff's E2 correction).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creators', function (Blueprint $table): void {
            $table->boolean('is_discoverable')->default(true)->after('tier');
        });

        // Partial-ish composite index serving the discovery gate's hot path
        // (application_status = 'approved' AND is_discoverable = true). A plain
        // composite index works across both drivers; the existing
        // idx_creators_application_status stays for the roster's app-status reads.
        Schema::table('creators', function (Blueprint $table): void {
            $table->index(['application_status', 'is_discoverable'], 'idx_creators_discoverable');
        });
    }

    public function down(): void
    {
        Schema::table('creators', function (Blueprint $table): void {
            $table->dropIndex('idx_creators_discoverable');
            $table->dropColumn('is_discoverable');
        });
    }
};
