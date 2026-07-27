<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * Jobs Board chunk 3 (AH-056, D4) — when a campaign was listed on the jobs
 * board, for the card's honest recency chip.
 *
 * ⚠ This is the ONLY migration in the chunk that touches a table with existing
 * production rows. It is a single nullable column with no default and no
 * backfill — metadata-only on Postgres 11+, and no existing campaign row is
 * read or rewritten. The runtime write is separately narrow: `listed_at` is
 * stamped ONLY on a `false → true` transition of `listed_on_jobs_board`, i.e.
 * only when an agency user deliberately lists one campaign they own.
 *
 * Why a column at all, rather than mining the audit trail. AH-054's F3 does put
 * `listed_on_jobs_board` in the campaign audit snapshot, so the information
 * technically exists in `audit_logs` — but reading it for a per-card display
 * value would mean a creator-facing feed querying a forensic table and
 * JSON-digging `before`/`after` on every render. The alternative,
 * `updated_at`, would be actively DISHONEST: it moves on every unrelated
 * Settings save, so a campaign listed in March and typo-fixed today would
 * claim "Listed today". `created_at` is worse — it predates listing entirely.
 *
 * Ships in the SAME release as the board, so there is no null-backfill gap:
 * zero campaigns are listed at deploy (`listed_on_jobs_board` is
 * `default(false)` with nothing backfilled, and create cannot list), so every
 * campaign that will ever appear on a board is listed AFTER this column exists.
 * The card still degrades gracefully on a null (no chip), because a null is
 * reachable in principle — a campaign listed before this migration would have
 * one, and there is no such campaign today only by timing.
 *
 * DISPLAY METADATA ONLY. `Campaign::scopeListedOnJobsBoard()` remains the sole
 * visibility authority and never consults this column — pinned by a
 * break-revert-shaped assertion (review priority 5). No index: nothing filters
 * or sorts on it (the AH-035 `previously_declined` posture — "read per
 * already-indexed row, never filtered on").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->timestamp('listed_at')->nullable()->after('listing_examples_url');
        });
    }

    /**
     * Honest inverse, with one thing it cannot restore: dropping the column
     * discards when each listed campaign was listed. The structure comes back
     * by re-running up(); the timestamps do not, and they are not
     * reconstructible from `updated_at` (see the up() note). Nothing else
     * depends on the column — visibility is decided entirely by
     * `scopeListedOnJobsBoard()` — so a rollback degrades the recency chip and
     * changes no behaviour.
     */
    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropColumn('listed_at');
        });
    }
};
