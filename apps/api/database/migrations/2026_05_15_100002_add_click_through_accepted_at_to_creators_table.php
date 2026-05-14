<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * Adds `creators.click_through_accepted_at` (migration #38, chunk-2
 * sub-step 7 per the plan refinement #2). Q-flag-off-2 = (a) in
 * the chunk-2 plan: when `contract_signing_enabled` is OFF, the
 * wizard's master-contract step routes to a click-through fallback
 * — the creator clicks a button accepting terms and the controller
 * stamps this timestamp. `signed_master_contract_id` stays NULL in
 * that path.
 *
 * Decision rationale (preserved here so a future sprint doesn't
 * re-litigate): a separate column (over a sentinel value on the
 * existing `signed_master_contract_id`) keeps the semantic of
 * "envelope mode" vs "click-through mode" cleanly readable from
 * the row alone, without joining the (future) contracts table or
 * inspecting magic IDs. Forensic clarity ranks above column-count
 * minimalism for compliance evidence (the click-through is a
 * legal artefact even if it's lighter weight than an envelope).
 *
 * Forward + backward tested per docs/08-DATABASE-EVOLUTION.md §
 * 7.1 + 7.2 (migration round-trip). The wizard's submit-validation
 * (sub-step 9) treats EITHER signed_master_contract_id OR
 * click_through_accepted_at as satisfying the contract step.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creators', function (Blueprint $table): void {
            $table->timestampTz('click_through_accepted_at')
                ->nullable()
                ->after('signed_master_contract_id');
        });
    }

    public function down(): void
    {
        Schema::table('creators', function (Blueprint $table): void {
            $table->dropColumn('click_through_accepted_at');
        });
    }
};
