<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * Adds the KYC-method discriminator + manual-verify attribution to the
 * `creators` table — Sprint 4 Chunk 3 (Cluster 1, D-c3-4).
 *
 * Two columns:
 *
 *   - kyc_method (vendor|manual, nullable until identity is cleared):
 *     records WHICH path cleared identity verification. Stamped `vendor`
 *     by ProcessKycWebhookJob whenever it writes kyc_status (D-c3-5) and
 *     `manual` by the admin verify-identity endpoint (D-c3-3). Nullable
 *     because a fresh creator has no method yet (kyc_status='none'). The
 *     per-attempt vendor history still lives in creator_kyc_verifications;
 *     this denormalised column is the always-populated discriminator on
 *     whichever path clears identity.
 *
 *   - verified_by_user_id (FK users.id, SET NULL): the platform_admin who
 *     manually cleared identity. Load-bearing compliance attribution for
 *     the permanent admin override (a different concept from the tax
 *     profile's verified_by_user_id — KYC needs its own per inventory B3).
 *     Mirrors the approved_by_user_id FK convention (nullOnDelete) on this
 *     same table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creators', function (Blueprint $table): void {
            $table->string('kyc_method', 16)->nullable()->after('kyc_verified_at');
            $table->unsignedBigInteger('verified_by_user_id')->nullable()->after('kyc_method');
            $table->foreign('verified_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('creators', function (Blueprint $table): void {
            $table->dropForeign(['verified_by_user_id']);
            $table->dropColumn(['kyc_method', 'verified_by_user_id']);
        });
    }
};
