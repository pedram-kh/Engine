<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * Creates the `user_notification_preferences` table — the per-type × per-channel
 * opt-out surface for the notification subsystem (S11.0 Chunk 1, D-7). Per
 * docs/03-DATA-MODEL.md §14 (the `notification_preferences` row shape:
 * channel + event_key + is_enabled), here named to its subsystem.
 *
 * Row shape: one row per (user, type, channel). `type` is the NotificationType
 * value (the docs' `event_key`); `channel` is one of `in_app` / `email` /
 * `digest`. The `digest` channel is present-but-unconsumed this chunk — the
 * Messaging sprint is its first consumer.
 *
 * Default resolution is COMPUTED, not seeded (D-7): a MISSING row resolves to
 * `in_app=on, email=on, digest=off` (preserve-current). No per-user row is
 * seeded; a missing row can NEVER silently disable an existing email, so the
 * Ch2 retrofit is safe by construction. The DB-level `is_enabled` default is
 * `true` to match the in_app/email channels; the application layer owns the
 * per-channel default (NotificationService / preference resolution).
 *
 * Tenancy (D-9): user-global, like `users`. NO `agency_id`, no BelongsToAgency;
 * own-record-only authorization.
 *
 * FK: `user_id` CASCADE — preferences are owned by the user and have no value
 * once the user is gone (distinct from notifications, which RESTRICT for the
 * erasure trail). Unique (user_id, type, channel) is the upsert key (the Ch3
 * write-back UI re-uses it). Runs on Postgres (CI) and SQLite (local).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_notification_preferences', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();

            // The NotificationType value (docs/03-DATA-MODEL.md §14 `event_key`).
            $table->string('type', 64);

            // in_app | email | digest.
            $table->string('channel', 16);

            $table->boolean('is_enabled')->default(true);

            $table->timestamps();

            $table->unique(['user_id', 'type', 'channel'], 'unique_user_notification_pref');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notification_preferences');
    }
};
