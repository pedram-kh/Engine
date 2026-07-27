<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * Jobs Board chunk 3 (AH-056, D1) — the creator's application to a listed
 * campaign. A NEW table, so no existing row is read or rewritten.
 *
 * D1 rules an application is a TABLE, not a pre-`invited` assignment state.
 * The decisive finding: `CampaignAssignmentController::store()` is idempotent
 * on the (campaign, creator) unique pair and returns an existing non-`declined`
 * row as-is — with a pre-`invited` state, an agency inviting a creator who had
 * already applied would silently get that row back and the OFFER WOULD NEVER
 * PERSIST. A data-loss-shaped hazard on the platform's most load-bearing
 * machine. A separate table keeps the two namespaces disjoint.
 *
 * Shape notes:
 *   - `agency_id` is DENORMALIZED from the parent campaign, mirroring
 *     `campaign_assignments` (BelongsToAgency + the same RESTRICT rule), so
 *     chunk 4's agency-side board column scopes for free. The drift surface is
 *     contained by setting it from the campaign at the single insert site,
 *     pinned by a test that the two can never diverge (Q8).
 *   - `status` is varchar(32), not varchar(16). The values are tiny
 *     (`pending` / `accepted` / `rejected`) but the `manually_verified`
 *     overflow lesson on `campaign_posted_content.verification_status`
 *     (varchar(16), 17 chars needed) is cheap to not repeat.
 *   - `note` is the creator's optional apply note. The ~1000-char cap lives in
 *     validation, not the column — the `cancelled_reason` / `notes` precedent
 *     on the assignments table.
 *   - **NO SoftDeletes, by design.** "No re-apply after rejection" is
 *     implemented as the RETAINED terminal row keeping the unique pair
 *     occupied (the `RelationshipStatus::Declined` precedent). A soft-delete
 *     column would invite a delete path that reopens re-apply; there is no
 *     row-removing path for this table in any chunk of the arc.
 *
 * FK delete rules mirror `campaign_assignments`: `campaign_id` CASCADE (an
 * application cannot outlive its campaign), `creator_id` + `agency_id`
 * RESTRICT.
 *
 * Indexes: the unique pair leads with `campaign_id`, so it already serves both
 * `withCount('applications')` on a board card and the per-caller
 * "have I applied?" correlated subquery. `idx_applications_agency_status` is
 * added now for chunk 4's agency-side board column — a locked arc decision, and
 * an index on a table that is empty at deploy costs nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_applications', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            // Denormalized tenant scope (BelongsToAgency), set from the parent
            // campaign at the insert site — never from ambient context, which a
            // creator caller does not have.
            $table->unsignedBigInteger('agency_id');
            $table->foreign('agency_id')
                ->references('id')
                ->on('agencies')
                ->restrictOnDelete();

            $table->unsignedBigInteger('campaign_id');
            $table->foreign('campaign_id')
                ->references('id')
                ->on('campaigns')
                ->cascadeOnDelete();

            $table->unsignedBigInteger('creator_id');
            $table->foreign('creator_id')
                ->references('id')
                ->on('creators')
                ->restrictOnDelete();

            // See CampaignApplicationStatus — pending | accepted | rejected.
            // A deliberately tiny lifecycle: terminal on both outcomes, no
            // edges out, no withdraw v1.
            $table->string('status', 32);

            // The creator's optional one-tap apply note (D1/D5).
            $table->text('note')->nullable();

            // Stamped when the agency accepts or rejects (chunk 4 writes it;
            // the column ships now so chunk 4 adds no migration).
            $table->timestamp('responded_at')->nullable();

            $table->timestamps();
        });

        Schema::table('campaign_applications', function (Blueprint $table): void {
            $table->unique(['campaign_id', 'creator_id'], 'unique_application_campaign_creator');
            $table->index(['agency_id', 'status'], 'idx_applications_agency_status');
        });
    }

    /**
     * Structurally a true inverse; the CONTENT cannot be restored. Dropping
     * this table discards every creator's application, their note, and the
     * retained rejected rows that implement the no-re-apply rule — so a
     * rollback followed by a re-run would let previously-rejected creators
     * apply again. Snapshot first if this is ever rolled back against a
     * populated database.
     */
    public function down(): void
    {
        Schema::dropIfExists('campaign_applications');
    }
};
