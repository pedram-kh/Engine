<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Http\Controllers;

use App\Core\Errors\ErrorResponse;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Events\AssignmentTransitioned;
use App\Modules\Campaigns\Exceptions\AssignmentTransitionException;
use App\Modules\Campaigns\Http\Requests\InviteAssignmentRequest;
use App\Modules\Campaigns\Http\Requests\ReinviteAssignmentRequest;
use App\Modules\Campaigns\Http\Resources\CampaignAssignmentResource;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Campaigns\Services\AssignmentInviteGate;
use App\Modules\Campaigns\Services\CampaignAssignmentStateMachine;
use App\Modules\Creators\Enums\ApplicationStatus;
use App\Modules\Creators\Features\PerCampaignContractEnabled;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Laravel\Pennant\Feature;

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
            ->with(['creator:id,ulid,display_name', 'latestPostedContent', 'sentContract'])
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
     */
    public function store(InviteAssignmentRequest $request, Agency $agency, Campaign $campaign, AssignmentInviteGate $gate): JsonResponse
    {
        $this->assertBelongsToAgency($campaign, $agency);
        Gate::authorize('invite', $campaign);

        /** @var User $actor */
        $actor = $request->user();
        $validated = $request->validated();

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

        // Idempotent on the unique (campaign_id, creator_id): a creator already
        // on this campaign is returned as-is (the bulk loop relies on this) —
        // no second row, no duplicate audit/event.
        $existing = CampaignAssignment::query()
            ->where('campaign_id', $campaign->id)
            ->where('creator_id', $creator->id)
            ->first();

        if ($existing !== null) {
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

        $assignment = CampaignAssignment::query()->create([
            'agency_id' => $agency->id,
            'campaign_id' => $campaign->id,
            'brand_id' => $campaign->brand_id,
            'creator_id' => $creator->id,
            'status' => AssignmentStatus::Invited,
            'invited_at' => now(),
            'invited_by_user_id' => $actor->id,
            'agreed_fee_minor_units' => $validated['agreed_fee_minor_units'],
            'agreed_fee_currency' => strtoupper((string) $validated['agreed_fee_currency']),
            'deliverables' => $validated['deliverables'] ?? null,
            'posting_due_at' => $validated['posting_due_at'] ?? null,
            // Sprint 12 Chunk 3 (D-2) — mirror of posting_due_at; nullable.
            'draft_due_at' => $validated['draft_due_at'] ?? null,
        ]);

        // Correction #1 — invite is a CREATE, not a machine transition, so the
        // ENDPOINT hand-writes the `assignment.invited` audit row + dispatches
        // the event (the machine never sees the create). The event carries
        // from=to=invited (no prior state) so the future board listener can
        // create the card off `eventKey()`.
        Audit::log(
            action: AuditAction::AssignmentInvited,
            subject: $assignment,
            metadata: [
                'from' => null,
                'to' => AssignmentStatus::Invited->value,
                'agreed_fee_minor_units' => $assignment->agreed_fee_minor_units,
                'agreed_fee_currency' => $assignment->agreed_fee_currency,
            ],
        );

        AssignmentTransitioned::dispatch(
            $assignment,
            AssignmentStatus::Invited,
            AssignmentStatus::Invited,
            AuditAction::AssignmentInvited,
            $actor->id,
        );

        return (new CampaignAssignmentResource($assignment->load('creator:id,ulid,display_name')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
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
