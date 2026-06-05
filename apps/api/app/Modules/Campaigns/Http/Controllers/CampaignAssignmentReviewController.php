<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Http\Controllers;

use App\Core\Errors\ErrorResponse;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Enums\DraftReviewStatus;
use App\Modules\Campaigns\Exceptions\AssignmentTransitionException;
use App\Modules\Campaigns\Http\Resources\CampaignDraftResource;
use App\Modules\Campaigns\Http\Resources\CampaignPostedContentResource;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Campaigns\Models\CampaignDraft;
use App\Modules\Campaigns\Models\CampaignPostedContent;
use App\Modules\Campaigns\Services\CampaignAssignmentStateMachine;
use App\Modules\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The AGENCY-side review surface for a campaign assignment (Sprint 9 Chunk 2) —
 * the review half of the submission→review seam. Per-action endpoints (D-4):
 *
 *   GET  …/assignments/{assignment}                   show (D-7, the drawer read)
 *   POST …/assignments/{assignment}/approve           draft_submitted → approved
 *   POST …/assignments/{assignment}/request-revision  draft_submitted → revision_requested
 *   POST …/assignments/{assignment}/reject            draft_submitted → rejected (terminal)
 *
 * Authz is the `review` ability (admin + manager + staff, D-6). Each action
 * orchestrates, in ONE transaction (D-4): write the latest draft's review
 * trail (`reviewed_at` / `reviewed_by_user_id` / `review_status` /
 * `review_feedback` — the column-only fields Chunk 1 shipped) BEFORE driving
 * the machine, so the notification listener sees the persisted feedback. The
 * machine remains the sole status authority; its typed exceptions map to 422.
 */
final class CampaignAssignmentReviewController
{
    /**
     * GET /api/v1/agencies/{agency}/campaigns/{campaign}/assignments/{assignment}
     *
     * The agency-side detail (D-7): the assignment + its full draft version
     * history + any posted content (reusing the Chunk 1 resources with their
     * signed media URLs). Read-only.
     */
    public function show(Request $request, Agency $agency, Campaign $campaign, CampaignAssignment $assignment): JsonResponse
    {
        $this->assertBelongsToAgency($campaign, $agency);
        $this->assertAssignmentBelongsToCampaign($assignment, $campaign);
        Gate::authorize('review', $campaign);

        $assignment->loadMissing(['creator:id,ulid,display_name', 'campaign:id,ulid,name,brand_id', 'campaign.brand:id,name']);

        $drafts = CampaignDraft::query()
            ->where('assignment_id', $assignment->id)
            ->orderByDesc('version')
            ->get();

        $posted = CampaignPostedContent::query()
            ->where('assignment_id', $assignment->id)
            ->orderByDesc('id')
            ->get();

        $campaignModel = $assignment->campaign;

        return response()->json([
            'data' => [
                'id' => $assignment->ulid,
                'type' => 'campaign_assignment',
                'attributes' => [
                    'status' => $assignment->status->value,
                    'agreed_fee_minor_units' => $assignment->agreed_fee_minor_units,
                    'agreed_fee_currency' => $assignment->agreed_fee_currency,
                    'posting_due_at' => $assignment->posting_due_at?->toIso8601String(),
                    'submitted_draft_at' => $assignment->submitted_draft_at?->toIso8601String(),
                    'approved_at' => $assignment->approved_at?->toIso8601String(),
                    'posted_at' => $assignment->posted_at?->toIso8601String(),
                    'verified_live_at' => $assignment->verified_live_at?->toIso8601String(),
                    'creator' => $assignment->creator !== null ? [
                        'id' => $assignment->creator->ulid,
                        'display_name' => $assignment->creator->display_name,
                    ] : null,
                    'campaign' => $campaignModel !== null ? [
                        'id' => $campaignModel->ulid,
                        'name' => $campaignModel->name,
                        'brand_name' => $campaignModel->brand?->name,
                    ] : null,
                ],
                'relationships' => [
                    'drafts' => CampaignDraftResource::collection($drafts)->resolve($request),
                    'posted_content' => CampaignPostedContentResource::collection($posted)->resolve($request),
                ],
            ],
        ]);
    }

    /**
     * POST …/assignments/{assignment}/approve — no feedback (D-5).
     */
    public function approve(Request $request, Agency $agency, Campaign $campaign, CampaignAssignment $assignment, CampaignAssignmentStateMachine $machine): JsonResponse
    {
        return $this->review($request, $agency, $campaign, $assignment, $machine, DraftReviewStatus::Approved, null);
    }

    /**
     * POST …/assignments/{assignment}/request-revision — feedback REQUIRED (D-5).
     */
    public function requestRevision(Request $request, Agency $agency, Campaign $campaign, CampaignAssignment $assignment, CampaignAssignmentStateMachine $machine): JsonResponse
    {
        $validated = $request->validate([
            'review_feedback' => ['required', 'string', 'max:5000'],
        ]);

        return $this->review($request, $agency, $campaign, $assignment, $machine, DraftReviewStatus::RevisionRequested, (string) $validated['review_feedback']);
    }

    /**
     * POST …/assignments/{assignment}/reject — reason REQUIRED (D-5); terminal (D-1).
     */
    public function reject(Request $request, Agency $agency, Campaign $campaign, CampaignAssignment $assignment, CampaignAssignmentStateMachine $machine): JsonResponse
    {
        $validated = $request->validate([
            'review_feedback' => ['required', 'string', 'max:5000'],
        ]);

        return $this->review($request, $agency, $campaign, $assignment, $machine, DraftReviewStatus::Rejected, (string) $validated['review_feedback']);
    }

    /**
     * The shared orchestration: authz → resolve the latest draft → in one
     * transaction write the review trail then drive the machine. The machine's
     * source guard fail-closes a non-`draft_submitted` assignment (mapped 422).
     */
    private function review(
        Request $request,
        Agency $agency,
        Campaign $campaign,
        CampaignAssignment $assignment,
        CampaignAssignmentStateMachine $machine,
        DraftReviewStatus $reviewStatus,
        ?string $feedback,
    ): JsonResponse {
        $this->assertBelongsToAgency($campaign, $agency);
        $this->assertAssignmentBelongsToCampaign($assignment, $campaign);
        Gate::authorize('review', $campaign);

        /** @var User $actor */
        $actor = $request->user();

        // Fail-closed at the HTTP layer too (the machine guards again): only a
        // submitted draft is reviewable. A clearer 422 than the raw machine code.
        if ($assignment->status !== AssignmentStatus::DraftSubmitted) {
            return ErrorResponse::single(
                $request,
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'assignment.not_reviewable',
                'This assignment has no draft awaiting review.',
            );
        }

        $draft = CampaignDraft::query()
            ->where('assignment_id', $assignment->id)
            ->orderByDesc('version')
            ->first();

        if ($draft === null) {
            return ErrorResponse::single(
                $request,
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'assignment.no_draft',
                'No draft was found for this assignment.',
            );
        }

        try {
            DB::transaction(function () use ($draft, $assignment, $machine, $actor, $reviewStatus, $feedback): void {
                // Write the review trail FIRST (column-only fields shipped in
                // Chunk 1) so the transition event's notification listener reads
                // the persisted feedback.
                $draft->review_status = $reviewStatus;
                $draft->reviewed_at = now();
                $draft->reviewed_by_user_id = $actor->id;
                $draft->review_feedback = $feedback;
                $draft->save();

                $context = ['draft_id' => $draft->ulid, 'version' => $draft->version];

                match ($reviewStatus) {
                    DraftReviewStatus::Approved => $machine->approve($assignment, $actor, $context),
                    DraftReviewStatus::RevisionRequested => $machine->requestRevision($assignment, $actor, $context),
                    DraftReviewStatus::Rejected => $machine->rejectDraft($assignment, (string) $feedback, $actor, $context),
                    DraftReviewStatus::Pending => null,
                };
            });
        } catch (AssignmentTransitionException $e) {
            return ErrorResponse::single(
                $request,
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $e->errorCode,
                $e->getMessage(),
            );
        }

        return response()->json([
            'data' => (new CampaignDraftResource($draft->fresh() ?? $draft))->resolve($request),
            'meta' => ['code' => $this->metaCode($reviewStatus)],
        ]);
    }

    private function metaCode(DraftReviewStatus $status): string
    {
        return match ($status) {
            DraftReviewStatus::Approved => 'assignment.draft_approved',
            DraftReviewStatus::RevisionRequested => 'assignment.revision_requested',
            DraftReviewStatus::Rejected => 'assignment.draft_rejected',
            DraftReviewStatus::Pending => 'assignment.draft_pending',
        };
    }

    private function assertBelongsToAgency(Campaign $campaign, Agency $agency): void
    {
        if ($campaign->agency_id !== $agency->id) {
            abort(404);
        }
    }

    private function assertAssignmentBelongsToCampaign(CampaignAssignment $assignment, Campaign $campaign): void
    {
        if ($assignment->campaign_id !== $campaign->id) {
            abort(404);
        }
    }
}
