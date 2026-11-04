<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * AH-118 (campaign rules), item 1 — the per-campaign "only creators in the
 * listed regions can apply" switch. ONE additive boolean, `NOT NULL DEFAULT
 * false`. A defaulted-boolean add is metadata-only on Postgres 11+ (the AH-054
 * and AH-069 precedent), so no existing campaign row is read or rewritten and
 * every campaign that exists today reads OFF — which is today's behaviour: any
 * rostered creator may apply.
 *
 * Nothing reads the column until the `campaign_region_restriction_enabled`
 * flag is turned on. No index: it is only read on a campaign row already
 * loaded by ULID.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->boolean('restrict_to_listing_regions')->default(false)->after('listing_regions');
        });
    }

    /**
     * Honest inverse, with one thing it cannot restore: dropping the column
     * discards every campaign's restriction choice. Re-running up() restores the
     * STRUCTURE and resets every row to `false` (anyone rostered may apply),
     * which is the permissive direction but not the agency's data. Take a
     * snapshot first if this is ever rolled back against a populated database.
     */
    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropColumn('restrict_to_listing_regions');
        });
    }
};
