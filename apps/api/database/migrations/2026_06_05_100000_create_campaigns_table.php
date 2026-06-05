<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * Creates the `campaigns` table — Sprint 8 Chunk 1 (D-1). Per
 * docs/03-DATA-MODEL.md §7 (campaigns).
 *
 * A campaign belongs to exactly one agency and one brand (both RESTRICT —
 * a campaign anchors real money + assignments; you do not cascade-delete
 * the agency/brand out from under it). `created_by_user_id` is RESTRICT per
 * the data model (the creating member is part of the audit trail).
 *
 * Money is integer minor units (D-3): `budget_minor_units` (bigint) +
 * `budget_currency` (char3). One currency per campaign.
 *
 * `brief` is a structured jsonb blob (deliverables / do-donts / hashtags /
 * mentions / links / usage_rights / attachments) — NOT normalized tables.
 *
 * P3 marketplace columns (`is_marketplace_visible`, `marketplace_open_at`,
 * `marketplace_close_at`) ship column-only from P1 per the data model — no
 * behaviour this chunk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->unsignedBigInteger('agency_id');
            $table->foreign('agency_id')
                ->references('id')
                ->on('agencies')
                ->restrictOnDelete();

            $table->unsignedBigInteger('brand_id');
            $table->foreign('brand_id')
                ->references('id')
                ->on('brands')
                ->restrictOnDelete();

            $table->string('name', 255);
            $table->text('description')->nullable();

            // awareness / engagement / conversion / ugc / launch — varchar(32).
            $table->string('objective', 32);
            // draft / active / paused / completed / cancelled — varchar(16).
            $table->string('status', 16)->default('draft');

            // Money — integer minor units (D-3). Nullable so a draft can be
            // created before the budget is fixed; the create FormRequest
            // requires them at the API edge.
            $table->bigInteger('budget_minor_units')->nullable();
            $table->char('budget_currency', 3)->nullable();

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('posting_window_starts_at')->nullable();
            $table->timestamp('posting_window_ends_at')->nullable();

            // Structured brief blob (jsonb). Cast to array on the model.
            $table->jsonb('brief')->nullable();

            $table->integer('target_creator_count')->nullable();

            $table->unsignedBigInteger('created_by_user_id');
            $table->foreign('created_by_user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();

            $table->timestamp('published_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            // P3 marketplace columns — present from P1, column-only (no behaviour).
            $table->boolean('is_marketplace_visible')->default(false);
            $table->timestamp('marketplace_open_at')->nullable();
            $table->timestamp('marketplace_close_at')->nullable();

            $table->boolean('requires_per_campaign_contract')->default(false);

            $table->timestamps();
            $table->softDeletes();
        });

        // Per docs/03-DATA-MODEL.md §7 indexes.
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->index(['agency_id', 'brand_id'], 'idx_campaigns_agency_brand');
            $table->index('status', 'idx_campaigns_status');
            $table->index(['starts_at', 'ends_at'], 'idx_campaigns_dates');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};
