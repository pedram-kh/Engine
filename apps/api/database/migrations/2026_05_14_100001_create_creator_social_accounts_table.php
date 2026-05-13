<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * Creates the `creator_social_accounts` table per docs/03-DATA-MODEL.md §5.
 * Sprint 3 Chunk 1 migration #8.
 *
 * One row per (creator, platform) pair — enforced by the
 * unique_creator_social_creator_platform composite unique index.
 *
 * Encryption: oauth_access_token + oauth_refresh_token are encrypted at
 * the application layer per spec §23. Cast lives on
 * App\Modules\Creators\Models\CreatorSocialAccount.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creator_social_accounts', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->unsignedBigInteger('creator_id');
            $table->foreign('creator_id')
                ->references('id')
                ->on('creators')
                ->cascadeOnDelete();

            $table->string('platform', 16);
            $table->string('platform_user_id', 128);
            $table->string('handle', 128);
            $table->string('profile_url', 2048);

            // Encrypted at the application layer (#23 of data-model spec).
            // The text type accommodates the encrypted ciphertext envelope
            // which is significantly larger than the underlying token.
            $table->text('oauth_access_token')->nullable();
            $table->text('oauth_refresh_token')->nullable();
            $table->timestamp('oauth_expires_at')->nullable();

            $table->timestamp('last_synced_at')->nullable();
            $table->string('sync_status', 16)->default('pending');

            // Cached metrics: followers, following, posts_count, engagement_rate.
            $table->jsonb('metrics')->nullable();
            $table->jsonb('audience_demographics')->nullable();

            $table->boolean('is_primary')->default(false);

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('creator_social_accounts', function (Blueprint $table): void {
            $table->unique(['creator_id', 'platform'], 'unique_creator_social_creator_platform');
            $table->index(['platform', 'handle'], 'idx_creator_social_handle_platform');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creator_social_accounts');
    }
};
