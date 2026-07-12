<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * Decline-history marker (re-offer-after-decline chunk). When the agency
 * re-opens a DECLINED invitation with a fresh offer, the same assignment row
 * flips `declined → invited`; this durable boolean records that the row was
 * once declined so the agency Creators tab can show a "declined, then
 * re-invited" history tag even after the status has moved on.
 *
 * Additive, non-null with a false default — every existing row is a valid
 * "never declined-then-reoffered" by default. No index: read per already-
 * indexed row, never filtered on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_assignments', function (Blueprint $table): void {
            $table->boolean('previously_declined')->default(false)->after('responded_at');
        });
    }

    public function down(): void
    {
        Schema::table('campaign_assignments', function (Blueprint $table): void {
            $table->dropColumn('previously_declined');
        });
    }
};
