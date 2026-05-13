<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * Creates the `agency_creator_relations` table per docs/03-DATA-MODEL.md §6.
 * Sprint 3 Chunk 1 migration #14.
 *
 * D-pause-1 (Chunk 1 read-pass divergence): docs/reviews/sprint-1-self-review.md §a
 * lists this table as already shipped during Sprint 1's multi-tenancy
 * primitives chunk. Verification during the Chunk 1 read pass found no
 * migration, no model, no code references outside docs. Standing standard
 * #34 (cross-chunk handoff verification) caught the historical-record drift
 * before Chunk 1 built against a nonexistent table. The table is created
 * here with the FULL P1 column set per spec §6 plus the Sprint-3
 * invitation columns (per kickoff §1.1) in a single migration. A
 * tech-debt entry tracks the Sprint 1 self-review reconciliation as a
 * future doc-cleanup pass.
 *
 * Per-agency view of a creator. Composite tenant scope: agency_id is the
 * tenant column for BelongsToAgency; the (agency_id, creator_id) pair is
 * unique.
 *
 * relationship_status enum:
 *   - 'roster'   — creator is on the agency's active roster
 *   - 'external' — creator engaged for a campaign without joining roster
 *   - 'prospect' — invited but hasn't completed the wizard yet (Sprint 3)
 *
 * Invitation columns (active when relationship_status='prospect'):
 *   - invitation_token_hash    SHA-256 of the magic-link token. Q1 (b-mod):
 *                              nulled on acceptance; the unhashed token is
 *                              never stored.
 *   - invitation_expires_at    7 days from invitation_sent_at by default.
 *   - invitation_sent_at       When the invite email was queued.
 *   - invited_by_user_id       The agency member who sent the invite.
 *
 * Q1 (b-mod) lifecycle on acceptance:
 *   - relationship_status: 'prospect' → 'roster'
 *   - invitation_token_hash: <hash> → null (defense-in-depth)
 *   - invitation_expires_at, invitation_sent_at, invited_by_user_id:
 *     RETAINED as historical record (Sprint 6 / Sprint 13 surfaces).
 *
 * Deferred FK: blacklisted_by_user_id references users.id with SET NULL —
 * users exists, FK added here. blacklist_scope, blacklist_type follow
 * the spec enum domain (agency|brand, hard|soft).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_creator_relations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->unsignedBigInteger('agency_id');
            $table->foreign('agency_id')
                ->references('id')
                ->on('agencies')
                ->cascadeOnDelete();

            $table->unsignedBigInteger('creator_id');
            $table->foreign('creator_id')
                ->references('id')
                ->on('creators')
                ->cascadeOnDelete();

            $table->string('relationship_status', 16);

            // Blacklist columns (per spec §6).
            $table->boolean('is_blacklisted')->default(false);
            $table->string('blacklist_scope', 8)->nullable();
            $table->text('blacklist_reason')->nullable();
            $table->string('blacklist_type', 8)->nullable();
            $table->timestamp('blacklisted_at')->nullable();
            $table->unsignedBigInteger('blacklisted_by_user_id')->nullable();
            $table->foreign('blacklisted_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
            $table->timestamp('notification_sent_at')->nullable();

            // P2 columns — present from P1 with nullable defaults.
            $table->string('appeal_status', 16)->nullable();
            $table->timestamp('appeal_submitted_at')->nullable();

            $table->smallInteger('internal_rating')->nullable();
            $table->text('internal_notes')->nullable();
            $table->integer('total_campaigns_completed')->default(0);
            $table->bigInteger('total_paid_minor_units')->default(0);
            $table->timestamp('last_engaged_at')->nullable();

            // Sprint 3 — magic-link invitation columns (kickoff §1.1).
            // Active when relationship_status='prospect'.
            $table->char('invitation_token_hash', 64)->nullable();
            $table->timestamp('invitation_expires_at')->nullable();
            $table->timestamp('invitation_sent_at')->nullable();
            $table->unsignedBigInteger('invited_by_user_id')->nullable();
            $table->foreign('invited_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->timestamps();
        });

        Schema::table('agency_creator_relations', function (Blueprint $table): void {
            $table->unique(['agency_id', 'creator_id'], 'unique_agency_creator');
            $table->index(['agency_id', 'is_blacklisted'], 'idx_agency_creator_blacklisted');
            // Lookup index for invitation acceptance (token_hash uniqueness
            // is logical — only one prospect can hold a given hash at a
            // time; enforced by application logic, not DB constraint, so
            // historical retained hashes don't conflict).
            $table->index('invitation_token_hash', 'idx_agency_creator_invite_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_creator_relations');
    }
};
