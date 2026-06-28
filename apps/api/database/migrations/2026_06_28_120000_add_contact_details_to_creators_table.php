<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * Adds four OPTIONAL creator contact columns (ad-hoc AH-005): phone, whatsapp,
 * address_street, address_postal_code.
 *
 * PLAINTEXT, deliberately NOT encrypted like the tax-profile address blob
 * (creator_tax_profiles.address is encrypted:array). These are visible to
 * non-blacklisted connected agencies on the roster-detail surface, so an
 * encrypt-at-rest cast would force decrypt-at-read on every agency view for no
 * confidentiality gain over the rest of the agency-visible profile.
 *
 * The full mailing address composes from the EXISTING country_code + region
 * (region serves as the city/locality line — slightly loose, tracked as
 * tech-debt) plus these two new lines; no city/country column is duplicated.
 *
 * All four nullable — contact details are optional and partial entry is fine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creators', function (Blueprint $table): void {
            $table->string('phone', 32)->nullable()->after('region');
            $table->string('whatsapp', 32)->nullable()->after('phone');
            $table->string('address_street', 255)->nullable()->after('whatsapp');
            $table->string('address_postal_code', 20)->nullable()->after('address_street');
        });
    }

    public function down(): void
    {
        Schema::table('creators', function (Blueprint $table): void {
            $table->dropColumn(['phone', 'whatsapp', 'address_street', 'address_postal_code']);
        });
    }
};
