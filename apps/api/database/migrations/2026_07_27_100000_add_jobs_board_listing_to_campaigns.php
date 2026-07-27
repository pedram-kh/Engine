<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * Jobs Board chunk 1+2 (AH-054, D1 + D2) — the agency-side listing fields plus
 * the visibility switch. Six additive columns, no rewrite of any existing row:
 * the boolean is `NOT NULL DEFAULT false` (a defaulted-boolean add is
 * metadata-only on Postgres 11+), every other column is nullable.
 *
 *   - `listed_on_jobs_board` (D1) — a NEW boolean, deliberately NOT the
 *     reserved P3 `is_marketplace_visible`. Roster-jobs ("the creators you are
 *     connected to see your jobs") and the P3 marketplace ("anyone can browse")
 *     are different concepts; repurposing the reserved column would poison its
 *     P3 meaning. The two-visibility-booleans cost is recorded in
 *     docs/tech-debt.md — reconcile when the P3 marketplace ships.
 *   - `listing_duration` / `listing_fee` — free text, the AH-034 `fee_per`
 *     precedent: agency-authored, untranslated, deliberately NOT enums
 *     ("4 weeks", "€300/video"). `listing_fee` is DISPLAY-ONLY copy — the
 *     binding offer stays per-assignment (`agreed_fee_*`), set at invite time.
 *   - `listing_languages` / `listing_regions` — jsonb arrays of codes. The
 *     languages are validated against the 24-EU set (the documented three-set
 *     locale architecture: a campaign's production language follows the
 *     operating markets); the regions are ISO-3166-1 alpha-2 codes.
 *   - `listing_examples_url` — optional reference link.
 *
 * No index: the boolean is ~all-false and the table is small; the chunk-3 read
 * predicate (`Campaign::scopeListedOnJobsBoard`) is agency-scoped, which the
 * existing `idx_campaigns_agency_brand` already serves. A partial index is the
 * volume-triggered follow-up, logged in docs/tech-debt.md.
 *
 * Nothing reads `listed_on_jobs_board` in this chunk — it ships dark, default
 * false, so no existing campaign becomes visible anywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->boolean('listed_on_jobs_board')->default(false)->after('requires_per_campaign_contract');
            $table->string('listing_duration', 120)->nullable()->after('listed_on_jobs_board');
            $table->string('listing_fee', 120)->nullable()->after('listing_duration');
            $table->jsonb('listing_languages')->nullable()->after('listing_fee');
            $table->jsonb('listing_regions')->nullable()->after('listing_languages');
            $table->string('listing_examples_url', 2048)->nullable()->after('listing_regions');
        });
    }

    /**
     * Honest inverse, with one thing it cannot restore: dropping these columns
     * discards the agency-authored listing copy (duration / fee / languages /
     * regions / examples link) and every campaign's listed state. The structure
     * is restored by re-running up(); the CONTENT is gone. Take a snapshot
     * first if this is ever rolled back against a populated database.
     */
    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropColumn([
                'listed_on_jobs_board',
                'listing_duration',
                'listing_fee',
                'listing_languages',
                'listing_regions',
                'listing_examples_url',
            ]);
        });
    }
};
