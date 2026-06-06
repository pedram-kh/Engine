<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * Creates the `message_threads` table — one thread per CampaignAssignment
 * (Sprint 11, D-3). Per docs/03-DATA-MODEL.md §11.
 *
 * Tenancy (D-16): tenant-scoped via `agency_id` (the model uses
 * BelongsToAgency). Messages + read receipts scope THROUGH the thread, so they
 * carry no `agency_id` of their own.
 *
 * The `assignment_id` UNIQUE is the idempotency backstop for the three
 * thread-create sites (D-3): the invite listener, the defensive create before a
 * system-message write, and the lazy create on first GET all use
 * firstOrCreate keyed on `assignment_id`, and a concurrent double-create
 * collides on this unique rather than duplicating.
 *
 * FK delete rules: `agency_id` RESTRICT (the tenancy anchor, mirroring
 * campaign_assignments); `assignment_id` CASCADE (a thread cannot outlive its
 * assignment).
 *
 * `last_message_at` is a denormalized sort/preview hint, stamped on every
 * message write (human or system); nullable until the first message lands.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_threads', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            // Denormalized tenant scope (BelongsToAgency).
            $table->unsignedBigInteger('agency_id');
            $table->foreign('agency_id')
                ->references('id')
                ->on('agencies')
                ->restrictOnDelete();

            // One thread per assignment — the UNIQUE backs firstOrCreate
            // idempotency across the three create sites (D-3).
            $table->unsignedBigInteger('assignment_id');
            $table->foreign('assignment_id')
                ->references('id')
                ->on('campaign_assignments')
                ->cascadeOnDelete();
            $table->unique('assignment_id', 'unique_message_threads_assignment');

            $table->timestampTz('last_message_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_threads');
    }
};
