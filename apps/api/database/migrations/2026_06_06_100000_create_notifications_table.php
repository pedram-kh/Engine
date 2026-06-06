<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * Creates the `notifications` table — the core of the in-app notification
 * subsystem (S11.0 Chunk 1, D-4). Per docs/03-DATA-MODEL.md §14.
 *
 * This is a CUSTOM table, deliberately NOT Laravel's stock `database`
 * notification channel. The stock channel stores `type` as a class FQCN and
 * everything else in an opaque `data` blob — no actor column, no indexable
 * type, no clean per-type query. This shape gives us a first-class
 * `recipient_user_id`, an indexable `type` (the NotificationType enum value,
 * sharing the AuditAction vocabulary), an optional `actor_user_id`, and an
 * optional polymorphic subject.
 *
 * Tenancy (D-9): notifications are user-level, ABOVE tenancy. The table has
 * NO `agency_id` and the model does NOT use BelongsToAgency — isolation is
 * `recipient_user_id = auth user` at the controller. Both agency users and
 * creators are Users hitting the same surface.
 *
 * FK delete rules: `recipient_user_id` RESTRICT (a notification anchors a
 * real recipient; deleting a user with a notification history is an explicit
 * GDPR-erasure concern, not an incidental cascade). `actor_user_id` nullOnDelete
 * (system notifications have no actor — the audit `actor_id`-nullable precedent;
 * a since-deleted actor leaves the row intact).
 *
 * Polymorphic subject (`subject_type` + `subject_id`) is the manual pair the
 * codebase uses everywhere (audit_logs, contracts) rather than morphs(). Both
 * are nullable — a system notification need not pin to a subject row.
 *
 * `data` (jsonb) holds render params only (e.g. {campaign_name, creator_name})
 * — NEVER localized text. The body renders client-side from `type` + `data`
 * (Ch3). Append-then-mark-read lifecycle: no `updated_at` (mirrors audit_logs);
 * `read_at` is the only post-insert mutation.
 *
 * Indexes: (recipient_user_id, read_at) for the unread-count; (recipient_user_id,
 * created_at) for the feed. Runs on Postgres (CI) and SQLite (local).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->unsignedBigInteger('recipient_user_id');
            $table->foreign('recipient_user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();

            // System notifications have no actor (the audit actor_id-nullable
            // precedent). A since-deleted actor nulls out, leaving the row.
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->foreign('actor_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            // Polymorphic subject — the assignment/creator/message the
            // notification is about. Manual pair (no FK); nullable for
            // system notifications that pin to no single row.
            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            // The NotificationType enum value (shares the AuditAction vocabulary).
            $table->string('type', 64);

            // Render params only — never localized text (D-4).
            $table->jsonb('data')->nullable();

            $table->timestampTz('read_at')->nullable();

            // Append-then-mark-read: created_at only, no updated_at (audit_logs
            // precedent). read_at is the sole post-insert mutation.
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::table('notifications', function (Blueprint $table): void {
            // Unread-count: WHERE recipient_user_id = ? AND read_at IS NULL.
            $table->index(['recipient_user_id', 'read_at'], 'idx_notifications_recipient_unread');
            // Feed: WHERE recipient_user_id = ? ORDER BY created_at DESC.
            $table->index(['recipient_user_id', 'created_at'], 'idx_notifications_recipient_feed');
            $table->index(['subject_type', 'subject_id'], 'idx_notifications_subject');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
