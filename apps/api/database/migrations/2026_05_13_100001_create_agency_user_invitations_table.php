<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * Creates the `agency_user_invitations` table. Tracks pending invitations
 * to join an agency before the invitee has accepted (and before a user
 * account may exist for them).
 *
 * Honest deviation #D2 (Sprint 2 Chunk 1): this table is NOT in
 * docs/03-DATA-MODEL.md. The `agency_users` table tracks accepted
 * memberships but requires a `user_id` FK, making it unsuitable for
 * pending invitations where the invitee may not yet have an account.
 * Building as a structurally-correct minimal extension.
 *
 * Token model (Q1 answer — single-use-with-retry-on-failure):
 *   - Only the SHA-256 hash of the unhashed token is stored.
 *   - `accepted_at` stamps when the invitation was successfully consumed.
 *   - Multiple acceptance ATTEMPTS before `accepted_at` is set are fine
 *     (supports network retries). Once stamped, all further attempts → 409.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_user_invitations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->unsignedBigInteger('agency_id');
            $table->foreign('agency_id')
                ->references('id')
                ->on('agencies')
                ->cascadeOnDelete();

            // The email address being invited. May or may not map to an
            // existing user at invitation time; the match happens at accept time.
            $table->string('email', 255);

            // Role the invitee will receive upon acceptance.
            $table->string('role', 32);

            // SHA-256 hash of the unhashed token. The unhashed token is only
            // ever in the magic-link email (and the test-helper response).
            $table->string('token_hash', 64)->unique();

            $table->timestamp('expires_at');

            // Null = pending, non-null = accepted (single-use-with-retry).
            $table->timestamp('accepted_at')->nullable();

            // The user who accepted the invitation; null until accepted.
            $table->unsignedBigInteger('accepted_by_user_id')->nullable();
            $table->foreign('accepted_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            // The agency member who sent the invitation.
            $table->unsignedBigInteger('invited_by_user_id');
            $table->foreign('invited_by_user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();

            $table->timestamps();
        });

        Schema::table('agency_user_invitations', function (Blueprint $table): void {
            $table->index(['agency_id', 'email'], 'idx_invitations_agency_email');
            // token_hash already has a unique index from the column definition.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_user_invitations');
    }
};
