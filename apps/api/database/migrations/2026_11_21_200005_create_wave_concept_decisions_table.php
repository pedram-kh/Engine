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
 * PL-2.3 (M5): the client's decisions on a concept, recorded by the agency. Append-only: a new decision is a new row; the
 * latest row by id is the current one. `recorded_by_user_id` is nullable (a client's own decision in PL-5 has no agency
 * user); `source` says who recorded it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }

        Schema::create('wave_concept_decisions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->unsignedBigInteger('agency_id');
            $table->foreign('agency_id')->references('id')->on('agencies')->restrictOnDelete();

            $table->unsignedBigInteger('wave_id');
            $table->foreign('wave_id')->references('id')->on('waves')->restrictOnDelete();

            $table->char('market', 2);
            $table->unsignedBigInteger('concept_id');
            $table->foreign(['wave_id', 'market', 'concept_id'], 'fk_wave_concept_decisions_concept')
                ->references(['wave_id', 'market', 'id'])->on('wave_concepts')->restrictOnDelete();

            $table->string('status', 20);
            $table->string('client_name', 80);
            $table->string('note', 500)->nullable();
            $table->string('comment', 1000)->nullable();
            $table->string('source', 16)->default('agency');

            $table->unsignedBigInteger('recorded_by_user_id')->nullable();
            $table->foreign('recorded_by_user_id')->references('id')->on('users')->nullOnDelete();

            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index(['concept_id', 'id'], 'idx_wave_concept_decisions_concept_id_id');
            $table->index('agency_id', 'idx_wave_concept_decisions_agency_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE wave_concept_decisions ADD CONSTRAINT chk_wave_concept_decisions_status CHECK (status IN ('approved', 'changes_requested'))");
            DB::statement("ALTER TABLE wave_concept_decisions ADD CONSTRAINT chk_wave_concept_decisions_comment_required CHECK (status <> 'changes_requested' OR comment IS NOT NULL)");
            DB::statement("ALTER TABLE wave_concept_decisions ADD CONSTRAINT chk_wave_concept_decisions_comment_only_changes CHECK (status = 'changes_requested' OR comment IS NULL)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wave_concept_decisions');
    }
};
