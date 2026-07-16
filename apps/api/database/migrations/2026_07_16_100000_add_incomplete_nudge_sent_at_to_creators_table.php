<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * Adds the once-only send-stamp for the incomplete-creator email nudge.
 *
 * `incomplete_nudge_sent_at` is the send record for the scheduled nudge
 * (creators:send-incomplete-nudges): a nullable timestamp that mirrors the
 * `agency_creator_relations.notification_sent_at` / `invitation_sent_at`
 * precedent. Null = never nudged (eligible once the other predicates hold);
 * non-null = already nudged (the once-only guarantee — the eligibility query
 * filters on IS NULL, so a second run sends nothing). There is deliberately
 * NO `became_incomplete_at` column (v2 territory if a second reminder ever
 * ships) — the anchor is `creators.created_at`, lossiness accepted and
 * recorded in docs/reviews/incomplete-creator-nudge-review.md (D3).
 *
 * No index this chunk (D7): a daily batch with status-indexed narrowing at
 * current scale. A volume-triggered index is logged in docs/tech-debt.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creators', function (Blueprint $table): void {
            $table->timestamp('incomplete_nudge_sent_at')->nullable()->after('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::table('creators', function (Blueprint $table): void {
            $table->dropColumn('incomplete_nudge_sent_at');
        });
    }
};
