<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * AH-119 (Messages v2), A3 — the member who owns a creator relationship, which the unified inbox's
 * "Mine" filter reads. ONE nullable column plus an index; a nullable add without a default is
 * metadata-only on Postgres, so none of the existing relation rows is read or rewritten. No backfill:
 * the inbox reads `coalesce(owner_user_id, invited_by_user_id)`, so every relation that exists today
 * already has an effective owner where it had an inviter.
 *
 * `nullOnDelete`: removing a user must not remove or block the relationship; the relation simply
 * falls back to the inviter, then to "unowned" (shown under All only).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agency_creator_relations', function (Blueprint $table): void {
            $table->foreignId('owner_user_id')->nullable()->after('invited_by_user_id')
                ->constrained('users')->nullOnDelete();
            $table->index(['agency_id', 'owner_user_id'], 'idx_acr_agency_owner');
        });
    }

    /**
     * Honest inverse, with one thing it cannot restore: dropping the column discards every
     * reassignment made since (relations fall back to their inviter). Re-running up() restores the
     * STRUCTURE only. Snapshot first if this is ever rolled back against a populated database.
     */
    public function down(): void
    {
        Schema::table('agency_creator_relations', function (Blueprint $table): void {
            $table->dropIndex('idx_acr_agency_owner');
            $table->dropConstrainedForeignId('owner_user_id');
        });
    }
};
