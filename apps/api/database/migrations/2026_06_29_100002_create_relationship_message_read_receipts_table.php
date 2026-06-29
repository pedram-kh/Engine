<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * Creates the `relationship_message_read_receipts` table — per-user read state
 * on a relationship message (AH-010a, D1/D5/D10). Mirrors
 * `message_read_receipts`.
 *
 * Read state is per-USER, not per-thread: each agency member (org-level — any
 * active member of the connected agency can read, Q4) and the creator has their
 * own receipt set, so "unread" is computed per viewer (the absence of a receipt
 * row for a message they can see, excluding their own sends). This is what makes
 * unread + the two-state read indicator (D10) "auto-work" on the existing poll.
 *
 * The `(message_id, user_id)` UNIQUE makes mark-read idempotent: a second
 * mark-read collides on the unique and is a no-op rather than a duplicate row.
 *
 * Tenancy: receipts scope THROUGH the message → thread. No `agency_id`. FK
 * delete rules: both CASCADE (a receipt is meaningless without its message or
 * its user). No timestamps beyond `read_at` — the row IS the read event.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relationship_message_read_receipts', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('message_id');
            $table->foreign('message_id')
                ->references('id')
                ->on('relationship_messages')
                ->cascadeOnDelete();

            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();

            $table->timestampTz('read_at');

            $table->unique(['message_id', 'user_id'], 'unique_relationship_receipts_message_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relationship_message_read_receipts');
    }
};
