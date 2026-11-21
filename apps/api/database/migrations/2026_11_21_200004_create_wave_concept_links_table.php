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
 * PL-2.3 (M4): reference links of a concept. The domain is derived on read; the server never fetches the URL. */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }

        Schema::create('wave_concept_links', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->unsignedBigInteger('agency_id');
            $table->foreign('agency_id')->references('id')->on('agencies')->restrictOnDelete();

            $table->unsignedBigInteger('wave_id');
            $table->foreign('wave_id')->references('id')->on('waves')->restrictOnDelete();

            $table->unsignedBigInteger('market_id');
            $table->unsignedBigInteger('concept_id');
            $table->foreign(['wave_id', 'market_id', 'concept_id'], 'fk_wave_concept_links_concept')
                ->references(['wave_id', 'market_id', 'id'])->on('wave_concepts')->restrictOnDelete();

            $table->string('url', 2048);
            $table->string('title', 120);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index('concept_id', 'idx_wave_concept_links_concept_id');
            $table->index('agency_id', 'idx_wave_concept_links_agency_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wave_concept_links');
    }
};
