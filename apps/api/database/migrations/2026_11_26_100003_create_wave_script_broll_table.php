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
 * altered. Its foreign keys take SHARE ROW EXCLUSIVE locks on `agencies`, `waves`, `wave_scripts` and `wave_script_lines`
 * (PL tables, or the small `agencies` table). `SET LOCAL lock_timeout = '5s'`. docs/reviews/pl-3-2-migration-rehearsal.md.
 *
 * PL-3.2b (M9): the B-roll rows of a line. B-roll belongs to a LINE (never to a hook). The code `{line number}_{n}` (the first
 * row of line 2 is `2_1`) is computed from the positions and is never stored, so it follows its line on a reorder with nothing to
 * rewrite. `shot_type` is one of the ten codes of `ShotType` (the CHECK is the enum's values; the parity test keeps them equal).
 * The description is English only (ruling 3). The 12-per-script cap is a service rule. `(script_id, id)` is unique so a later
 * upload slot can point at a shot by a composite key.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }

        Schema::create('wave_script_broll', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->unsignedBigInteger('agency_id');
            $table->foreign('agency_id')->references('id')->on('agencies')->restrictOnDelete();

            $table->unsignedBigInteger('wave_id');
            $table->foreign('wave_id')->references('id')->on('waves')->restrictOnDelete();

            $table->unsignedBigInteger('script_id');
            $table->foreign(['wave_id', 'script_id'], 'fk_wave_script_broll_script')
                ->references(['wave_id', 'id'])->on('wave_scripts')->restrictOnDelete();

            $table->unsignedBigInteger('line_id');
            $table->foreign(['script_id', 'line_id'], 'fk_wave_script_broll_line')
                ->references(['script_id', 'id'])->on('wave_script_lines')->restrictOnDelete();

            $table->unsignedSmallInteger('position');
            $table->string('shot_type', 24)->nullable();
            $table->text('description')->nullable();

            $table->timestamps();

            $table->unique(['script_id', 'id'], 'unique_wave_script_broll_script_id_id');
            $table->index(['line_id', 'position'], 'idx_wave_script_broll_line_position');
            $table->index('agency_id', 'idx_wave_script_broll_agency_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE wave_script_broll ADD CONSTRAINT chk_wave_script_broll_shot_type CHECK (shot_type IS NULL OR shot_type IN ('wide', 'medium', 'close_up', 'detail', 'pov', 'over_the_shoulder', 'top_down', 'screen_recording', 'reaction', 'creator_to_camera'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wave_script_broll');
    }
};
