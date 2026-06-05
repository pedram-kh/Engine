<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * Creates the `campaign_posted_content` table (Sprint 9 Chunk 1, D-2). Tracks
 * the actual published post on social. A CHILD of a campaign assignment — each
 * row points UP via `assignment_id`; `campaign_assignments` carries NO back-FK.
 * Per docs/03-DATA-MODEL.md §7 (`campaign_posted_content`, :605-622).
 *
 * FK delete rules: `assignment_id` CASCADE (posted content cannot outlive its
 * assignment).
 *
 * Chunk 1 only WRITES the creator-reported side (`platform`, `post_url`,
 * `posted_at`, `verification_status` default `pending`). The verification job
 * (Chunk 2) advances `verification_status` + stamps `verified_at` /
 * `platform_post_id`. The metrics columns (`last_metrics_synced_at`,
 * `metrics`, `metrics_history`) ship now but are mostly P2 column-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_posted_content', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->unsignedBigInteger('assignment_id');
            $table->foreign('assignment_id')
                ->references('id')
                ->on('campaign_assignments')
                ->cascadeOnDelete();

            $table->string('platform', 16);
            $table->string('post_url', 2048);
            // Stamped by the verification job (Chunk 2) once the post is matched.
            $table->string('platform_post_id', 128)->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('verified_at')->nullable();

            // pending → {verified, not_found, mismatch}. Chunk 2's
            // VerifyPostedContentJob advances this; Chunk 1 writes `pending`.
            $table->string('verification_status', 16)->default('pending');

            // Metrics — mostly P2 column-only (synced by a later metrics job).
            $table->timestamp('last_metrics_synced_at')->nullable();
            $table->jsonb('metrics')->nullable();
            $table->jsonb('metrics_history')->nullable();

            $table->timestamps();
        });

        Schema::table('campaign_posted_content', function (Blueprint $table): void {
            $table->index(['assignment_id', 'verification_status'], 'idx_posted_content_assignment_verification');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_posted_content');
    }
};
