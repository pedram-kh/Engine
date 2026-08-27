<?php

declare(strict_types=1);

use App\Modules\Creators\Models\Creator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * The creator's date of birth (AH-088). Entered by the creator and by nobody
 * else; every other audience reads the DERIVED age, never this column — see
 * {@see Creator::age()} and the withholding pin in
 * tests/Feature/Modules/Creators/DateOfBirthWithholdingTest.php.
 *
 * A `date` (not `timestamp`): a birthday has no time-of-day, and the derived
 * age must not depend on one. No minimum-age rule anywhere in the stack —
 * recorded as offered-and-declined (AH-088 D8); the request rules carry only
 * sanity bounds.
 *
 * §5.40: additive-nullable only. No default, no backfill, no index; zero
 * existing rows are read or written by this migration. The one deploy-time
 * write is the separate `creators:recompute-completeness` run — DOB joins the
 * profile floor (D6), so the persisted score moves for creators who have not
 * filled it yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creators', function (Blueprint $table): void {
            $table->date('date_of_birth')->nullable()->after('region');
        });
    }

    public function down(): void
    {
        Schema::table('creators', function (Blueprint $table): void {
            $table->dropColumn('date_of_birth');
        });
    }
};
