<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * Creates the `messages` table — the append-only message log for a thread
 * (Sprint 11). Per docs/03-DATA-MODEL.md §11.
 *
 * D-2 spec-drift correction: `sender_user_id` is NULLABLE here (the data model
 * §11 drafted it non-null). System messages (`sender_role = system`,
 * `kind = system`) have NO human sender — `sender_user_id = null`, mirroring the
 * established S11.0 `notifications.actor_user_id`-nullable precedent. No
 * fictional bot user. Human messages always carry a non-null sender.
 *
 * Tenancy (D-16): messages scope THROUGH the thread (no `agency_id`). The model
 * does NOT use BelongsToAgency.
 *
 * FK delete rules: `thread_id` CASCADE (a message cannot outlive its thread);
 * `sender_user_id` RESTRICT (a human message anchors a real sender; deleting a
 * user with a message history is an explicit GDPR-erasure concern, not an
 * incidental cascade — the notifications.recipient_user_id RESTRICT precedent).
 *
 * `attachments` (jsonb) holds an array of {s3_path, mime_type, name,
 * size_bytes} (D-6). `system_event_key` (varchar(64)) holds the AuditAction
 * verb string for system messages (D-4); null for human messages — the body
 * text is NEVER stored localized, it renders from key + context (D-5).
 *
 * `deleted_at` is present-but-unwritten this sprint (D-14, the Sprint-9
 * review-trail-columns pattern): no delete endpoint ships, the soft-delete
 * column is laid down for a future moderation sprint. Tracked in tech-debt.
 *
 * Index: (thread_id, created_at) for the per-thread chronological feed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->unsignedBigInteger('thread_id');
            $table->foreign('thread_id')
                ->references('id')
                ->on('message_threads')
                ->cascadeOnDelete();

            // NULLABLE (D-2): system messages have no human sender.
            $table->unsignedBigInteger('sender_user_id')->nullable();
            $table->foreign('sender_user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();

            $table->string('sender_role', 16);
            $table->string('kind', 16);

            $table->text('body')->nullable();
            $table->jsonb('attachments')->nullable();

            // The AuditAction verb string for system messages (D-4); null for
            // human messages. Localized text is NEVER stored (D-5).
            $table->string('system_event_key', 64)->nullable();

            $table->timestamps();
            // Present-but-unwritten (D-14) — no delete endpoint this sprint.
            $table->softDeletes();
        });

        Schema::table('messages', function (Blueprint $table): void {
            $table->index(['thread_id', 'created_at'], 'idx_messages_thread_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
