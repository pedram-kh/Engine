<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * Creates the `relationship_threads` table — one 1:1 thread per
 * connected agency↔creator pair (AH-010a, D1).
 *
 * A deliberate PARALLEL spine to `message_threads`, NOT a generalization of it.
 * Campaign messaging's `messages.thread_id` FK points hard at `message_threads`,
 * so relationship messages cannot live in the shipped `messages` table without
 * a campaign-path schema change — the AH-010 Step-0 finding. To keep the
 * Sprint-11 campaign system and its full suite untouched/green (the build
 * assertion), AH-010 mirrors the message layer (`relationship_messages` +
 * `relationship_message_read_receipts`) below this spine rather than sharing it.
 * The duplication is logged as deliberate debt with a named consolidation
 * trigger (extract a shared message contract once it is safe to touch both).
 *
 * Tenancy (mirrors D-16): tenant-scoped via `agency_id` (BelongsToAgency).
 * Messages + receipts scope THROUGH the thread, so they carry no `agency_id`.
 *
 * The `(agency_id, creator_id)` UNIQUE is the idempotency backstop (D3): both
 * sides may initiate, and `firstOrCreate` keyed on this pair makes a concurrent
 * double-create collide on the unique rather than duplicating — the
 * assignment-thread `unique_message_threads_assignment` pattern.
 *
 * FK delete rules: both RESTRICT — deleting an agency or a creator that holds a
 * message history is an explicit GDPR-erasure concern, not an incidental
 * cascade (the `notifications.recipient_user_id` RESTRICT precedent), and
 * `agency_id` is additionally the tenancy anchor.
 *
 * `last_message_at` is a denormalized sort/preview hint stamped on every
 * message write; nullable until the first message lands.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relationship_threads', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            // Denormalized tenant scope (BelongsToAgency).
            $table->unsignedBigInteger('agency_id');
            $table->foreign('agency_id')
                ->references('id')
                ->on('agencies')
                ->restrictOnDelete();

            $table->unsignedBigInteger('creator_id');
            $table->foreign('creator_id')
                ->references('id')
                ->on('creators')
                ->restrictOnDelete();

            // One thread per connected pair — the UNIQUE backs firstOrCreate
            // idempotency across both initiating sides (D3).
            $table->unique(['agency_id', 'creator_id'], 'unique_relationship_threads_pair');

            $table->timestampTz('last_message_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relationship_threads');
    }
};
