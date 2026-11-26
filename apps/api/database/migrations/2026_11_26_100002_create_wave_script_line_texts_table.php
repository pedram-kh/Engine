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
 * PL-3.2b (M8): the spoken text of a line, one row per language (`en` is English; a market language is another row). An empty
 * text is no row. A line has ONE spoken text per language, so there is no `field` column. `origin` is `manual` only until PL-9.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }

        Schema::create('wave_script_line_texts', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('agency_id');
            $table->foreign('agency_id')->references('id')->on('agencies')->restrictOnDelete();

            $table->unsignedBigInteger('wave_id');
            $table->foreign('wave_id')->references('id')->on('waves')->restrictOnDelete();

            $table->unsignedBigInteger('script_id');
            $table->foreign(['wave_id', 'script_id'], 'fk_wave_script_line_texts_script')
                ->references(['wave_id', 'id'])->on('wave_scripts')->restrictOnDelete();

            $table->unsignedBigInteger('line_id');
            $table->foreign(['script_id', 'line_id'], 'fk_wave_script_line_texts_line')
                ->references(['script_id', 'id'])->on('wave_script_lines')->restrictOnDelete();

            $table->char('language', 2);
            $table->text('body');
            $table->string('origin', 8)->default('manual');

            $table->timestamps();

            $table->unique(['line_id', 'language'], 'unique_wave_script_line_texts_line_language');
            $table->index('script_id', 'idx_wave_script_line_texts_script_id');
            $table->index('agency_id', 'idx_wave_script_line_texts_agency_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE wave_script_line_texts ADD CONSTRAINT chk_wave_script_line_texts_language CHECK (language ~ '^[a-z]{2}$')");
            DB::statement("ALTER TABLE wave_script_line_texts ADD CONSTRAINT chk_wave_script_line_texts_origin CHECK (origin = 'manual')");
            DB::statement('ALTER TABLE wave_script_line_texts ADD CONSTRAINT chk_wave_script_line_texts_body CHECK (char_length(trim(body)) > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wave_script_line_texts');
    }
};
