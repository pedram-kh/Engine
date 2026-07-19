<?php

declare(strict_types=1);

use App\Modules\Creators\Http\Requests\UpdateProfileRequest;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Who appears in your content?" — an optional, self-declared multi-select
 * of companion kinds (partner, kids age-bands, pets, roommates, ...) that
 * regularly appear in a creator's content. Casting-purpose matching signal
 * for brands; NOT demographic data (deliberately no exact counts, no ages,
 * no partner attributes — see the AH-050 review file's GDPR purpose section).
 *
 * Values come from the fixed 11-key registry on
 * {@see UpdateProfileRequest::CONTENT_COMPANION_KEYS}.
 * Null AND [] both mean "undisclosed" — empty is never backfilled or defaulted.
 *
 * §5.40: additive-nullable only. No default, no backfill, no index; zero
 * existing rows are read or written by this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creators', function (Blueprint $table): void {
            $table->jsonb('content_companions')->nullable()->after('accent');
        });
    }

    public function down(): void
    {
        Schema::table('creators', function (Blueprint $table): void {
            $table->dropColumn('content_companions');
        });
    }
};
