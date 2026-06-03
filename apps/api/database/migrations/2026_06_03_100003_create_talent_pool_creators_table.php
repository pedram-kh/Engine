<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * Creates the `talent_pool_creators` pivot — pool membership (Sprint 6
 * Chunk 2b, D-2b-2). Surfaced as a first-class pivot model
 * (App\Modules\TalentPools\Models\TalentPoolMembership) because it carries
 * `added_by_user_id`, exactly like AgencyMembership carries role/invited_by.
 *
 * House pivot style (agency_users / brand_creator_blacklists both do this):
 *   - surrogate `id` PK (NOT a composite PK), PLUS
 *   - unique(talent_pool_id, creator_id) named composite unique — one row per
 *     (pool, creator). The unique constraint is what makes the add endpoint
 *     idempotent (firstOrCreate → one row, never a duplicate / 500).
 *
 * On-delete:
 *   - talent_pool_id cascadeOnDelete — deleting a pool's row drops its
 *     memberships. (Soft-deleting a pool does NOT trigger this — soft delete
 *     only sets deleted_at; the membership rows survive for restore, D-2b-3.)
 *   - creator_id cascadeOnDelete — mirrors the live agency_creator_relations
 *     precedent: a deleted creator's memberships vanish.
 *   - added_by_user_id nullOnDelete — the house attribution pattern.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('talent_pool_creators', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('talent_pool_id');
            $table->foreign('talent_pool_id')
                ->references('id')
                ->on('talent_pools')
                ->cascadeOnDelete();

            $table->unsignedBigInteger('creator_id');
            $table->foreign('creator_id')
                ->references('id')
                ->on('creators')
                ->cascadeOnDelete();

            $table->unsignedBigInteger('added_by_user_id')->nullable();
            $table->foreign('added_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->timestamps();
        });

        Schema::table('talent_pool_creators', function (Blueprint $table): void {
            $table->unique(['talent_pool_id', 'creator_id'], 'unique_talent_pool_creator');
            $table->index('creator_id', 'idx_talent_pool_creators_creator_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('talent_pool_creators');
    }
};
