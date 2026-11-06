<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * AH-119 (Messages v2), A4 — a brand's colour, stored as a PALETTE KEY (`violet`, `green`, ...), never
 * as free hex; the web maps keys to theme colours. ONE nullable string, no default, no index:
 * metadata-only on Postgres, no existing brand row is read or rewritten. Existing brands stay null and
 * the web derives a stable key from the brand ulid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table): void {
            $table->string('color', 16)->nullable()->after('logo_path');
        });
    }

    /** Honest inverse; the one thing lost is every colour an agency chose. */
    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table): void {
            $table->dropColumn('color');
        });
    }
};
