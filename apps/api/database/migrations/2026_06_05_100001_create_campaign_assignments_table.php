<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * Creates the `campaign_assignments` table — the heart of the system
 * (Sprint 8 Chunk 1, D-2). One assignment = one creator engaged on one
 * campaign. Per docs/03-DATA-MODEL.md §7 (campaign_assignments).
 *
 * FK delete rules: `campaign_id` CASCADE (an assignment cannot outlive its
 * campaign); `creator_id` RESTRICT (an engaged creator anchors money + a
 * contract trail); denormalized `agency_id` / `brand_id` RESTRICT (the
 * tenancy + reporting denormalization). User-attribution columns
 * (`invited_by_user_id`, `cancelled_by_user_id`) nullOnDelete.
 *
 * Money is integer minor units (D-3). The D-7 counter addition
 * (`countered_fee_minor_units` + `countered_fee_currency`) is NET-NEW vs the
 * data model (which has only `agreed_fee_*`): recording the creator's
 * counter distinctly preserves the agency's original offer rather than
 * overwriting it. Logged in docs/03-DATA-MODEL.md as a Sprint-8 addition.
 *
 * Deferred FK: `payment_id` is a nullable column with NO foreign key — the
 * `payments` table does not exist until Sprint 10 (escrow). `contract_id`
 * references the existing `contracts` table (nullOnDelete).
 *
 * The status column (varchar(32)) is driven exclusively by
 * CampaignAssignmentStateMachine — no controller flips it directly (D-5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_assignments', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            // Denormalized tenant scope (BelongsToAgency).
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

            // Denormalized brand (reporting + brand-scoped matching, Chunk 2).
            $table->unsignedBigInteger('brand_id');
            $table->foreign('brand_id')
                ->references('id')
                ->on('brands')
                ->restrictOnDelete();

            $table->unsignedBigInteger('creator_id');
            $table->foreign('creator_id')
                ->references('id')
                ->on('creators')
                ->restrictOnDelete();

            // See CampaignAssignmentStateMachine — varchar(32).
            $table->string('status', 32);

            // Timestamp trail (only stamped where a column exists; some
            // transitions — contracted/producing/revision_requested — have no
            // dedicated column and are recorded by the audit row + status).
            $table->timestamp('invited_at')->nullable();
            $table->unsignedBigInteger('invited_by_user_id')->nullable();
            $table->foreign('invited_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('accepted_at')->nullable();

            $table->unsignedBigInteger('contract_id')->nullable();
            $table->foreign('contract_id')
                ->references('id')
                ->on('contracts')
                ->nullOnDelete();

            // Money — integer minor units (D-3).
            $table->bigInteger('agreed_fee_minor_units')->nullable();
            $table->char('agreed_fee_currency', 3)->nullable();
            // D-7 net-new — the creator's counter-offer, recorded distinctly
            // from agreed_fee so the agency's original offer is preserved.
            $table->bigInteger('countered_fee_minor_units')->nullable();
            $table->char('countered_fee_currency', 3)->nullable();
            $table->bigInteger('markup_minor_units')->nullable();
            $table->bigInteger('total_charged_to_brand_minor_units')->nullable();

            // Specific deliverable list (overrides the campaign brief if set).
            $table->jsonb('deliverables')->nullable();

            $table->timestamp('posting_due_at')->nullable();
            $table->timestamp('submitted_draft_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('verified_live_at')->nullable();

            // Deferred FK — payments table lands in Sprint 10 (escrow).
            $table->unsignedBigInteger('payment_id')->nullable();

            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancelled_reason')->nullable();
            $table->unsignedBigInteger('cancelled_by_user_id')->nullable();
            $table->foreign('cancelled_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            // Internal agency notes (free-text — excluded from the audit allowlist).
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        // Per docs/03-DATA-MODEL.md §7 indexes.
        Schema::table('campaign_assignments', function (Blueprint $table): void {
            $table->unique(['campaign_id', 'creator_id'], 'unique_assignment_campaign_creator');
            $table->index(['agency_id', 'status'], 'idx_assignments_agency_status');
            $table->index(['creator_id', 'status'], 'idx_assignments_creator_status');
            $table->index('brand_id', 'idx_assignments_brand_id');
            $table->index('posting_due_at', 'idx_assignments_dates');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_assignments');
    }
};
