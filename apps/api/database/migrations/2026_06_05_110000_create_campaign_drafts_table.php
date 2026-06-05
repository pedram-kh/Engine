<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * Creates the `campaign_drafts` table (Sprint 9 Chunk 1, D-1). A draft is a
 * CHILD of a campaign assignment — each row points UP via `assignment_id`;
 * `campaign_assignments` carries NO back-FK. One row per submission attempt;
 * `version` increments per resubmission so the full history is preserved
 * (D-6). Per docs/03-DATA-MODEL.md §7 (`campaign_drafts`, :572-600).
 *
 * FK delete rules: `assignment_id` CASCADE (a draft cannot outlive its
 * assignment). `submitted_by_creator_id` / `reviewed_by_user_id` /
 * `client_reviewed_by_user_id` nullOnDelete (attribution columns).
 *
 * Chunk 1 only WRITES the submission side (`version`, `submitted_*`,
 * `caption`, `hashtags`, `mentions`, `media_attachments`, `review_status`
 * default `pending`). The review-trail columns (`reviewed_*`,
 * `review_feedback`) ship now but are populated by Chunk 2 (agency review).
 * The P2/P3 columns (`client_review_*`, `ai_qc_*`) ship as column-only.
 *
 * Free-text (`caption`, `review_feedback`, `client_review_feedback`) is kept
 * OUT of any audit snapshot — the hand-written-audit discipline (the model
 * intentionally does not use the Audited trait).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_drafts', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->unsignedBigInteger('assignment_id');
            $table->foreign('assignment_id')
                ->references('id')
                ->on('campaign_assignments')
                ->cascadeOnDelete();

            // Increments per resubmission — each version is its own row with
            // its own review trail (D-6, history preserved).
            $table->integer('version');

            $table->unsignedBigInteger('submitted_by_creator_id')->nullable();
            $table->foreign('submitted_by_creator_id')
                ->references('id')
                ->on('creators')
                ->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();

            // Submission payload.
            $table->text('caption')->nullable();
            $table->jsonb('hashtags')->nullable();
            $table->jsonb('mentions')->nullable();
            // Array of {s3_path, mime_type, kind, thumbnail_path, duration_seconds}.
            $table->jsonb('media_attachments')->nullable();

            // Review trail (Chunk 2 writes these — column-only this chunk).
            $table->string('review_status', 16)->default('pending');
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
            $table->foreign('reviewed_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
            $table->text('review_feedback')->nullable();

            // P2 brand-portal review (column-only from P1).
            $table->string('client_review_status', 16)->nullable();
            $table->timestamp('client_reviewed_at')->nullable();
            $table->unsignedBigInteger('client_reviewed_by_user_id')->nullable();
            $table->foreign('client_reviewed_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
            $table->text('client_review_feedback')->nullable();

            // P3 automated QC (column-only from P1).
            $table->jsonb('ai_qc_results')->nullable();
            $table->boolean('ai_qc_passed')->nullable();

            $table->timestamps();
        });

        Schema::table('campaign_drafts', function (Blueprint $table): void {
            // One version number per assignment (resubmit computes max+1).
            $table->unique(['assignment_id', 'version'], 'unique_draft_assignment_version');
            $table->index(['assignment_id', 'review_status'], 'idx_drafts_assignment_review_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_drafts');
    }
};
