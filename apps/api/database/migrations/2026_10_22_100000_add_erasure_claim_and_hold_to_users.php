<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * AH-104c C1-1 — the erasure claim and the hold, stored on the user.
 *
 * `erasure_started_at` is the CLAIM: the moment an admin's Erase (or the shell command)
 * took the account. From then the creator can no longer cancel and the sign-in answers as
 * for an unknown account; it is set in one locked transaction by `ErasureClaimer` and by
 * nothing else, BEFORE the first irreversible step (the run deletes the S3 files before its
 * database transaction, so a lock inside that transaction would come too late).
 *
 * `erasure_held_at` / `erasure_held_by_user_id` are the HOLD: an admin's "do not erase
 * this account" (a legal hold, a dispute). The gate reads them in C1; the endpoints that
 * write them arrive in C2. The holder is an admin's id, never the subject's data.
 *
 * §5.40: additive-nullable only. No default, no backfill, no index (the queue's reads
 * start from `deletion_requested_at` / `deletion_scheduled_for`; the table has a few
 * thousand rows). Nothing is claimed or held by deploying this, and no route can claim.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('erasure_started_at')->nullable();
            $table->timestamp('erasure_held_at')->nullable();
            $table->foreignId('erasure_held_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('erasure_held_by_user_id');
            $table->dropColumn(['erasure_started_at', 'erasure_held_at']);
        });
    }
};
