<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * Creates the `integration_credentials` table per
 * docs/03-DATA-MODEL.md § 17 (migration #37). Stores per-provider
 * (and optionally per-agency) credentials with the `credentials`
 * column encrypted at the application layer per
 * docs/03-DATA-MODEL.md § 23 + docs/05-SECURITY-COMPLIANCE.md § 4.
 *
 * Sprint 3 Chunk 2 ships the table but does NOT write to it — mock
 * provider credentials live in `config/integrations.php` and real
 * vendor secrets live in AWS Secrets Manager per
 * docs/06-INTEGRATIONS.md § 1.2. Future sprints (Sprint 4 KYC,
 * Sprint 7 Stripe Connect, Sprint 9 e-sign) write rows here when
 * agency-specific credentials are needed (e.g., custom DocuSign
 * accounts).
 *
 * `agency_id` is nullable: a NULL row is a global credential, a
 * non-NULL row is an agency-specific credential. The application
 * layer's binding logic prefers agency-specific rows over global
 * fallbacks when both exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_credentials', function (Blueprint $table): void {
            $table->id();

            // Per-agency override slot. NULL means "global credential
            // for this provider". When non-NULL, the FK references
            // agencies.id and cascades on tenant deletion (rare —
            // tenant deletes are soft-delete via deleted_at; this is
            // a defensive belt-and-braces for the explicit-hard-delete
            // path).
            $table->foreignId('agency_id')->nullable()->constrained('agencies')->cascadeOnDelete();

            // Vendor namespace (matches integration_events.provider).
            $table->string('provider', 32);

            // Encrypted JSON blob. The application-layer cast (set
            // on the IntegrationCredential model) round-trips
            // through Laravel's `encrypted:array` so writes are
            // ciphertext on disk and reads decrypt back to an
            // array. NEVER store unencrypted credential material
            // here — the encryption cast is non-negotiable per
            // docs/03-DATA-MODEL.md § 23.
            $table->jsonb('credentials');

            // Optional vendor-side credential expiry hint so a
            // future scheduler can surface "expiring soon" alerts.
            $table->timestampTz('expires_at')->nullable();

            $table->timestampsTz();

            // A given provider has at most one row per agency
            // (agency_id NULL = global; non-NULL = agency-specific).
            // PostgreSQL treats NULLs as distinct in unique
            // constraints, which is exactly the behaviour we want
            // (multiple agencies can each have their own row + one
            // global row may also exist).
            $table->unique(['agency_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_credentials');
    }
};
