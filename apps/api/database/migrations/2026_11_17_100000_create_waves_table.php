<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * PROD-DATA RISK: NONE. One NEW, EMPTY table; no existing table is altered and no row is read, written or
 * backfilled. Nothing reads or writes `waves` until the `waves_enabled` flag is turned on.
 *
 * PL-1.1 — a wave is one pass of strategy and scripts for a brand (docs/reviews/pl-1-1-plan.md §1.1).
 *
 *   - `campaign_id` is the OPTIONAL link to a campaign (nullable). One wave per campaign is held HERE, by the
 *     partial unique index `unique_waves_campaign_id` (`WHERE deleted_at IS NULL`, the
 *     `unique_brand_creator_blacklist` precedent): nulls never collide, and a soft-deleted wave frees its
 *     campaign. The "same brand" rule is a service rule, not a foreign key (it would need a new index on
 *     `campaigns`, which this migration does not touch).
 *   - Markets are NOT a column (PL-2.4): they are rows of `wave_markets` (a named group of countries), with their
 *     countries in `wave_market_countries`. This migration was edited in place; no PL migration had run outside local
 *     and CI (WORKING-PROCESS.md section 5).
 *   - `status` is a plain string column; the PHP enum is the constraint, so adding a case needs no migration.
 *   - Every foreign key is RESTRICT: nothing cascades into a wave.
 *
 * down() honesty: `dropIfExists` is the structural inverse and a LOSSY content inverse; every wave row is
 * destroyed. It is a rollback tool only before the flag is armed. Take a snapshot first once rows exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Addendum 2026-11-18: the foreign keys take a brief ShareRowExclusiveLock on each referenced live table, so
        // give up after 5 s instead of queueing behind a long transaction (PostgreSQL only).
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }

        Schema::create('waves', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->unsignedBigInteger('agency_id');
            $table->foreign('agency_id')->references('id')->on('agencies')->restrictOnDelete();

            $table->unsignedBigInteger('brand_id');
            $table->foreign('brand_id')->references('id')->on('brands')->restrictOnDelete();

            $table->unsignedBigInteger('campaign_id')->nullable();
            $table->foreign('campaign_id')->references('id')->on('campaigns')->restrictOnDelete();

            $table->string('name', 160);
            $table->date('go_live_on');
            $table->string('status', 16)->default('draft');

            $table->unsignedBigInteger('created_by_user_id');
            $table->foreign('created_by_user_id')->references('id')->on('users')->restrictOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['brand_id', 'updated_at'], 'idx_waves_brand_updated');
            $table->index('agency_id', 'idx_waves_agency_id');
        });

        // One live wave per campaign. A raw partial index: it runs on Postgres and on the SQLite test database.
        DB::statement(
            'CREATE UNIQUE INDEX unique_waves_campaign_id '
            .'ON waves (campaign_id) '
            .'WHERE deleted_at IS NULL',
        );
    }

    public function down(): void
    {
        // Lossy: destroys every wave row. A rollback tool only before the flag is armed.
        Schema::dropIfExists('waves');
    }
};
