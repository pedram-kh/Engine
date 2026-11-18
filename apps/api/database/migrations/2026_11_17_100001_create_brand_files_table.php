<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * PROD-DATA RISK: NONE. One NEW, EMPTY table; no existing table is altered and no row is read, written or
 * backfilled. Nothing reads or writes `brand_files` until the `waves_enabled` flag is turned on.
 *
 * PL-1.1 — client files on a brand (docs/reviews/pl-1-1-plan.md §1.2). Mirrors `agency_files` with a brand
 * parent; kept separate so the Files library and the campaign attach picker are unchanged.
 *
 * `storage_key` is UNIQUE: `agencies/{agency_ulid}/brands/{brand_ulid}/files/{file_ulid}.{ext}` is minted once
 * per row. `file_type` is a plain string; the PHP enum is the constraint. `uploaded_by_user_id` is nullable with
 * `nullOnDelete` (attribution only, as `agency_files`). `agency_id` and `brand_id` are RESTRICT.
 *
 * down() honesty: `dropIfExists` is a LOSSY content inverse: every row's metadata is destroyed and the S3 objects
 * are NOT deleted (they become untracked bytes under `agencies/*\/brands/*\/files/`). A rollback tool only before
 * the flag is armed. Take a snapshot first once rows exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Addendum 2026-11-18: the foreign keys take a brief ShareRowExclusiveLock on each referenced live table, so
        // give up after 5 s instead of queueing behind a long transaction (PostgreSQL only).
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }

        Schema::create('brand_files', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->unsignedBigInteger('agency_id');
            $table->foreign('agency_id')->references('id')->on('agencies')->restrictOnDelete();

            $table->unsignedBigInteger('brand_id');
            $table->foreign('brand_id')->references('id')->on('brands')->restrictOnDelete();

            $table->string('original_filename', 255);
            $table->string('storage_key', 512)->unique();
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('size_bytes');
            $table->string('file_type', 16);

            $table->unsignedBigInteger('uploaded_by_user_id')->nullable();
            $table->foreign('uploaded_by_user_id')->references('id')->on('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['brand_id', 'created_at'], 'idx_brand_files_brand_created');
        });
    }

    public function down(): void
    {
        // Lossy: destroys every row's metadata and orphans the S3 objects. Rollback tool only before the flag is armed.
        Schema::dropIfExists('brand_files');
    }
};
