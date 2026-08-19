<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * AH-083 (⑧) — the per-(thread, recipient) debounce stamp for the new
 * immediate-message email. A NEW, additive table; no existing row anywhere is
 * read-for-mutation or rewritten by this migration.
 *
 * Named `message_email_debounces` rather than a `notification`-vocabulary name
 * (kickoff Q7): this table stores email-debounce state specifically, not a
 * general notification record — `notification`-vocabulary naming would
 * overclaim its scope.
 *
 * Row shape: one row per (thread, recipient_user_id) pair that has EVER
 * received a debounced email. `thread_type` / `thread_id` is a genuine
 * `morphTo('thread')` pair (not a hand-rolled discriminator) — Laravel's
 * default morph-column naming for a relation named `thread()` is exactly this
 * shape, matching the one polymorphic-subject precedent already in the house
 * (`notifications.subject_type` / `subject_id`; no `Relation::morphMap()` is
 * registered anywhere, so both columns store the raw model FQCN — either
 * `App\Modules\Messaging\Models\MessageThread` or
 * `App\Modules\Messaging\Models\RelationshipThread`).
 *
 * `last_emailed_at` is NOT nullable — a row only exists once an email has
 * actually been sent; there is nothing to represent before that. The row is
 * looked up and updated IN PLACE on every re-arm (an `updateOrCreate`-shaped
 * write), never inserted twice for the same triple — this is the
 * `user_notification_preferences` composite-unique family, deliberately NOT
 * the once-only `campaign_job_notifications` stamp family (that family never
 * updates a row after its first write; this one is rewritten every 30+
 * minutes for an active thread).
 *
 * No index beyond the unique constraint — every lookup is by the full
 * composite key, never a partial scan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_email_debounces', function (Blueprint $table): void {
            $table->id();

            $table->string('thread_type', 64);
            $table->unsignedBigInteger('thread_id');

            $table->unsignedBigInteger('recipient_user_id');
            $table->foreign('recipient_user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();

            $table->timestampTz('last_emailed_at');

            $table->timestamps();
        });

        Schema::table('message_email_debounces', function (Blueprint $table): void {
            $table->unique(
                ['thread_type', 'thread_id', 'recipient_user_id'],
                'unique_message_email_debounce_thread_recipient',
            );
        });
    }

    /**
     * Structurally a true inverse; the CONTENT cannot be restored. Dropping
     * this table discards every recorded `last_emailed_at`, so a
     * rollback-then-redeploy re-arms every (thread, recipient) pair
     * immediately — the next message on any thread that was inside its
     * 30-minute window at drop time would email again right away. Snapshot
     * first if this is ever rolled back against a populated database.
     */
    public function down(): void
    {
        Schema::dropIfExists('message_email_debounces');
    }
};
