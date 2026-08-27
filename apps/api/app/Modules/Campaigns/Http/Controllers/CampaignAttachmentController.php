<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Http\Controllers;

use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyFile;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Campaigns\Http\Requests\AttachCampaignFileRequest;
use App\Modules\Campaigns\Http\Requests\UpdateCampaignAttachmentRequest;
use App\Modules\Campaigns\Http\Resources\CampaignAttachmentResource;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAttachment;
use App\Modules\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Campaign attachments from the Files library, chunk B (AH-092). Per
 * docs/reviews/campaign-attachments-plan.md §2.3/§3.
 *
 *   GET    …/campaigns/{campaign}/attachments            index (unpaginated —
 *          a campaign's attachment list is small and bounded)
 *   POST   …/campaigns/{campaign}/attachments            attach (idempotent)
 *   PATCH  …/campaigns/{campaign}/attachments/{file}     toggle visibility
 *   DELETE …/campaigns/{campaign}/attachments/{file}     detach (hard delete)
 *
 * Read is gated on `CampaignPolicy::view` (any active member); attach,
 * toggle, and detach are campaign-MANAGEMENT acts and are gated on
 * `CampaignPolicy::update` (admin/manager) — the library's own delete matrix
 * governs the FILE, this gate governs the LINKAGE. No new policy class.
 *
 * `{file}` binds an {@see AgencyFile} by its existing ulid route key — there
 * is no ulid on `campaign_attachments` itself (see the model's docblock for
 * why); the file's identity is the only address a client needs, because the
 * campaign is already the route's parent segment.
 *
 * Every `{campaign}` AND `{file}` is explicitly re-checked against the path
 * `{agency}` before any Gate call — route-model binding resolves before
 * tenancy context (the AgencyFileController/CampaignAssignmentController
 * precedent) — mismatch 404s, never 403 (non-fingerprinting).
 */
final class CampaignAttachmentController
{
    public function index(Request $request, Agency $agency, Campaign $campaign): JsonResponse
    {
        $this->assertBelongsToAgency($campaign, $agency);
        Gate::authorize('view', $campaign);

        $attachments = $campaign->attachments()
            ->with(['agencyFile', 'attachedBy:id,ulid,name'])
            ->orderBy('created_at')
            ->get()
            // The Q1 race's dangling row (an attachment surviving a
            // concurrent soft-delete of its file, docs/tech-debt.md ›
            // AH-092): `agencyFile` resolves to null because `AgencyFile`'s
            // `SoftDeletes` global scope excludes the trashed row from the
            // eager load, and `CampaignAttachmentResource` assumes a live
            // file. Filtered here rather than dereferenced, so the same
            // "invisible, not a 500" posture D4's `creatorVisible()` scope
            // already gives creators also holds on the agency's own list —
            // an operator sees nothing to act on, not an error page, and can
            // still reach the real fix (detach) once a fresh attach/delete
            // resolves the row cleanly.
            ->filter(fn (CampaignAttachment $attachment): bool => $attachment->agencyFile !== null)
            ->values();

        return response()->json([
            'data' => CampaignAttachmentResource::collection($attachments)->resolve($request),
        ]);
    }

    /**
     * Idempotent — attaching an already-attached file is a 200 no-op (no
     * second row, no second audit row, and the replay does NOT re-apply a
     * `visible_to_creators` value to the existing row — attach and toggle are
     * different write intents, §2.3).
     */
    public function store(AttachCampaignFileRequest $request, Agency $agency, Campaign $campaign): JsonResponse
    {
        $this->assertBelongsToAgency($campaign, $agency);
        Gate::authorize('update', $campaign);

        $file = $this->resolveFile((string) $request->string('agency_file_id'));
        $this->assertFileBelongsToAgency($file, $agency);

        /** @var User $actor */
        $actor = $request->user();

        $attachment = CampaignAttachment::query()->firstOrCreate(
            ['campaign_id' => $campaign->id, 'agency_file_id' => $file->id],
            [
                'visible_to_creators' => $request->boolean('visible_to_creators', false),
                'attached_by_user_id' => $actor->id,
            ],
        );

        if ($attachment->wasRecentlyCreated) {
            Audit::log(
                action: AuditAction::CampaignAttachmentAdded,
                subject: $campaign,
                after: ['agency_file_id' => $file->id, 'visible_to_creators' => $attachment->visible_to_creators],
            );
        }

        $attachment->setRelation('agencyFile', $file);

        return (new CampaignAttachmentResource($attachment))
            ->response()
            ->setStatusCode($attachment->wasRecentlyCreated ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    public function update(UpdateCampaignAttachmentRequest $request, Agency $agency, Campaign $campaign, AgencyFile $file): JsonResponse
    {
        $this->assertBelongsToAgency($campaign, $agency);
        $this->assertFileBelongsToAgency($file, $agency);
        Gate::authorize('update', $campaign);

        $attachment = $this->resolveAttachment($campaign, $file);

        $before = $attachment->visible_to_creators;
        $after = $request->boolean('visible_to_creators');

        if ($before !== $after) {
            $attachment->update(['visible_to_creators' => $after]);

            Audit::log(
                action: AuditAction::CampaignAttachmentVisibilityChanged,
                subject: $campaign,
                before: ['visible_to_creators' => $before],
                after: ['visible_to_creators' => $after],
            );
        }

        $attachment->setRelation('agencyFile', $file);

        return (new CampaignAttachmentResource($attachment))->response();
    }

    public function destroy(Request $request, Agency $agency, Campaign $campaign, AgencyFile $file): JsonResponse
    {
        $this->assertBelongsToAgency($campaign, $agency);
        $this->assertFileBelongsToAgency($file, $agency);
        Gate::authorize('update', $campaign);

        $attachment = $this->resolveAttachment($campaign, $file);
        $attachment->delete();

        Audit::log(
            action: AuditAction::CampaignAttachmentRemoved,
            subject: $campaign,
            before: ['agency_file_id' => $file->id],
        );

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    private function resolveAttachment(Campaign $campaign, AgencyFile $file): CampaignAttachment
    {
        $attachment = CampaignAttachment::query()
            ->where('campaign_id', $campaign->id)
            ->where('agency_file_id', $file->id)
            ->first();

        if ($attachment === null) {
            abort(404);
        }

        return $attachment;
    }

    /**
     * Resolve a library file by its ULID, or 404 — never a 422 that would
     * let an operator fingerprint another agency's file ulids.
     */
    private function resolveFile(string $fileUlid): AgencyFile
    {
        $file = AgencyFile::query()->where('ulid', $fileUlid)->first();

        if ($file === null) {
            abort(404);
        }

        return $file;
    }

    /**
     * 404, not 403 — the same non-fingerprinting posture as every other
     * agency-scoped route (docs/05-SECURITY-COMPLIANCE.md §7). Load-bearing:
     * two independent FKs alone would not stop an agency-A file linking to an
     * agency-B campaign (docs/reviews/campaign-attachments-plan.md §2.1).
     */
    private function assertBelongsToAgency(Campaign $campaign, Agency $agency): void
    {
        if ($campaign->agency_id !== $agency->id) {
            abort(404);
        }
    }

    private function assertFileBelongsToAgency(AgencyFile $file, Agency $agency): void
    {
        if ($file->agency_id !== $agency->id) {
            abort(404);
        }
    }
}
