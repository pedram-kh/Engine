<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Http\Controllers;

use App\Core\Errors\ErrorResponse;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Exceptions\AssignmentTransitionException;
use App\Modules\Campaigns\Http\Requests\InviteAssignmentRequest;
use App\Modules\Campaigns\Http\Requests\ReinviteAssignmentRequest;
use App\Modules\Campaigns\Http\Resources\CampaignAssignmentResource;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignApplication;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Campaigns\Services\AssignmentInviteGate;
use App\Modules\Campaigns\Services\AssignmentOfferAttachmentUploadService;
use App\Modules\Campaigns\Services\CampaignApplicationDecisionService;
use App\Modules\Campaigns\Services\CampaignApplicationNotifier;
use App\Modules\Campaigns\Services\CampaignAssignmentStateMachine;
use App\Modules\Campaigns\Services\CampaignInvitationService;
use App\Modules\Campaigns\ValueObjects\AssignmentOffer;
use App\Modules\Creators\Enums\ApplicationStatus;
use App\Modules\Creators\Features\PerCampaignContractEnabled;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Laravel\Pennant\Feature;
use RuntimeException;

/**
 * Agency-side campaign assignments (Sprint 8 Chunk 1 read-only + Chunk 2 invite
 * front-door).
 *
 *   - index    — read-only listing for the Creators tab (Chunk 1), any member.
 *   - store    — INVITE a creator (Chunk 2, D-3). The execute ability + the
 *                two-tier gate (D-1 blacklist 422, D-2 availability 409). This
 *                is a CREATE, not a machine transition (correction #1): the
 *                endpoint hand-writes the `assignment.invited` audit row +
 *                dispatches the event itself.
 *   - reinvite — the agency re-offer after a counter (Chunk 2, D-7), a GUARDED
 *                machine edge (`countered → invited`). No raw status back-write.
 */
final class CampaignAssignmentController
{
    /**
     * GET /api/v1/agencies/{agency}/campaigns/{campaign}/assignments
     */
    public function index(Request $request, Agency $agency, Campaign $campaign): JsonResponse
    {
        $this->assertBelongsToAgency($campaign, $agency);
        Gate::authorize('view', $campaign);

        $perPage = max(1, min((int) $request->integer('per_page', 25), 100));

        $paginator = $campaign->assignments()
            ->where('campaign_assignments.agency_id', $agency->id)
            // AH-080 — `avatar_path` added so CampaignAssignmentResource can mint
            // the signed avatar_url the Creators-tab row + CreatorProfileDialog
            // header want (AH-075 precedent). Signing itself happens in the
            // resource, not here.
            ->with(['creator:id,ulid,display_name,avatar_path', 'latestPostedContent', 'sentContract'])
            ->orderByDesc('campaign_assignments.id')
            ->paginate($perPage)
            ->withQueryString();

        return response()->json([
            'data' => CampaignAssignmentResource::collection($paginator->items())->resolve($request),
            'meta' => [
                'total' => $paginator->total(),
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'last_page' => $paginator->lastPage(),
                // The per-campaign manual-contract flag, so the Creators tab can
                // gate the agency "proceed without a contract" action (visible
                // only when the campaign does not require a contract AND the flag
                // is ON). Contract-gate-decouple chunk, D-7.
                'per_campaign_contract_enabled' => Feature::active(PerCampaignContractEnabled::NAME),
            ],
        ]);
    }

    /**
     * POST /api/v1/agencies/{agency}/campaigns/{campaign}/assignments
     *
     * Single invite (the bulk D-5 case loops this client-side). The two-tier
     * gate fires BEFORE the create; the create is idempotent on the unique
     * (campaign_id, creator_id).
     *
     * ── D3b — the applications convergence hook (AH-058) ────────────────────
     *
     * This path gained exactly one new behaviour: when the invited pair has a
     * PENDING job application, that application is marked `accepted` in the same
     * transaction as the offer, and the creator gets the accepted notification
     * after it commits. A direct invite and an accept-from-application now reach
     * the same truthful outcome, so no application can sit pending forever for a
     * creator the agency has already engaged.
     *
     * Two consequences worth naming rather than discovering later:
     *
     *   1. §5.34 — for a pair with NO application (the overwhelming majority,
     *      and every pair that exists today) this endpoint is byte-identical to
     *      before: same row, same audit row, same event, same 201. The invite
     *      spec asserts that field-by-field.
     *   2. `store()` now runs its writes inside a `DB::transaction()`, which it
     *      did not before. That is a behavioural delta and a strict improvement:
     *      the pre-existing shape could already leave a created assignment whose
     *      audit row failed. The bulk-invite loop is N separate calls, so each
     *      gets its own transaction — one creator's failure never rolls back the
     *      other N−1.
     */
    public function store(
        InviteAssignmentRequest $request,
        Agency $agency,
        Campaign $campaign,
        AssignmentInviteGate $gate,
        AssignmentOfferAttachmentUploadService $offerUploads,
        CampaignAssignmentStateMachine $machine,
        CampaignInvitationService $invitations,
        CampaignApplicationDecisionService $decisions,
        CampaignApplicationNotifier $notifier,
    ): JsonResponse {
        $this->assertBelongsToAgency($campaign, $agency);
        Gate::authorize('invite', $campaign);

        /** @var User $actor */
        $actor = $request->user();
        $validated = $request->validated();

        // Invite-offer-details batch — the isolation backstop, re-run per
        // invite: the attachment upload_id must sit under THIS campaign's
        // offer prefix and the object must exist. The bulk loop carries the
        // same upload_id on every call (one shared object, stamped per row).
        /** @var array{upload_id: string, name: string, mime_type: string, size_bytes: int}|null $attachment */
        $attachment = $validated['attachment'] ?? null;
        $offer = AssignmentOffer::fromValidated($validated);
        if ($attachment !== null) {
            try {
                $offerUploads->assertUploadBelongs($agency, $campaign, (string) $attachment['upload_id']);
            } catch (RuntimeException $e) {
                return ErrorResponse::single(
                    $request,
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                    'assignment.attachment_invalid',
                    $e->getMessage(),
                );
            }
        }

        // D-4 — invite targets any DISCOVERABLE + approved creator (first
        // contact; NO roster relation required). Non-discoverable → 404, the
        // discovery-gate precedent (don't leak the creator's existence).
        $creator = Creator::query()
            ->where('ulid', $validated['creator_id'])
            ->where('application_status', ApplicationStatus::Approved->value)
            ->where('is_discoverable', true)
            ->first();

        if ($creator === null) {
            abort(404);
        }

        // D-1 (TIER 1 — HARD BLOCK) — either hard-blacklist predicate refuses
        // the invite with a 422, mirroring the connection-request gate.
        if ($gate->isHardBlacklisted($campaign, $creator->id)) {
            return response()->json([
                'message' => 'This creator is hard-blacklisted and cannot be invited to this campaign.',
                'errors' => ['blacklist' => ['This creator is hard-blacklisted and cannot be invited to this campaign.']],
                'meta' => ['code' => 'assignment.blacklisted'],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Existing row on the unique (campaign_id, creator_id). Two outcomes:
        //   - DECLINED → re-open it (re-offer-after-decline chunk): the SAME
        //     row flips declined → invited via the machine, carrying the fresh
        //     offer, so the chat thread + history are preserved and the agency
        //     sees the "declined then re-invited" tag. Returned 200.
        //   - ANY OTHER status → idempotent no-op returned as-is (the bulk loop
        //     relies on this; no second row, no duplicate audit/event, no
        //     surprise overwrite of a live invited/accepted/countered offer).
        $existing = CampaignAssignment::query()
            ->where('campaign_id', $campaign->id)
            ->where('creator_id', $creator->id)
            ->first();

        if ($existing !== null) {
            if ($existing->status === AssignmentStatus::Declined) {
                // The AH-035 re-offer branch settles the application too (D3b):
                // a creator who declined, applied later, and is now being
                // re-offered is exactly the pair a create-only hook would miss.
                $settled = DB::transaction(function () use ($existing, $offer, $actor, $machine, $decisions, $campaign): ?CampaignApplication {
                    $machine->reofferAfterDecline(
                        $existing,
                        $offer->agreedFeeMinorUnits,
                        $offer->agreedFeeCurrency,
                        $offer->feePer,
                        $offer->offerDescription,
                        $offer->attachmentForReoffer(),
                        $actor,
                    );

                    /** @var Creator $creator */
                    $creator = $existing->creator;

                    return $decisions->settlePendingApplication($campaign, $creator);
                });

                $this->emitSettledApplication($notifier, $campaign, $settled, $existing->ulid);
            }

            // Every other status is the pre-existing idempotent no-op: no offer
            // is made, so nothing has answered any application either.
            return (new CampaignAssignmentResource($existing->load('creator:id,ulid,display_name')))
                ->response()
                ->setStatusCode(Response::HTTP_OK);
        }

        // D-2 (TIER 2 — SOFT WARN) — a hard AVAILABILITY conflict returns a 409
        // conflict signal (NOT a block). The agency re-submits with
        // `acknowledged: true` to proceed. Soft availability never warns.
        $acknowledged = (bool) ($validated['acknowledged'] ?? false);
        if (! $acknowledged) {
            $conflict = $gate->availabilityConflict($campaign, $creator);
            if ($conflict->hasConflict) {
                return response()->json([
                    'message' => 'This creator has an availability conflict over the campaign window.',
                    'meta' => ['code' => 'assignment.availability_conflict'],
                    'conflict' => [
                        'creator_id' => $creator->ulid,
                        'conflicts' => array_map(static fn ($occurrence): array => [
                            'starts_at' => $occurrence->startsAt->toIso8601String(),
                            'ends_at' => $occurrence->endsAt->toIso8601String(),
                            'reason' => $occurrence->block->reason,
                        ], $conflict->conflicts),
                    ],
                ], Response::HTTP_CONFLICT);
            }
        }

        // The create + its hand-written audit row + its hand-dispatched event
        // live in ONE service (AH-058 S1) because accept-an-application is now a
        // second way an invitation is born, and an invitation created without
        // that audit row and that event is an assignment with no board card and
        // no message thread. Correction #1's reasoning moved into the service's
        // docblock with the code.
        // One transaction around the create, its audit row, its event and the
        // D3b application settle — see the method docblock for why this is new.
        [$assignment, $settled] = DB::transaction(function () use ($agency, $campaign, $creator, $offer, $actor, $invitations, $decisions): array {
            $assignment = $invitations->invite($agency, $campaign, $creator, $offer, $actor);

            return [$assignment, $decisions->settlePendingApplication($campaign, $creator)];
        });

        $this->emitSettledApplication($notifier, $campaign, $settled, $assignment->ulid);

        return (new CampaignAssignmentResource($assignment->load('creator:id,ulid,display_name')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * POST /api/v1/agencies/{agency}/campaigns/{campaign}/assignments/attachments/init
     *
     * Presigned init for the invite-offer attachment (invite-offer-details
     * batch). Campaign-keyed — the upload happens BEFORE any assignment row
     * exists. Same `invite` execute ability as the invite itself.
     */
    public function attachmentInit(
        Request $request,
        Agency $agency,
        Campaign $campaign,
        AssignmentOfferAttachmentUploadService $offerUploads,
    ): JsonResponse {
        $this->assertBelongsToAgency($campaign, $agency);
        Gate::authorize('invite', $campaign);

        $validated = $request->validate([
            'mime_type' => ['required', 'string'],
            'size_bytes' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $result = $offerUploads->initiatePresignedUpload(
                $agency,
                $campaign,
                (string) $validated['mime_type'],
                (int) $validated['size_bytes'],
            );
        } catch (RuntimeException $e) {
            return ErrorResponse::single($request, Response::HTTP_UNPROCESSABLE_ENTITY, 'assignment.attachment_invalid', $e->getMessage());
        }

        return response()->json(['data' => $result]);
    }

    /**
     * POST /api/v1/agencies/{agency}/campaigns/{campaign}/assignments/attachments/complete
     *
     * Verifies the presigned upload landed under the campaign's offer prefix
     * and EXIF-strips supported raster images in place (once, before any
     * assignment row or signed URL exists).
     */
    public function attachmentComplete(
        Request $request,
        Agency $agency,
        Campaign $campaign,
        AssignmentOfferAttachmentUploadService $offerUploads,
    ): JsonResponse {
        $this->assertBelongsToAgency($campaign, $agency);
        Gate::authorize('invite', $campaign);

        $validated = $request->validate([
            'upload_id' => ['required', 'string'],
        ]);

        try {
            $path = $offerUploads->completePresignedUpload($agency, $campaign, (string) $validated['upload_id']);
        } catch (RuntimeException $e) {
            return ErrorResponse::single($request, Response::HTTP_UNPROCESSABLE_ENTITY, 'assignment.attachment_invalid', $e->getMessage());
        }

        return response()->json(['data' => ['storage_path' => $path]]);
    }

    /**
     * POST /api/v1/agencies/{agency}/campaigns/{campaign}/assignments/{assignment}/reinvite
     *
     * The agency re-offer after a counter (D-7) — a GUARDED machine edge.
     */
    public function reinvite(
        ReinviteAssignmentRequest $request,
        Agency $agency,
        Campaign $campaign,
        CampaignAssignment $assignment,
        CampaignAssignmentStateMachine $machine,
    ): JsonResponse|CampaignAssignmentResource {
        $this->assertBelongsToAgency($campaign, $agency);
        $this->assertAssignmentBelongsToCampaign($assignment, $campaign);
        Gate::authorize('invite', $campaign);

        /** @var User $actor */
        $actor = $request->user();
        $validated = $request->validated();

        // The machine is the sole status authority + guards the edge
        // (`countered → invited` only). A non-countered source fails closed —
        // map its typed exception to a 422 rather than a raw 500.
        try {
            $machine->reinvite(
                $assignment,
                (int) $validated['agreed_fee_minor_units'],
                strtoupper((string) $validated['agreed_fee_currency']),
                $actor,
            );
        } catch (AssignmentTransitionException $e) {
            return ErrorResponse::single(
                request: $request,
                status: Response::HTTP_UNPROCESSABLE_ENTITY,
                code: $e->errorCode,
                title: 'Illegal assignment transition',
                detail: $e->getMessage(),
            );
        }

        return new CampaignAssignmentResource($assignment->load('creator:id,ulid,display_name'));
    }

    /**
     * The D3b emission, AFTER the caller's commit and never inside it: a mail
     * queued in an open transaction is visible to a worker immediately
     * (`after_commit => false`), so a rolled-back invite would have told the
     * creator their application was accepted.
     *
     * A no-op when nothing settled, which is the byte-identity case.
     */
    private function emitSettledApplication(
        CampaignApplicationNotifier $notifier,
        Campaign $campaign,
        ?CampaignApplication $settled,
        string $assignmentUlid,
    ): void {
        if (! $settled instanceof CampaignApplication) {
            return;
        }

        $notifier->accepted($settled->setRelation('campaign', $campaign), $assignmentUlid);
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
