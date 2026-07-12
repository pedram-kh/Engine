<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * Offer context on campaign assignments (invite-offer-details batch, explicit
 * fast-batch exception). Three additive nullable concerns, all set at INVITE
 * time and read-only thereafter:
 *
 *   - `fee_per` — free-text unit the fee applies to ("per script", "per
 *     Reel"). Deliberately NOT an enum: real-world units resist a fixed
 *     vocabulary, and the value is agency-authored content (untranslated —
 *     the campaign-name/description posture).
 *   - `offer_description` — free-text expectations from the agency.
 *   - `offer_attachment_*` — ONE optional brief file per invite (path on the
 *     `media` disk + display metadata). The repeatable multi-offer structure
 *     is deferred to the offers-array design (Item 2 of the batch).
 *
 * All columns nullable — every existing row and every attachment-less invite
 * is valid by omission. No index: read only via the already-indexed row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_assignments', function (Blueprint $table): void {
            $table->string('fee_per', 120)->nullable()->after('agreed_fee_currency');
            $table->text('offer_description')->nullable()->after('fee_per');
            $table->string('offer_attachment_path', 500)->nullable()->after('offer_description');
            $table->string('offer_attachment_name', 255)->nullable()->after('offer_attachment_path');
            $table->string('offer_attachment_mime', 120)->nullable()->after('offer_attachment_name');
            $table->bigInteger('offer_attachment_size_bytes')->nullable()->after('offer_attachment_mime');
        });
    }

    public function down(): void
    {
        Schema::table('campaign_assignments', function (Blueprint $table): void {
            $table->dropColumn([
                'fee_per',
                'offer_description',
                'offer_attachment_path',
                'offer_attachment_name',
                'offer_attachment_mime',
                'offer_attachment_size_bytes',
            ]);
        });
    }
};
