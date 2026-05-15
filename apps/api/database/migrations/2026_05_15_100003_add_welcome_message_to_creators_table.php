<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * Adds `welcome_message` to the `creators` table — Sprint 3 Chunk 4.
 *
 * Persisted by POST /api/v1/admin/creators/{creator}/approve when the
 * admin chooses to include an optional welcome note. Surfaced (Sprint 4+)
 * on the creator-side approved dashboard. Free-text, nullable, max 1000
 * chars (validated by AdminApproveCreatorRequest; column type is text to
 * give us breathing room if the cap relaxes in Phase 2).
 *
 * NOT audit-allowlisted: free-text containing the admin's tone-of-voice
 * shouldn't appear in audit before/after snapshots (the audit reason
 * field already captures the structured note via the metadata column).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creators', function (Blueprint $table): void {
            $table->text('welcome_message')->nullable()->after('rejection_reason');
        });
    }

    public function down(): void
    {
        Schema::table('creators', function (Blueprint $table): void {
            $table->dropColumn('welcome_message');
        });
    }
};
