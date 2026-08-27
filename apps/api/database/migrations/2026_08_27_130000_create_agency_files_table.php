<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * Creates the `agency_files` table — AH-091, Agency Files library chunk A. A
 * standalone (parent-less) tenant-scoped document row: upload, list, delete,
 * nothing else. Per docs/reviews/agency-files-plan.md §2.1 and
 * docs/03-DATA-MODEL.md §16 (which this table narrows — see that section for
 * the distinction from the still-unbuilt generic `files` registry).
 *
 * Tenancy: `agency_id` (the model uses BelongsToAgency, mirroring `boards`).
 * RESTRICT — an agency with files cannot be deleted out from under them.
 *
 * `storage_key` is UNIQUE — the S3 path `agencies/{agency_ulid}/files/{file_ulid}.{ext}`
 * is minted once per row and never reused (the ULID makes collision
 * practically impossible; the UNIQUE is a backstop, not a rename target).
 *
 * `mime_type` is `varchar(128)`, not the generic §16 spec's `varchar(64)` —
 * several allowed Office MIME strings (e.g. the 62-character
 * `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`) sit
 * close enough to 64 that a longer sibling string would silently truncate;
 * 128 is deliberate headroom, not an oversight.
 *
 * `category` is a plain `varchar(32)`, not a DB enum — v1 ships one value
 * (`campaign_attachment`); the PHP-level `AgencyFileCategory` enum is the
 * actual constraint, matching the `brands.status`-style string-column
 * precedent rather than a DB CHECK that would need a migration to extend.
 *
 * `uploaded_by_user_id` is nullable with `nullOnDelete` — attribution only,
 * never a required actor (a deleted user's prior uploads stay listed).
 *
 * `deleted_at` (SoftDeletes) — the CreatorPortfolioItem precedent: the row is
 * soft-deleted, but the S3 object itself is hard-deleted synchronously by the
 * application layer (object-first — see AgencyFileUploadService::delete()).
 * This migration only shapes the row; it has no reach into S3.
 *
 * FK delete rules: `agency_id` RESTRICT (the tenancy anchor); `uploaded_by_user_id`
 * SET NULL (attribution is best-effort, never blocking).
 *
 * down() honesty: dropping this table is a true structural inverse but a
 * lossy content inverse — every row's metadata (filename, category, uploader)
 * is discarded, and the S3 objects those rows pointed at are NOT deleted by
 * this migration; they become permanently untracked bytes under
 * `agencies/*\/files/` until a future reconciliation pass. A pre-deploy DB
 * snapshot is mandatory per house policy before running this migration in
 * either direction against a database that has already accepted uploads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_files', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            // Denormalized tenant scope (BelongsToAgency), RESTRICT.
            $table->unsignedBigInteger('agency_id');
            $table->foreign('agency_id')
                ->references('id')
                ->on('agencies')
                ->restrictOnDelete();

            $table->string('original_filename', 255);
            $table->string('storage_key', 512)->unique();
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('size_bytes');
            $table->string('category', 32);

            $table->unsignedBigInteger('uploaded_by_user_id')->nullable();
            $table->foreign('uploaded_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('agency_files', function (Blueprint $table): void {
            $table->index(['agency_id', 'created_at'], 'idx_agency_files_agency_created');
        });
    }

    public function down(): void
    {
        // Structural inverse only — see the class docblock: this discards row
        // metadata and orphans any already-uploaded S3 objects permanently.
        Schema::dropIfExists('agency_files');
    }
};
