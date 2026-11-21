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
 * PL-2.3 (M3): reference images of a concept. A `pending` row is the reservation made at upload init (it counts against
 * the 10-image and 300 MB limits until it expires); `ready` rows carry the stored object's own MIME and size.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }

        Schema::create('wave_concept_images', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->unsignedBigInteger('agency_id');
            $table->foreign('agency_id')->references('id')->on('agencies')->restrictOnDelete();

            $table->unsignedBigInteger('wave_id');
            $table->foreign('wave_id')->references('id')->on('waves')->restrictOnDelete();

            $table->char('market', 2);
            $table->unsignedBigInteger('concept_id');
            $table->foreign(['wave_id', 'market', 'concept_id'], 'fk_wave_concept_images_concept')
                ->references(['wave_id', 'market', 'id'])->on('wave_concepts')->restrictOnDelete();

            $table->string('storage_key', 255)->unique();
            $table->string('thumb_key', 255)->nullable()->unique();
            $table->string('original_filename', 255);
            $table->string('mime_type', 16)->nullable();
            $table->unsignedBigInteger('declared_bytes');
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('status', 8)->default('pending');
            $table->timestamp('expires_at')->nullable();
            $table->unsignedSmallInteger('position')->default(0);

            $table->unsignedBigInteger('uploaded_by_user_id')->nullable();
            $table->foreign('uploaded_by_user_id')->references('id')->on('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['concept_id', 'status'], 'idx_wave_concept_images_concept_status');
            $table->index('expires_at', 'idx_wave_concept_images_expires_at');
            $table->index('agency_id', 'idx_wave_concept_images_agency_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE wave_concept_images ADD CONSTRAINT chk_wave_concept_images_status CHECK (status IN ('pending', 'ready'))");
            DB::statement("ALTER TABLE wave_concept_images ADD CONSTRAINT chk_wave_concept_images_mime CHECK (mime_type IS NULL OR mime_type IN ('image/jpeg', 'image/png', 'image/webp'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wave_concept_images');
    }
};
