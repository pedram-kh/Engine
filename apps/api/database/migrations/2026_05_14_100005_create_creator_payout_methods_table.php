<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * Creates the `creator_payout_methods` table per docs/03-DATA-MODEL.md §5.
 * Sprint 3 Chunk 1 migration #12.
 *
 * Phase 1 supports Stripe Connect Express only (provider='stripe_connect').
 * paypal/wise are P3+ — the column carries the value but no code path
 * accepts non-stripe values during P1 (validated in the wizard form
 * request).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creator_payout_methods', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->unsignedBigInteger('creator_id');
            $table->foreign('creator_id')
                ->references('id')
                ->on('creators')
                ->cascadeOnDelete();

            $table->string('provider', 32);
            $table->string('provider_account_id', 128);
            $table->char('currency', 3);
            $table->boolean('is_default')->default(false);
            $table->string('status', 16)->default('pending');
            $table->timestamp('verified_at')->nullable();

            $table->timestamps();
        });

        Schema::table('creator_payout_methods', function (Blueprint $table): void {
            $table->index(['creator_id', 'is_default'], 'idx_payout_creator_default');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creator_payout_methods');
    }
};
