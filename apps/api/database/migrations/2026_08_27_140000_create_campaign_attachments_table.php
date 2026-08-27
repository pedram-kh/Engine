<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\AgencyFile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * Creates `campaign_attachments` — Campaign attachments from the Files
 * library, chunk B (AH-092). Links an existing {@see AgencyFile}
 * to a campaign; a NEW, additive-only table, empty at deploy. Per
 * docs/reviews/campaign-attachments-plan.md §2.1. Deliberately replaces, not
 * migrates, the untyped `campaigns.brief.attachments` JSON slot — that field
 * is left exactly as it is; nothing reads or writes it from here.
 *
 * No `agency_id` column: both parents (`campaigns`, `agency_files`) already
 * carry the tenant, and the row is only ever queried through one of them. The
 * attach-write's explicit same-agency structural check (both models resolved
 * under the path agency, mismatch 404) is what actually prevents an agency-A
 * file linking to an agency-B campaign — two independent FKs alone would not.
 *
 * No `ulid` column and no route addresses this row by its own identity — a
 * deliberate departure from `campaign_applications`, which earns a ulid
 * because it carries an independent status machine this table does not have.
 * `campaign_attachments` is binary (exists, plus one boolean) — the
 * `talent_pool_creators` shape: nested-addressed via the CHILD's own ulid
 * (`campaigns/{campaign}/attachments/{file}`, `{file}` binding AgencyFile by
 * its existing ulid route key).
 *
 * FK delete rules, per the AH-091 contract:
 *   - `campaign_id` CASCADE — a join row has no meaning after its campaign is
 *     hard-deleted (the `campaign_applications` precedent).
 *   - `agency_file_id` RESTRICT — cascade would silently erase the attachment
 *     row during a hard delete, contradicting chunk A's shipped "attached
 *     means delete returns 409 agency_file.attached" contract
 *     (AgencyFileController::destroy(), `hasAttachments()`). RESTRICT is the
 *     only posture consistent with that already-shipped API.
 *   - `attached_by_user_id` SET NULL — attribution only, never blocking
 *     (the AgencyFile.uploaded_by_user_id precedent).
 *
 * `visible_to_creators` defaults FALSE. The name is deliberately broader than
 * the UI's "Visible to applicants" label — it also gates an ASSIGNED
 * creator's access via the assignment-detail door, not only the Jobs Board
 * applicant door. See CampaignAttachment's own docblock.
 *
 * No `deleted_at` — detach is a HARD delete (a linkage, not content); there
 * is no row-removing path that should retain a ghost row. This also means
 * `AgencyFile::hasAttachments()`'s query needs no soft-delete clause: every
 * row in this table is, by construction, "live".
 *
 * Indexes: the unique pair is `campaign_id`-led (idempotent attach, one
 * visibility state per pair — `unique_campaign_attachment`) and is NOT the
 * right index for the file-centric `hasAttachments()` lookup, which gets its
 * own single-column index led by `agency_file_id`.
 *
 * down() honesty: this table is new, so dropping it is a true structural AND
 * content inverse — it loses only this chunk's own attach/visibility
 * history. No pre-existing `campaigns` or `agency_files` row, or S3 object,
 * is touched in either direction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_attachments', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('campaign_id');
            $table->foreign('campaign_id')
                ->references('id')
                ->on('campaigns')
                ->cascadeOnDelete();

            $table->unsignedBigInteger('agency_file_id');
            $table->foreign('agency_file_id')
                ->references('id')
                ->on('agency_files')
                ->restrictOnDelete();

            $table->boolean('visible_to_creators')->default(false);

            $table->unsignedBigInteger('attached_by_user_id')->nullable();
            $table->foreign('attached_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->timestamps();
        });

        Schema::table('campaign_attachments', function (Blueprint $table): void {
            $table->unique(['campaign_id', 'agency_file_id'], 'unique_campaign_attachment');
            $table->index('agency_file_id', 'idx_campaign_attachments_agency_file_id');
        });
    }

    /**
     * True structural and content inverse — see the class docblock. This
     * table is new; nothing pre-existing is reachable from here.
     */
    public function down(): void
    {
        Schema::dropIfExists('campaign_attachments');
    }
};
