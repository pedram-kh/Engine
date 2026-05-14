<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * Creates the `integration_events` table per docs/03-DATA-MODEL.md § 17
 * (migration #36 in the documented ordering). Append-only log of
 * inbound webhook events from third-party providers.
 *
 * The unique index on (provider, provider_event_id) is THE
 * idempotency mechanism (Decision Q-mock-2 = (a) in the chunk-2
 * plan): the second receipt of the same vendor event ID fails the
 * INSERT, the controller catches the integrity-constraint violation,
 * and returns 200 OK without dispatching the Process*WebhookJob a
 * second time. No application-layer dedupe needed — the database
 * is the source of truth.
 *
 * Stored under app/Modules/Audit/Models/IntegrationEvent.php
 * (Q-module-location in the chunk-2 plan: cross-cutting log
 * adjacent to audit_logs but with a distinct lifecycle —
 * IntegrationEvent does NOT extend the Audited trait or auto-emit
 * audit rows, per Refinement 5).
 *
 * Lifecycle: INSERT (webhook controller) → UPDATE
 * (Process*WebhookJob sets processed_at OR processing_error).
 * Never deleted; future retention sweeps belong in Sprint 14
 * (GDPR / data-retention).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_events', function (Blueprint $table): void {
            $table->id();

            // Vendor namespace (e.g. 'kyc', 'esign', 'stripe',
            // 'meta', 'tiktok'). varchar(32) per data-model spec.
            $table->string('provider', 32);

            // Vendor-side event identifier. The unique index below
            // pairs this with `provider` to enforce idempotency.
            $table->string('provider_event_id', 255);

            // Vendor-side event-type string (e.g.
            // 'verification.completed', 'envelope.signed'). Not
            // constrained to an enum — vendors evolve their
            // taxonomies independently; the handler maps to
            // internal status enums per provider.
            $table->string('event_type', 128);

            // Full vendor-payload as JSONB so debugging / replay
            // has the verbatim record.
            $table->jsonb('payload');

            // Set by Process*WebhookJob on successful handling.
            // NULL while in-flight or on processing failure.
            $table->timestampTz('processed_at')->nullable();

            // Set by Process*WebhookJob on a handling error. The
            // text is operator-only (never returned to the
            // webhook caller per the single-error-code discipline
            // — see chunk-2 review's "Decisions documented for
            // future chunks → single error code for webhook
            // signature failures").
            $table->text('processing_error')->nullable();

            // When the webhook controller received the event.
            // Distinct from created_at (NOT created here — the
            // table is append-only and explicitly does not carry
            // the standard updated_at; received_at + processed_at
            // is the full lifecycle picture).
            $table->timestampTz('received_at');

            // The unique index is the idempotency mechanism.
            // Deliberately enforced at the database layer so a
            // race between concurrent webhook receipts of the same
            // event_id resolves to a single accepted insert.
            $table->unique(
                ['provider', 'provider_event_id'],
                'unique_integration_provider_event',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_events');
    }
};
