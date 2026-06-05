<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * Bugfix — widen `campaign_drafts.review_status` (and the symmetric
 * `client_review_status`) from varchar(16) to varchar(32).
 *
 * The columns shipped at varchar(16) in migration #110000 (Sprint 9 Chunk 1),
 * sized against the only value Chunk 1 ever wrote: `pending` (7 chars). Chunk 2
 * (the agency review) introduced the `revision_requested` value — which is 18
 * characters and OVERFLOWS varchar(16). Postgres rejects the write with
 * SQLSTATE 22001 ("value too long"), so the entire review transaction rolls
 * back and "Request changes" silently fails (the assignment never leaves
 * `draft_submitted`). The suite missed it because tests run on SQLite, which
 * does not enforce varchar length; Postgres (dev/prod) does.
 *
 * 32 leaves headroom over the longest current value (18) without going
 * unbounded. `client_review_status` is widened in lockstep: it is column-only
 * today (P2 brand-portal review) but will store the same enum values.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_drafts', function (Blueprint $table): void {
            $table->string('review_status', 32)->default('pending')->change();
            $table->string('client_review_status', 32)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('campaign_drafts', function (Blueprint $table): void {
            $table->string('review_status', 16)->default('pending')->change();
            $table->string('client_review_status', 16)->nullable()->change();
        });
    }
};
