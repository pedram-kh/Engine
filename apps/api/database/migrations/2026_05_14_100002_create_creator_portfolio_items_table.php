<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * Creates the `creator_portfolio_items` table per docs/03-DATA-MODEL.md §5.
 * Sprint 3 Chunk 1 migration #9.
 *
 * Up to 10 items per creator (spec §5). Enforced at the application layer
 * inside the wizard portfolio service, not at the DB level — partial
 * counting via DB constraint would require triggers and the 10-cap is a
 * product rule that may shift in future tiers (Sprint 4+ premium).
 *
 * Position column carries the display order (drag-reorder is a frontend
 * concern in Chunk 3; backend ships PATCH …/portfolio/reorder).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creator_portfolio_items', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->unsignedBigInteger('creator_id');
            $table->foreign('creator_id')
                ->references('id')
                ->on('creators')
                ->cascadeOnDelete();

            $table->string('kind', 16);
            $table->string('title', 255)->nullable();
            $table->text('description')->nullable();

            // Either s3_path (uploaded file via media disk) OR external_url
            // (link items). Mutually exclusive; service-layer validation.
            $table->string('s3_path', 512)->nullable();
            $table->string('external_url', 2048)->nullable();
            $table->string('thumbnail_path', 512)->nullable();
            $table->string('mime_type', 64)->nullable();
            $table->bigInteger('size_bytes')->nullable();
            $table->integer('duration_seconds')->nullable();

            $table->integer('position');

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('creator_portfolio_items', function (Blueprint $table): void {
            $table->index(['creator_id', 'position'], 'idx_portfolio_creator_position');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creator_portfolio_items');
    }
};
