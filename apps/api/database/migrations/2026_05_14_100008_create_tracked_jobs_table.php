<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * Creates the `tracked_jobs` table per docs/04-API-DESIGN.md § 18.
 *
 * Reusable infrastructure for any async operation whose progress must
 * be poll-able by the initiator. Sprint 3 Chunk 1 introduces this for
 * bulk creator invitations; future sprints reuse it (GDPR exports —
 * Sprint 14, payments — Sprint 10).
 *
 * The `agency_id` column is nullable because some tracked jobs are
 * cross-tenant (e.g. platform-admin sweeps in P2). When set, the GET
 * endpoint enforces agency-scope membership before returning the row.
 *
 * `result` is jsonb so reusable progress hints + final result payloads
 * can be queried from operator tooling. Encryption is NOT applied at
 * this layer — callers MUST NOT store PII in the result column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracked_jobs', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            // The kind discriminator (e.g. 'bulk_creator_invitation',
            // 'data_export'). 64 chars is generous; canonical kinds
            // are documented per-sprint review.
            $table->string('kind', 64);

            // Initiator metadata — who started this job?
            $table->unsignedBigInteger('initiator_user_id')->nullable();
            $table->foreign('initiator_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            // Tenant scope — null for cross-tenant jobs (rare); set for
            // agency-scoped jobs. Enforced at the GET endpoint.
            $table->unsignedBigInteger('agency_id')->nullable();
            $table->foreign('agency_id')
                ->references('id')
                ->on('agencies')
                ->cascadeOnDelete();

            // Lifecycle. Spec § 18 lists queued | processing | complete | failed.
            $table->string('status', 16)->default('queued');

            // Progress 0.0 to 1.0. Persisted as numeric for clean JSON
            // round-trip; controllers cast to float.
            $table->decimal('progress', 5, 4)->default(0);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('estimated_completion_at')->nullable();

            // Free-form result payload + failure_reason. result MUST NOT
            // contain PII per the audit-allowlist discipline.
            $table->jsonb('result')->nullable();
            $table->text('failure_reason')->nullable();

            $table->timestamps();
        });

        Schema::table('tracked_jobs', function (Blueprint $table): void {
            $table->index(['initiator_user_id', 'status'], 'idx_tracked_jobs_initiator_status');
            $table->index(['agency_id', 'kind'], 'idx_tracked_jobs_agency_kind');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracked_jobs');
    }
};
