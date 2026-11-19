<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * PROD-DATA RISK: NONE for existing data. One NEW, EMPTY table; no row is read, written or backfilled. Adding its two
 * foreign keys takes a SHARE ROW EXCLUSIVE lock on `agencies` and `waves` (ACCESS EXCLUSIVE only on the new table):
 * writes to those tables wait, reads do not, for the length of the migration transaction. Nothing reads or writes
 * `wave_usps` until `strategy_enabled` (and `waves_enabled`) is on and a person types a USP.
 *
 * PL-2.1 (docs/reviews/pl-2-1-plan.md section 1.1). One row per USP of a wave.
 *
 *   - `color_key` is STORED (the colour belongs to the card). `unique (wave_id, color_key)` is the belt for the cap of
 *     six: only six keys are valid, so a seventh row cannot exist.
 *   - `position` is the card number and the future matrix row order; the index is NON-unique so a reorder (positions
 *     rewritten 1..n) never collides mid-rewrite.
 *   - `unique (wave_id, id)` is the target of the composite foreign keys of `wave_persona_angles`.
 *   - Every foreign key is RESTRICT; deletes are explicit, audited service code. No user column (who did it is in the
 *     audit log), so `users` is not locked and the erasure registry has no user reference here.
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

        Schema::create('wave_usps', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->unsignedBigInteger('agency_id');
            $table->foreign('agency_id')->references('id')->on('agencies')->restrictOnDelete();

            $table->unsignedBigInteger('wave_id');
            $table->foreign('wave_id')->references('id')->on('waves')->restrictOnDelete();

            $table->string('color_key', 16);
            $table->unsignedSmallInteger('position');
            $table->string('title', 40);
            $table->text('explainer')->nullable();
            $table->text('creative_angle')->nullable();
            $table->text('copy_seed')->nullable();

            $table->timestamps();

            $table->unique(['wave_id', 'color_key'], 'unique_wave_usps_wave_color');
            $table->unique(['wave_id', 'id'], 'unique_wave_usps_wave_id_id');
            $table->index(['wave_id', 'position'], 'idx_wave_usps_wave_position');
            $table->index('agency_id', 'idx_wave_usps_agency_id');
        });
    }

    public function down(): void
    {
        // Lossy: destroys every USP row. A rollback tool only before the flag is armed.
        Schema::dropIfExists('wave_usps');
    }
};
