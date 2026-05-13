<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * Creates the `creator_kyc_verifications` table per docs/03-DATA-MODEL.md §5.
 * Sprint 3 Chunk 1 migration #13.
 *
 * One row per verification attempt — historical record of KYC sessions for
 * a creator (initial pass, re-verifications, expired sessions, etc.).
 *
 * Encryption (#23 of data-model spec, applied via cast on the model):
 *   - decision_data (text type — full provider response stored as
 *     encrypted JSON blob)
 *
 * Webhook-event correlation lives on `integration_events` (Chunk 2 ships).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creator_kyc_verifications', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->unsignedBigInteger('creator_id');
            $table->foreign('creator_id')
                ->references('id')
                ->on('creators')
                ->cascadeOnDelete();

            $table->string('provider', 32);
            $table->string('provider_session_id', 255)->nullable();
            $table->string('provider_decision_id', 255)->nullable();
            $table->string('status', 16)->default('started');

            // Encrypted at the application layer. Stored as text to
            // accommodate ciphertext envelope.
            $table->text('decision_data')->nullable();

            $table->text('failure_reason')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();
        });

        Schema::table('creator_kyc_verifications', function (Blueprint $table): void {
            $table->index(['creator_id', 'status'], 'idx_kyc_creator_status');
            $table->index('provider_session_id', 'idx_kyc_provider_session');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creator_kyc_verifications');
    }
};
