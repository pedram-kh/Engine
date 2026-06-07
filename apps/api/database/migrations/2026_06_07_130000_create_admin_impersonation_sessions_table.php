<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * admin_impersonation_sessions — the server-authoritative impersonation
 * record (Sprint 13, D-9).
 *
 * Schema reference: docs/03-DATA-MODEL.md §6.8 (the persistent impersonation
 * log) + the Sprint-13 TTL addendum.
 *
 * This row is BOTH the audit-grade log of "admin X impersonated user Y, for
 * reason Z, from start..end" AND the live authority the per-request
 * enforcement middleware (D-10) reads on every impersonated request:
 *
 *   - `expires_at` is the TTL AUTHORITY (Q2). Server-side and absolute —
 *     30 minutes from start, regardless of cookie state. The middleware
 *     rejects (and ends) any impersonated session whose `expires_at` has
 *     passed. An advisory frontend timer is NOT trusted.
 *   - `ended_at` set means the impersonation was explicitly ended (by the
 *     admin or the impersonated tab); the middleware terminates the main
 *     session on its next request.
 *   - `token_hash` is the one-time hand-off token (hashed; the plaintext is
 *     returned to the admin once and consumed by the main-SPA claim). The
 *     two-cookie model means the admin's `web_admin` POST cannot write the
 *     main SPA's `web` session directly, so the token bridges the SPAs.
 *   - `claimed_at` makes the hand-off SINGLE-USE.
 *
 * No `updated_at` beyond the mutable lifecycle columns above; the row is
 * otherwise append-grade.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_impersonation_sessions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->char('ulid', 26)->unique();

            // The impersonator (platform_admin) and the impersonated user.
            $table->foreignId('admin_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('impersonated_user_id')->constrained('users')->cascadeOnDelete();

            $table->text('reason');

            // One-time hand-off token (hashed). Consumed by the main-SPA
            // claim; nulled out once claimed so it can never be replayed.
            $table->char('token_hash', 64)->nullable()->unique();

            // TTL authority (Q2) — absolute, server-side.
            $table->timestamp('expires_at');
            $table->timestamp('started_at');
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('ended_at')->nullable();

            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            // The enforcement middleware looks up the live session by ulid
            // (stored in the main `web` session); incident review queries by
            // either party.
            $table->index('admin_user_id', 'idx_impersonation_admin');
            $table->index('impersonated_user_id', 'idx_impersonation_impersonated');
            $table->index(['ended_at', 'expires_at'], 'idx_impersonation_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_impersonation_sessions');
    }
};
