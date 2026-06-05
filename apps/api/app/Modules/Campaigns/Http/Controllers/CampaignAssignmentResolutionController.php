<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Http\Controllers;

use App\Core\Errors\ErrorResponse;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Enums\PostedContentVerificationStatus;
use App\Modules\Campaigns\Exceptions\AssignmentTransitionException;
use App\Modules\Campaigns\Http\Resources\CampaignAssignmentResource;
use App\Modules\Campaigns\Mail\ResubmitRequestedMail;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Campaigns\Models\CampaignPostedContent;
use App\Modules\Campaigns\Services\CampaignAssignmentStateMachine;
use App\Modules\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;

/**
 * The AGENCY-side resolution surface for a FAILED auto-verification
 * (verification-resolution chunk, D-4/D-5/D-6) — the resolution half of
 * verification-failure. Sprint 9 detected the failure (the job set
 * `not_found`/`mismatch`, the assignment stayed `posted`, the agency was
 * notified) but the agency had no action to resolve it. The three actions:
 *
 *   POST …/manually-verify            ACT1 — posted → manually_verified (D-4)
 *   POST …/request-resubmit-fresh     ACT2 — posted → approved (D-5)
 *   POST …/request-resubmit-in-place  ACT3 — notify only, NO transition (D-6)
 *
 * Authz is the `review` ability (admin + manager + staff, the review/execute
 * precedent). Every action FAIL-CLOSES on the resolvable precondition: the
 * assignment is `posted` AND its latest posted-content row's verification
 * failed (`not_found`/`mismatch`). The machine remains the sole status
 * authority for ACT1/ACT2; its typed exceptions map to 422.
 *
 * ACT1/ACT2 inherit the audit row + {@see AssignmentTransitioned} dispatch from
 * the machine's commit() (D-9). ACT3 has NO edge, so it hand-writes its own
 * `assignment.resubmit_requested_in_place` audit row (the agency-request fact);
 * the creator's actual in-place URL edit audits SEPARATELY as its own mutation
 * (the creator-self posted-content PATCH). The resubmit notifications (ACT2 +
 * ACT3) are sent DIRECTLY here so the free-text feedback never rides the audit
 * snapshot (D-3); the manual-verify acceptance rides the transition listener.
 */
final class CampaignAssignmentResolutionController
{
    /**
     * POST …/assignments/{assignment}/manually-verify — ACT1 (D-4). The human
     * override of a failed verification; reason MANDATORY (422 without).
     */
    public function manuallyVerify(Request $request, Agency $agency, Campaign $campaign, CampaignAssignment $assignment, CampaignAssignmentStateMachine $machine): JsonResponse|CampaignAssignmentResource
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:5000'],
        ]);

        if (($guard = $this->guard($request, $agency, $campaign, $assignment)) !== null) {
            return $guard;
        }

        /** @var User $actor */
        $actor = $request->user();

        try {
            $machine->manuallyVerify($assignment, (string) $validated['reason'], $actor);
        } catch (AssignmentTransitionException $e) {
            return $this->transitionError($request, $e);
        }

        return $this->resource($assignment);
    }

    /**
     * POST …/assignments/{assignment}/request-resubmit-fresh — ACT2 (D-5). Sends
     * the assignment back to `approved`; the failed posted-content row is KEPT
     * as history. Optional feedback rides the creator notification.
     */
    public function requestResubmitFresh(Request $request, Agency $agency, Campaign $campaign, CampaignAssignment $assignment, CampaignAssignmentStateMachine $machine): JsonResponse|CampaignAssignmentResource
    {
        $validated = $request->validate([
            'feedback' => ['nullable', 'string', 'max:5000'],
        ]);
        $feedback = $this->normalizeFeedback($validated['feedback'] ?? null);

        if (($guard = $this->guard($request, $agency, $campaign, $assignment)) !== null) {
            return $guard;
        }

        /** @var User $actor */
        $actor = $request->user();

        try {
            $machine->returnForResubmit($assignment, $actor);
        } catch (AssignmentTransitionException $e) {
            return $this->transitionError($request, $e);
        }

        $this->notifyCreatorOfResubmit($assignment, 'fresh', $feedback);

        return $this->resource($assignment);
    }

    /**
     * POST …/assignments/{assignment}/request-resubmit-in-place — ACT3 (D-6). A
     * NUDGE only: NO state transition (the assignment stays `posted`). The
     * creator fixes the post URL in place via their own posted-content PATCH,
     * which is what resets verification to `pending` + re-arms the job (the
     * agency request is a prompt, not a precondition). Hand-writes the
     * `assignment.resubmit_requested_in_place` audit row + notifies the creator.
     */
    public function requestResubmitInPlace(Request $request, Agency $agency, Campaign $campaign, CampaignAssignment $assignment): JsonResponse|CampaignAssignmentResource
    {
        $validated = $request->validate([
            'feedback' => ['nullable', 'string', 'max:5000'],
        ]);
        $feedback = $this->normalizeFeedback($validated['feedback'] ?? null);

        if (($guard = $this->guard($request, $agency, $campaign, $assignment)) !== null) {
            return $guard;
        }

        // No machine edge — hand-write the agency-request audit (the free-text
        // feedback is deliberately NOT snapshotted, the hand-written-audit
        // discipline, D-3). The actor is resolved from the request by the logger.
        Audit::log(
            action: AuditAction::AssignmentResubmitRequestedInPlace,
            subject: $assignment,
            metadata: ['status' => $assignment->status->value],
        );

        $this->notifyCreatorOfResubmit($assignment, 'in_place', $feedback);

        return $this->resource($assignment);
    }

    /**
     * Fail-closed precondition shared by all three actions: the campaign belongs
     * to the agency, the assignment belongs to the campaign, the actor may
     * `review`, and the assignment is RESOLVABLE — `posted` with a latest
     * posted-content row whose verification failed (`not_found`/`mismatch`).
     * Returns an error response when not resolvable, null when clear.
     */
    private function guard(Request $request, Agency $agency, Campaign $campaign, CampaignAssignment $assignment): ?JsonResponse
    {
        if ($campaign->agency_id !== $agency->id) {
            abort(404);
        }
        if ($assignment->campaign_id !== $campaign->id) {
            abort(404);
        }
        Gate::authorize('review', $campaign);

        if ($assignment->status !== AssignmentStatus::Posted) {
            return ErrorResponse::single(
                $request,
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'assignment.not_resolvable',
                'This assignment has no failed verification to resolve.',
            );
        }

        $latest = CampaignPostedContent::query()
            ->where('assignment_id', $assignment->id)
            ->orderByDesc('id')
            ->first();

        $failed = [PostedContentVerificationStatus::NotFound, PostedContentVerificationStatus::Mismatch];
        if ($latest === null || ! in_array($latest->verification_status, $failed, true)) {
            return ErrorResponse::single(
                $request,
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'assignment.not_resolvable',
                'This assignment has no failed verification to resolve.',
            );
        }

        return null;
    }

    private function notifyCreatorOfResubmit(CampaignAssignment $assignment, string $mode, ?string $feedback): void
    {
        $creator = $assignment->creator;
        $campaign = $assignment->campaign;
        if ($creator === null || $campaign === null) {
            return;
        }

        $recipient = $creator->user;
        if (! $recipient instanceof User || $recipient->email === '') {
            return;
        }

        Mail::to($recipient->email)
            ->locale($recipient->preferred_language ?: 'en')
            ->queue(new ResubmitRequestedMail(
                creatorName: $creator->display_name ?? $recipient->name,
                campaignName: $campaign->name,
                mode: $mode === 'in_place' ? 'in_place' : 'fresh',
                feedback: $feedback,
                assignmentUlid: $assignment->ulid,
            ));
    }

    private function normalizeFeedback(?string $feedback): ?string
    {
        if ($feedback === null) {
            return null;
        }
        $trimmed = trim($feedback);

        return $trimmed === '' ? null : $trimmed;
    }

    private function resource(CampaignAssignment $assignment): CampaignAssignmentResource
    {
        return new CampaignAssignmentResource(
            $assignment->load(['creator:id,ulid,display_name', 'latestPostedContent']),
        );
    }

    private function transitionError(Request $request, AssignmentTransitionException $e): JsonResponse
    {
        return ErrorResponse::single(
            $request,
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $e->errorCode,
            $e->getMessage(),
        );
    }
}
