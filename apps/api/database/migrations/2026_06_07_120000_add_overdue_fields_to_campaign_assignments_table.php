<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * Sprint 12 Chunk 3 — the time-triggered overdue vertical (D-2 + D-4). Three
 * net-new nullable columns on `campaign_assignments`:
 *
 *   - `draft_due_at` (D-2) — the draft deadline, an EXACT mirror of the existing
 *     `posting_due_at` (nullable timestamp, indexed). Set on invite this chunk
 *     (backend-only; the FE invite-form control is deferred to tech-debt).
 *     Nullable means `draft_overdue` is capable at ship and inert until a
 *     deadline is set — the scan skips nulls.
 *   - `posting_overdue_flagged_at` / `draft_overdue_flagged_at` (D-4) — the
 *     explicit one-shot markers. The daily scan stamps the marker the first time
 *     it fires the matching overdue event and gates on `… IS NULL` before
 *     firing, so an overdue fires at most once per assignment per overdue type —
 *     even if a human drags the card out of the overdue column while still
 *     overdue (the engine's already-in-target no-op alone would re-fire on the
 *     next daily scan). P1 posture: a permanent one-shot; it does NOT reset if
 *     the deadline is later cleared/extended (the reset-on-un-overdue refinement
 *     is logged to tech-debt).
 *
 * `draft_due_at` gets its OWN index (mirroring `idx_assignments_dates` on
 * `posting_due_at`) rather than widening the existing single-column index — both
 * deadlines stay independently indexed and the scan's `… < now()` predicate is
 * index-served. The flagged-at markers are not indexed (the deadline index
 * already drives the scan; `flagged_at IS NULL` is a cheap residual filter).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_assignments', function (Blueprint $table): void {
            $table->timestamp('draft_due_at')->nullable()->after('posting_due_at');
            $table->timestamp('posting_overdue_flagged_at')->nullable()->after('verified_live_at');
            $table->timestamp('draft_overdue_flagged_at')->nullable()->after('posting_overdue_flagged_at');
        });

        Schema::table('campaign_assignments', function (Blueprint $table): void {
            $table->index('draft_due_at', 'idx_assignments_draft_due_at');
        });
    }

    public function down(): void
    {
        Schema::table('campaign_assignments', function (Blueprint $table): void {
            $table->dropIndex('idx_assignments_draft_due_at');
            $table->dropColumn(['draft_due_at', 'posting_overdue_flagged_at', 'draft_overdue_flagged_at']);
        });
    }
};
