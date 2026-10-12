<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * AH-102b — the push delivery attempt log behind `PushDeliveryRecorder`. A NEW,
 * additive table (`CREATE TABLE` only): no existing row anywhere is read,
 * rewritten or backfilled. Its only coupling to live data is one foreign key
 * FROM this table TO `users`; nothing about `users` changes.
 *
 * Shape (plan §3.1):
 *  - ONE row per send ATTEMPT (≤ 3 per job). "Terminal" is
 *    `outcome <> 'retryable' OR final`; the admin tiles count terminal rows.
 *  - APPEND-ONLY: no `updated_at`; `created_at` is the only timestamp.
 *  - `user_id` FKs `users` with ON DELETE CASCADE: hard-deleting a user (the
 *    AH-104 erasure path) removes their delivery history with them.
 *  - `user_push_token_id` is a plain nullable column with NO foreign key on
 *    purpose: token rows are deleted on logout / bearer revoke, and an
 *    attempt that happened must outlive the device row it was sent to.
 *  - `notification_ulid` is the join key to `notifications.ulid` for the
 *    admin "in-app: read / unread / none" column. No foreign key: the
 *    notification may be deleted or pruned independently.
 *  - NEVER stored: the FCM token, message title/body/data, IP, user-agent.
 *    `detail` is only ever written for non-delivered outcomes and is capped
 *    again in the recorder.
 *
 * Indexes (exactly three, each justified):
 *  - (user_id, created_at)  — "this creator's deliveries" in the admin view
 *  - (outcome, created_at)  — outcome tiles and the Failures preset
 *  - (created_at)           — retention scans (`push:prune-deliveries`) and
 *                             the footer's `min(created_at)`
 *
 * DEPLOY NOTE: the production application role (`engine_c_user`) needs an
 * explicit GRANT on this table AND its id sequence (SELECT, INSERT, UPDATE,
 * DELETE; USAGE on `push_deliveries_id_seq`) — DELETE is for the prune
 * command. Without it every insert fails; the recorder contains that failure
 * (no duplicate push), but no rows are written. See the AH-102b deploy-log
 * entry for the has_table_privilege read-back.
 *
 * Tenancy: user-global like `user_push_tokens` — no `agency_id`; reachable
 * only through the platform-admin read endpoints.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_deliveries', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();

            $table->unsignedBigInteger('user_push_token_id')->nullable();

            $table->string('platform', 16);
            $table->string('notification_type', 64);
            $table->char('notification_ulid', 26)->nullable();

            $table->string('outcome', 16);
            $table->unsignedSmallInteger('attempt');
            $table->boolean('final')->default(false);

            $table->string('code', 64)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('detail', 200)->nullable();

            $table->timestampTz('created_at')->useCurrent();

            $table->index(['user_id', 'created_at'], 'idx_push_deliveries_user_created');
            $table->index(['outcome', 'created_at'], 'idx_push_deliveries_outcome_created');
            $table->index('created_at', 'idx_push_deliveries_created');
        });
    }

    /**
     * Structurally a true inverse; the CONTENT cannot be restored. Dropping
     * this table discards the whole delivery history (≤ the retention window).
     * Snapshot first if this is ever rolled back against a populated database.
     */
    public function down(): void
    {
        Schema::dropIfExists('push_deliveries');
    }
};
