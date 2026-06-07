<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * audit_logs.impersonator_user_id — the dual-audit column (Sprint 13, D-9 / Q3).
 *
 * When an action is taken WHILE impersonating, the audit row's `actor_id`
 * is the IMPERSONATED user (the action was taken "as" them — that is the
 * truthful actor), and `impersonator_user_id` is the platform_admin who was
 * behind the keyboard. A FIRST-CLASS COLUMN (not JSON metadata) because the
 * incident-review queries are inherently column queries:
 *
 *   - "every action taken while impersonating user Y" → WHERE actor_id = Y
 *     AND impersonator_user_id IS NOT NULL
 *   - "every action admin X performed via impersonation" →
 *     WHERE impersonator_user_id = X
 *
 * A JSON `metadata->impersonator` field cannot serve those first-class /
 * indexed. NULL for the overwhelming majority of rows (no impersonation in
 * play), so the column is cheap.
 *
 * AuditLogger reads ImpersonationContext (the singleton mirror of
 * TenancyContext) to populate this automatically — no caller needs to know.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->unsignedBigInteger('impersonator_user_id')->nullable()->after('actor_role');

            $table->index('impersonator_user_id', 'idx_audit_impersonator');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropIndex('idx_audit_impersonator');
            $table->dropColumn('impersonator_user_id');
        });
    }
};
