<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * audit_logs — append-only privileged-action log.
 *
 * Schema reference: docs/03-DATA-MODEL.md §12.
 * Append-only contract: docs/05-SECURITY-COMPLIANCE.md §3.4.
 *
 * Append-only means:
 *   - No `updated_at` column. The row is immutable after insert.
 *   - No `deleted_at` column. Retention is handled by a separate
 *     scheduled job running under a privileged DB role (Phase 2+),
 *     not by application soft-deletes.
 *   - The {@see AuditLog} model overrides
 *     update() and delete() to throw, providing application-layer
 *     enforcement on top of the eventual database-grant model
 *     (INSERT + SELECT only) described in §3.4.
 *
 * IP storage note: docs/03-DATA-MODEL.md §12 specifies `inet`. We store as
 * varchar(45) on SQLite (test) and Postgres (production) for portability;
 * the cidr/inet typing is a Postgres-specific optimisation we can introduce
 * via a follow-up migration once Postgres-specific features start landing
 * (see docs/tech-debt.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->char('ulid', 26)->unique();

            $table->foreignId('agency_id')
                ->nullable()
                ->constrained('agencies')
                ->nullOnDelete();

            $table->string('actor_type', 32);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_role', 32)->nullable();

            $table->string('action', 64);

            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->char('subject_ulid', 26)->nullable();

            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();

            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['actor_type', 'actor_id'], 'idx_audit_actor');
            $table->index(['subject_type', 'subject_id'], 'idx_audit_subject');
            $table->index('action', 'idx_audit_action');
            $table->index(['agency_id', 'created_at'], 'idx_audit_agency_created');
            $table->index('created_at', 'idx_audit_created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
