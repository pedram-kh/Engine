<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * Creates the `message_read_receipts` table — per-user read state on a message
 * (Sprint 11). Per docs/03-DATA-MODEL.md §11.
 *
 * Read state is per-USER, not per-thread: each agency notifiable member (and
 * the creator) has their own receipt set, so "unread" is computed per viewer
 * (the absence of a receipt row for a message they can see, excluding their own
 * sends + system messages).
 *
 * The `(message_id, user_id)` UNIQUE makes re-reading idempotent (§5.6): a
 * second mark-read collides on the unique and is a no-op rather than a
 * duplicate row.
 *
 * Tenancy (D-16): receipts scope THROUGH the message → thread. No `agency_id`.
 *
 * FK delete rules: both CASCADE (a receipt is meaningless without its message
 * or its user). No timestamps beyond `read_at` — the row IS the read event.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_read_receipts', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('message_id');
            $table->foreign('message_id')
                ->references('id')
                ->on('messages')
                ->cascadeOnDelete();

            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();

            $table->timestampTz('read_at');

            $table->unique(['message_id', 'user_id'], 'unique_read_receipts_message_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_read_receipts');
    }
};
