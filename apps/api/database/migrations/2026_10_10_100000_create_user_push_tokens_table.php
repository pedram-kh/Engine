<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * AH-102 — device push tokens for the creator app. A NEW, additive table
 * (`CREATE TABLE` only): no existing row anywhere is read, rewritten or
 * backfilled. Its only coupling to live data is two foreign keys FROM this
 * table TO `users` and `personal_access_tokens`; nothing about those tables
 * changes.
 *
 * Shape (plan §3.2):
 *  - ONE token per device login: `personal_access_token_id` is NOT NULL and
 *    UNIQUE, so an FCM token rotation on the same login is a replace, and a
 *    second device is simply a second PAT / second row.
 *  - `token` is UNIQUE: upsert-by-token value, so a handset that changes
 *    hands (logout → another account logs in) MOVES the row instead of
 *    leaving two accounts addressable at one device.
 *  - Revocation is DB-level: the FK to the PAT cascades on delete, so every
 *    path that revokes a bearer (`logoutToken`, the password-reset mass
 *    revoke, the account-lockout revoke) silently drops its push token with
 *    it — including the mass `->delete()` paths that fire no model events.
 *  - `invalidated_at` / `invalidation_reason` record a provider-reported dead
 *    token (FCM UNREGISTERED etc.) without deleting the evidence.
 *
 * Tenancy: user-global like `user_notification_preferences` — no `agency_id`;
 * own-record only, reached only through the bearer-only `/me/push-tokens`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_push_tokens', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();

            $table->unsignedBigInteger('personal_access_token_id');
            $table->foreign('personal_access_token_id')
                ->references('id')
                ->on('personal_access_tokens')
                ->cascadeOnDelete();

            $table->string('platform', 16);
            $table->string('token', 512);
            $table->string('app_version', 32)->nullable();

            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampTz('invalidated_at')->nullable();
            $table->string('invalidation_reason', 64)->nullable();

            $table->timestamps();

            $table->unique('personal_access_token_id', 'unique_user_push_tokens_pat');
            $table->unique('token', 'unique_user_push_tokens_token');
            $table->index(['user_id', 'invalidated_at'], 'idx_user_push_tokens_user_live');
        });
    }

    /**
     * Structurally a true inverse; the CONTENT cannot be restored. Dropping
     * this table discards every registered device token, and devices only
     * re-register when the creator app next resumes with a live session — so
     * until each device is next opened, it receives no push. Snapshot first if
     * this is ever rolled back against a populated database.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_push_tokens');
    }
};
