<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * PROD-DATA RISK: NONE for existing data. One NEW, EMPTY table; no existing table is altered. Foreign keys take SHARE ROW
 * EXCLUSIVE locks on the referenced tables (docs/reviews/pl-2-3-plan.md §8).
 *
 * PL-2.3 (M2): the USP chips of a concept. A new, empty table; both keys are composite (same wave, same market). */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }

        Schema::create('wave_concept_usps', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('agency_id');
            $table->foreign('agency_id')->references('id')->on('agencies')->restrictOnDelete();

            $table->unsignedBigInteger('wave_id');
            $table->foreign('wave_id')->references('id')->on('waves')->restrictOnDelete();

            $table->char('market', 2);
            $table->unsignedBigInteger('concept_id');
            $table->foreign(['wave_id', 'market', 'concept_id'], 'fk_wave_concept_usps_concept')
                ->references(['wave_id', 'market', 'id'])->on('wave_concepts')->restrictOnDelete();

            $table->unsignedBigInteger('usp_id');
            $table->foreign(['wave_id', 'usp_id'], 'fk_wave_concept_usps_usp')
                ->references(['wave_id', 'id'])->on('wave_usps')->restrictOnDelete();

            $table->unsignedSmallInteger('position');
            $table->timestamps();

            $table->unique(['concept_id', 'usp_id'], 'unique_wave_concept_usps_concept_usp');
            $table->index('usp_id', 'idx_wave_concept_usps_usp_id');
            $table->index('agency_id', 'idx_wave_concept_usps_agency_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wave_concept_usps');
    }
};
