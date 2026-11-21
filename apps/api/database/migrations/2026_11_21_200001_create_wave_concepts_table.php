<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * PROD-DATA RISK: NONE for existing data. One NEW, EMPTY table; no row is read, written or backfilled; no existing table
 * is altered. Its foreign keys take SHARE ROW EXCLUSIVE locks on the referenced tables (docs/reviews/pl-2-3-plan.md §8).
 *
 * PL-2.3 (M1): the concepts of a market of a wave. A new, empty table; no existing table is altered. The persona key is
 * the composite (wave, market, persona) so a concept cannot name a persona of another market or wave. `status` is the
 * current status (the history is `wave_concept_decisions`); `origin` is the PL-9 hook (only `manual` exists).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }

        Schema::create('wave_concepts', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->unsignedBigInteger('agency_id');
            $table->foreign('agency_id')->references('id')->on('agencies')->restrictOnDelete();

            $table->unsignedBigInteger('wave_id');
            $table->foreign('wave_id')->references('id')->on('waves')->restrictOnDelete();

            $table->unsignedBigInteger('market_id');
            $table->unsignedBigInteger('persona_id');
            $table->foreign(['wave_id', 'market_id', 'persona_id'], 'fk_wave_concepts_persona')
                ->references(['wave_id', 'market_id', 'id'])->on('wave_personas')->restrictOnDelete();

            $table->unsignedSmallInteger('position');
            $table->string('title', 120);
            $table->string('funnel_stage', 4)->nullable();
            $table->string('funnel_note', 40)->nullable();
            $table->string('product', 160)->nullable();
            $table->string('angle', 300)->nullable();
            $table->string('primary_message', 300)->nullable();
            $table->string('hypothesis', 400)->nullable();
            $table->string('hook', 300)->nullable();
            $table->string('format_visual', 400)->nullable();
            $table->jsonb('key_points')->nullable();
            $table->string('payoff', 300)->nullable();
            $table->string('cta', 200)->nullable();
            $table->string('status', 20)->default('awaiting');
            $table->string('origin', 8)->default('manual');

            $table->timestamps();

            $table->unique(['wave_id', 'market_id', 'id'], 'unique_wave_concepts_wave_market_id');
            $table->index(['wave_id', 'market_id', 'position'], 'idx_wave_concepts_wave_market_position');
            $table->index('persona_id', 'idx_wave_concepts_persona_id');
            $table->index('agency_id', 'idx_wave_concepts_agency_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE wave_concepts ADD CONSTRAINT chk_wave_concepts_status CHECK (status IN ('awaiting', 'approved', 'changes_requested'))");
            DB::statement("ALTER TABLE wave_concepts ADD CONSTRAINT chk_wave_concepts_funnel CHECK (funnel_stage IS NULL OR funnel_stage IN ('tofu', 'mofu', 'bofu'))");
            DB::statement("ALTER TABLE wave_concepts ADD CONSTRAINT chk_wave_concepts_origin CHECK (origin IN ('manual'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wave_concepts');
    }
};
