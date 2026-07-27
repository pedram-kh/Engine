<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * Jobs Board chunk 3 (AH-056, D7) — the per-(campaign, creator) once-only
 * notification stamp. A NEW table, so no existing row is read or rewritten.
 *
 * Why its own table rather than a column somewhere. The house once-only
 * mechanism is a stamp COLUMN on an existing row (AH-048's
 * `creators.incomplete_nudge_sent_at`; AH-035's `previously_declined`), but
 * both of those stamp a SINGLE-AXIS fact. "Have we told this creator about
 * this job?" is two-axis, and neither I2 option makes it free:
 *
 *   - `campaign_applications` cannot host it — the stamp must exist BEFORE the
 *     creator applies, so hosting it there would mean creating application
 *     rows for people who have not applied, which destroys the table's meaning
 *     and its unique-pair semantics.
 *   - `campaign_assignments` cannot host it — there is no row until an agency
 *     invites, which is strictly after the notification.
 *
 * So the stamp is the arc's third table, named as such (D7).
 *
 * The unique composite IS the mechanism: the fan-out inserts a stamp per
 * recipient, and a re-list simply finds every row already present and sends
 * nothing (D6 — "re-list never re-notifies"). `notified_at` carries the when.
 *
 * FK delete rules diverge from `campaign_assignments` deliberately: BOTH sides
 * CASCADE. A stamp anchors no money, no contract and no audit trail — it is
 * operational bookkeeping — so it should not RESTRICT the deletion of anything,
 * and a stamp whose campaign or creator is gone is meaningless.
 *
 * No `ulid` (never route-bound, never emitted in a resource), no tenancy trait
 * (never read by an agency-scoped query — the fan-out service scopes by
 * `campaign_id`), and no `created_at`/`updated_at` (`notified_at` is the only
 * time this table has to know).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_job_notifications', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('campaign_id');
            $table->foreign('campaign_id')
                ->references('id')
                ->on('campaigns')
                ->cascadeOnDelete();

            $table->unsignedBigInteger('creator_id');
            $table->foreign('creator_id')
                ->references('id')
                ->on('creators')
                ->cascadeOnDelete();

            $table->timestamp('notified_at');
        });

        Schema::table('campaign_job_notifications', function (Blueprint $table): void {
            $table->unique(['campaign_id', 'creator_id'], 'unique_job_notification_campaign_creator');
        });
    }

    /**
     * Structurally a true inverse; the CONTENT cannot be restored. Dropping
     * this table discards the once-only record, so re-running up() and then
     * re-listing a campaign would notify creators who have already been
     * notified about that job. Snapshot first if this is ever rolled back
     * against a populated database.
     */
    public function down(): void
    {
        Schema::dropIfExists('campaign_job_notifications');
    }
};
