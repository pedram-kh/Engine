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
 * altered. Its foreign keys take SHARE ROW EXCLUSIVE locks on `agencies`, `waves`, `wave_scripts`, `wave_script_hooks`,
 * `wave_script_lines` and **`users`** (the uploader column; `users` is a busy production table). `SET LOCAL lock_timeout = '5s'`
 * makes a blocked migration fail fast instead of queueing every later write behind it. docs/reviews/pl-3-2-migration-rehearsal.md.
 *
 * PL-3.2b (M10): reference images of a hook or of a line (never of a script as a whole, never of a B-roll row): exactly ONE of
 * `hook_id` and `line_id` is set (a CHECK). `pending` is a reservation made at upload init (it counts against the limits until
 * `expires_at`); `ready` carries the stored object's own MIME and size. JPG, PNG, WebP and GIF. The 10-per-block and 300 MB
 * per-script limits are service rules (a reservation under the script row lock, checked again at verify).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }

        Schema::create('wave_script_images', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->unsignedBigInteger('agency_id');
            $table->foreign('agency_id')->references('id')->on('agencies')->restrictOnDelete();

            $table->unsignedBigInteger('wave_id');
            $table->foreign('wave_id')->references('id')->on('waves')->restrictOnDelete();

            $table->unsignedBigInteger('script_id');
            $table->foreign(['wave_id', 'script_id'], 'fk_wave_script_images_script')
                ->references(['wave_id', 'id'])->on('wave_scripts')->restrictOnDelete();

            // Exactly one owner. MATCH SIMPLE: a null column switches its composite check off, as intended.
            $table->unsignedBigInteger('hook_id')->nullable();
            $table->foreign(['script_id', 'hook_id'], 'fk_wave_script_images_hook')
                ->references(['script_id', 'id'])->on('wave_script_hooks')->restrictOnDelete();
            $table->unsignedBigInteger('line_id')->nullable();
            $table->foreign(['script_id', 'line_id'], 'fk_wave_script_images_line')
                ->references(['script_id', 'id'])->on('wave_script_lines')->restrictOnDelete();

            $table->unsignedInteger('position')->default(0);
            $table->string('storage_key', 512)->unique();
            $table->string('thumb_key', 512)->nullable()->unique();
            $table->string('original_filename', 255);
            $table->string('mime_type', 32)->nullable();
            $table->unsignedBigInteger('declared_bytes');
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('status', 8)->default('pending');
            $table->timestamp('expires_at')->nullable();

            $table->unsignedBigInteger('uploaded_by_user_id')->nullable();
            $table->foreign('uploaded_by_user_id')->references('id')->on('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['script_id', 'status'], 'idx_wave_script_images_script_status');
            $table->index(['hook_id', 'status'], 'idx_wave_script_images_hook_status');
            $table->index(['line_id', 'status'], 'idx_wave_script_images_line_status');
            $table->index('expires_at', 'idx_wave_script_images_expires_at');
            $table->index('agency_id', 'idx_wave_script_images_agency_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE wave_script_images ADD CONSTRAINT chk_wave_script_images_one_owner CHECK (num_nonnulls(hook_id, line_id) = 1)');
            DB::statement("ALTER TABLE wave_script_images ADD CONSTRAINT chk_wave_script_images_mime CHECK (mime_type IS NULL OR mime_type IN ('image/jpeg', 'image/png', 'image/webp', 'image/gif'))");
            DB::statement("ALTER TABLE wave_script_images ADD CONSTRAINT chk_wave_script_images_status CHECK (status IN ('pending', 'ready'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wave_script_images');
    }
};
