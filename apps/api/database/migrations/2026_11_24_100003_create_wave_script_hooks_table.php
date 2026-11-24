<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * PROD-DATA RISK: NONE for existing data. One NEW, EMPTY table; no row is read, written or backfilled; no existing table is
 * altered. Its foreign keys take SHARE ROW EXCLUSIVE locks on `agencies`, `waves` and `wave_scripts` (all PL tables or the
 * small `agencies` table) for the length of the migration transaction. `SET LOCAL lock_timeout = '5s'`.
 *
 * PL-3.2a (M4): the hooks of a script, in order. The hook LETTER (A, B, C ...) is not stored: it is the position, so a
 * delete, a duplicate or a reorder never renumbers a column. `shot_type` is one of the ten codes of `ShotType` (the CHECK is
 * the enum's values; the parity test keeps them equal). The language texts live in `wave_script_hook_texts`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }

        Schema::create('wave_script_hooks', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->unsignedBigInteger('agency_id');
            $table->foreign('agency_id')->references('id')->on('agencies')->restrictOnDelete();

            $table->unsignedBigInteger('wave_id');
            $table->foreign('wave_id')->references('id')->on('waves')->restrictOnDelete();

            $table->unsignedBigInteger('script_id');
            $table->foreign(['wave_id', 'script_id'], 'fk_wave_script_hooks_script')
                ->references(['wave_id', 'id'])->on('wave_scripts')->restrictOnDelete();

            $table->unsignedSmallInteger('position');
            $table->string('shot_type', 24)->nullable();
            $table->text('visual_description')->nullable();
            $table->unsignedInteger('revision')->default(1);

            $table->timestamps();

            $table->unique(['script_id', 'id'], 'unique_wave_script_hooks_script_id_id');
            $table->index(['script_id', 'position'], 'idx_wave_script_hooks_script_position');
            $table->index('agency_id', 'idx_wave_script_hooks_agency_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE wave_script_hooks ADD CONSTRAINT chk_wave_script_hooks_shot_type CHECK (shot_type IS NULL OR shot_type IN ('wide', 'medium', 'close_up', 'detail', 'pov', 'over_the_shoulder', 'top_down', 'screen_recording', 'reaction', 'creator_to_camera'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wave_script_hooks');
    }
};
