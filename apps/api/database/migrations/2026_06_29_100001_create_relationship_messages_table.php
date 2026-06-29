<?php

declare(strict_types=1);

use App\Modules\Messaging\Enums\MessageKind;
use App\Modules\Messaging\Enums\MessageSenderRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * Creates the `relationship_messages` table — the append-only message log for a
 * relationship thread (AH-010a, D1). Mirrors `messages`, MINUS the system
 * concerns: a relationship thread has NO lifecycle/system messages (no
 * assignment to hang them off), so there is no `system_event_key` column and
 * `sender_user_id` is NOT NULL — every relationship message has a human author
 * (creator or an agency member, D1/Q4).
 *
 * Tenancy: messages scope THROUGH the thread (no `agency_id`). FK delete rules:
 * `thread_id` CASCADE (a message cannot outlive its thread); `sender_user_id`
 * RESTRICT (a human message anchors a real sender; deleting a user with a
 * message history is an explicit GDPR-erasure concern — the
 * `notifications.recipient_user_id` / campaign-`messages` precedent).
 *
 * `attachments` (jsonb) holds an array of {s3_path | external_url, mime_type,
 * name, size_bytes, kind} (D4): files (thread-keyed presigned-PUT, EXIF-stripped
 * on complete) AND links (http/https-only). `kind` (varchar(16)) reuses the
 * campaign {@see MessageKind} vocabulary
 * (`text` / `attachment_only`); `sender_role` reuses
 * {@see MessageSenderRole} (`creator` / `agency_user`).
 *
 * `deleted_at` is present-but-unwritten (the campaign-`messages` D-14 / Sprint-9
 * review-trail pattern): no delete endpoint ships, the column is laid down for a
 * future moderation sprint. Tracked in tech-debt.
 *
 * Index: (thread_id, created_at) for the per-thread chronological feed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relationship_messages', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->unsignedBigInteger('thread_id');
            $table->foreign('thread_id')
                ->references('id')
                ->on('relationship_threads')
                ->cascadeOnDelete();

            // NOT NULL: relationship messages always have a human sender (no
            // system messages on this surface).
            $table->unsignedBigInteger('sender_user_id');
            $table->foreign('sender_user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();

            $table->string('sender_role', 16);
            $table->string('kind', 16);

            $table->text('body')->nullable();
            $table->jsonb('attachments')->nullable();

            $table->timestamps();
            // Present-but-unwritten — no delete endpoint this chunk.
            $table->softDeletes();
        });

        Schema::table('relationship_messages', function (Blueprint $table): void {
            $table->index(['thread_id', 'created_at'], 'idx_relationship_messages_thread_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relationship_messages');
    }
};
