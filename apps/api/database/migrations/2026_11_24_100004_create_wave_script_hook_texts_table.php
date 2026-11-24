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
 * altered. Its foreign keys take SHARE ROW EXCLUSIVE locks on `agencies`, `waves`, `wave_scripts` and `wave_script_hooks`
 * (PL tables, or the small `agencies` table) for the length of the migration transaction. `SET LOCAL lock_timeout = '5s'`.
 *
 * PL-3.2a (M5): the spoken audio and the on-screen overlay of a hook, one row per field and language. English is
 * `language = 'en'`; a market language (PL-3.2b) is a second row. An empty overlay is no row. `origin` is `manual` only now;
 * PL-9 widens the CHECK.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }

        Schema::create('wave_script_hook_texts', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('agency_id');
            $table->foreign('agency_id')->references('id')->on('agencies')->restrictOnDelete();

            $table->unsignedBigInteger('wave_id');
            $table->foreign('wave_id')->references('id')->on('waves')->restrictOnDelete();

            $table->unsignedBigInteger('script_id');
            $table->foreign(['wave_id', 'script_id'], 'fk_wave_script_hook_texts_script')
                ->references(['wave_id', 'id'])->on('wave_scripts')->restrictOnDelete();

            $table->unsignedBigInteger('hook_id');
            $table->foreign(['script_id', 'hook_id'], 'fk_wave_script_hook_texts_hook')
                ->references(['script_id', 'id'])->on('wave_script_hooks')->restrictOnDelete();

            $table->string('field', 8);
            $table->char('language', 2);
            $table->text('body');
            $table->string('origin', 8)->default('manual');

            $table->timestamps();

            $table->unique(['hook_id', 'field', 'language'], 'unique_wave_script_hook_texts_hook_field_language');
            $table->index('script_id', 'idx_wave_script_hook_texts_script_id');
            $table->index('agency_id', 'idx_wave_script_hook_texts_agency_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE wave_script_hook_texts ADD CONSTRAINT chk_wave_script_hook_texts_field CHECK (field IN ('audio', 'overlay'))");
            DB::statement("ALTER TABLE wave_script_hook_texts ADD CONSTRAINT chk_wave_script_hook_texts_origin CHECK (origin = 'manual')");
            DB::statement('ALTER TABLE wave_script_hook_texts ADD CONSTRAINT chk_wave_script_hook_texts_body CHECK (char_length(trim(body)) > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wave_script_hook_texts');
    }
};
