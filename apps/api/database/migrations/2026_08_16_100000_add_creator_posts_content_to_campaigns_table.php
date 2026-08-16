<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * Draft Workflow v2 chunk B (AH-069, D1) — the per-campaign posting toggle.
 * ONE additive boolean, `NOT NULL DEFAULT true`. A defaulted-boolean add is
 * metadata-only on Postgres 11+ (the AH-054 precedent in
 * 2026_07_27_100000_add_jobs_board_listing_to_campaigns.php), so **no existing
 * campaign row is read or rewritten** and every campaign that exists today
 * reads `true` — which is exactly today's behaviour: the creator posts the
 * deliverable and the assignment continues through posted → verified.
 *
 * WHY THE DEFAULT IS `true` AND NOT `false` (Q1, the two-layer design):
 *
 *   - The DB default is the SAFETY FLOOR. `true` means "this campaign expects
 *     the `assignment.posted_by_creator` step" — the lifecycle that has always
 *     shipped. Anything that creates a campaign WITHOUT naming the field (a
 *     direct API POST, a factory, a seeder, a future import) therefore falls
 *     back to the behaviour that already exists, which is the safe direction.
 *     The alternative — `default(false)` plus a backfill command — would have
 *     meant every campaign in the table reading OFF between `migrate` and the
 *     command, and an approval landing in that window drives a live assignment
 *     into `completed_on_approval`, a TERMINAL state no application path can
 *     leave (CampaignAssignmentStateMachine::cancel() refuses a terminal;
 *     markPosted() only accepts `approved`). Minutes of exposure, an
 *     unrecoverable row. The defaulted-ON column removes the window entirely
 *     and removes the need for a data mutation at all.
 *   - The PRODUCT default lives in the create FORM, not here: CampaignForm's
 *     create mode initialises the switch to OFF and always sends the field
 *     explicitly, so a campaign created through the UI hands off at approval.
 *     The two layers do not conflict — the form always names the value, so the
 *     column default only ever governs the paths that don't.
 *
 * No index: this is a per-campaign read on a row already loaded by ULID; no
 * query filters on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            // `after()` is a MySQL-only positioning hint and is ignored by the
            // Postgres grammar; it is kept for readability next to the sibling
            // behaviour toggle.
            $table->boolean('creator_posts_content')->default(true)->after('requires_per_campaign_contract');
        });
    }

    /**
     * Honest inverse, with one thing it cannot restore: dropping the column
     * discards every campaign's posting posture. Re-running up() restores the
     * STRUCTURE and resets every row to `true` (posting expected) — which is
     * the safe direction, but it is not the operator's data. Any campaign that
     * had been set to hand off at approval would silently expect posting again.
     * Take a snapshot before rolling this back against a populated database.
     */
    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropColumn('creator_posts_content');
        });
    }
};
