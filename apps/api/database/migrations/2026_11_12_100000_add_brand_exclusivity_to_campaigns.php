<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * AH-120 — brand exclusivity between campaigns. Schema only; no row is read,
 * rewritten or backfilled, and nothing reads either object until the
 * `campaign_brand_exclusivity_enabled` flag is turned on.
 *
 *   1. `campaigns.exclusivity_ends_on` — `date NULL`, no default, no index. A
 *      nullable add is metadata-only on Postgres. NULL means "no date": the
 *      block then ends at completion. Read only on a campaign row already
 *      loaded or joined by primary key, so it needs no index of its own.
 *
 *   2. `campaign_exclusive_brands` — NEW table, the campaign's list of brands
 *      its selected creators may not apply to. A pivot rather than a jsonb id
 *      list because the predicate runs in reverse ("who is selected on a
 *      campaign that blocks brand B"), which wants an index, an FK and a plain
 *      join, not a jsonb containment scan.
 *        - `campaign_id` CASCADE: the list cannot outlive its campaign.
 *        - `brand_id` RESTRICT: brands are only ever soft-deleted (archived),
 *          and an archived brand stays blocked; a hard delete must not silently
 *          lift a block.
 *        - `unique(campaign_id, brand_id)`: a brand is listed once per campaign.
 *        - `index(brand_id, campaign_id)`: the reverse lookup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->date('exclusivity_ends_on')->nullable()->after('restrict_to_listing_regions');
        });

        Schema::create('campaign_exclusive_brands', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('campaign_id');
            $table->foreign('campaign_id')
                ->references('id')
                ->on('campaigns')
                ->cascadeOnDelete();

            $table->unsignedBigInteger('brand_id');
            $table->foreign('brand_id')
                ->references('id')
                ->on('brands')
                ->restrictOnDelete();

            $table->timestamps();

            $table->unique(['campaign_id', 'brand_id'], 'unique_exclusive_brand_campaign_brand');
            $table->index(['brand_id', 'campaign_id'], 'idx_exclusive_brands_brand_campaign');
        });
    }

    /**
     * Structurally a true inverse; the CONTENT cannot be restored. Dropping
     * the table and the column discards every campaign's blocked-brand list and
     * end date. Re-running up() restores the structure with every campaign
     * reading "no exclusivity", the permissive direction but not the agency's
     * data. Snapshot first if this is ever rolled back against a populated
     * database.
     */
    public function down(): void
    {
        Schema::dropIfExists('campaign_exclusive_brands');

        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropColumn('exclusivity_ends_on');
        });
    }
};
