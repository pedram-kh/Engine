<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * PROD-DATA RISK: NONE for existing data. One NEW, EMPTY table; no row is read, written or backfilled. Adding its
 * foreign keys takes a SHARE ROW EXCLUSIVE lock on `agencies` and `waves` (live tables) and on `wave_usps` and
 * `wave_personas` (new in the same release, empty): writes to those wait, reads do not, for the length of the
 * migration transaction.
 *
 * PL-2.1 (docs/reviews/pl-2-1-plan.md section 1.3). One angle: a persona's one-line reason to care about a USP.
 *
 *   - Composite foreign keys `(wave_id, persona_id) -> wave_personas (wave_id, id)` and `(wave_id, usp_id) ->
 *     wave_usps (wave_id, id)`: "a USP and a persona must belong to the same wave" is true in the DATABASE, not only
 *     in code. Both RESTRICT: deleting a USP that an angle uses is an explicit, confirmed, audited service action.
 *   - `unique (persona_id, usp_id)`: one angle per USP per persona.
 *   - `origin` is a plain string (`manual` only in PL-2.1; the PHP enum is the constraint).
 *
 * `SET LOCAL lock_timeout` as in the PL-1 migrations (PostgreSQL only). down() is lossy and a rollback tool only
 * before the flag is armed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }

        Schema::create('wave_persona_angles', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->unsignedBigInteger('agency_id');
            $table->foreign('agency_id')->references('id')->on('agencies')->restrictOnDelete();

            $table->unsignedBigInteger('wave_id');
            $table->foreign('wave_id')->references('id')->on('waves')->restrictOnDelete();

            $table->unsignedBigInteger('persona_id');
            $table->unsignedBigInteger('usp_id');
            $table->foreign(['wave_id', 'persona_id'], 'fk_wave_persona_angles_persona')
                ->references(['wave_id', 'id'])->on('wave_personas')->restrictOnDelete();
            $table->foreign(['wave_id', 'usp_id'], 'fk_wave_persona_angles_usp')
                ->references(['wave_id', 'id'])->on('wave_usps')->restrictOnDelete();

            $table->string('explanation', 140);
            $table->string('origin', 16)->default('manual');

            $table->timestamps();

            $table->unique(['persona_id', 'usp_id'], 'unique_wave_persona_angles_persona_usp');
            $table->index('usp_id', 'idx_wave_persona_angles_usp_id');
            $table->index('agency_id', 'idx_wave_persona_angles_agency_id');
        });
    }

    public function down(): void
    {
        // Lossy: destroys every angle row. A rollback tool only before the flag is armed.
        Schema::dropIfExists('wave_persona_angles');
    }
};
