<?php

declare(strict_types=1);

namespace App\Modules\Creators\Http\Controllers;

use App\Core\Tenancy\BelongsToAgencyScope;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Campaigns\Services\AssignmentOfferAttachmentUploadService;
use App\Modules\Campaigns\Services\CampaignAssignmentStateMachine;
use App\Modules\Creators\Http\Requests\CounterAssignmentRequest;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The CREATOR half of the assignment lifecycle (Sprint 8 Chunk 2, D-9) — mirrors
 * {@see CreatorConnectionRequestController} (the 6.6b/6.6c pattern) exactly.
 *
 *   GET    /api/v1/creators/me/assignments                       list invitations
 *   POST   /api/v1/creators/me/assignments/{assignment}/accept   invited → accepted
 *   POST   /api/v1/creators/me/assignments/{assignment}/decline  invited → declined
 *   POST   /api/v1/creators/me/assignments/{assignment}/counter  invited → countered
 *
 * ⚠ The BelongsToAgency global scope is bypassed deliberately (the documented
 * justified HTTP bypass, mirroring the connection controller + discovery): the
 * authenticated caller is a CREATOR, who may hold assignments from MANY
 * agencies; an ambient tenant context would otherwise hide every OTHER agency's
 * assignment. So we scope by `creator_id` only and drop the agency scope.
 * Ownership is STRUCTURAL — a non-owned ULID is simply not found (404), so one
 * creator can never act on another's assignment.
 *
 * Fail-closed (D-9): accept/decline/counter reject unless the assignment is
 * EXACTLY `invited` (422 `assignment.not_invited`). Status is flipped ONLY via
 * {@see CampaignAssignmentStateMachine} (the sole authority) — never here.
 */
final class CreatorAssignmentController
{
    public function index(Request $request): JsonResponse
    {
        $creator = $this->requireCreator($request);

        $assignments = CampaignAssignment::query()
            ->withoutGlobalScope(BelongsToAgencyScope::class)
            ->where('creator_id', $creator->id)
            ->with(['campaign:id,ulid,name,posting_window_starts_at,posting_window_ends_at,starts_at,ends_at,brand_id', 'campaign.brand:id,ulid,name'])
            ->orderByDesc('invited_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => $assignments->map($this->toRow(...))->all(),
        ]);
    }

    public function accept(Request $request, string $assignment, CampaignAssignmentStateMachine $machine): JsonResponse
    {
        return $this->transition($request, $assignment, 'assignment.accepted', static function (CampaignAssignment $a, User $actor) use ($machine): void {
            // Toggle-off flow (D2): a campaign that does NOT require a
            // per-campaign contract auto-advances straight through
            // `accepted → contracted` with NO contract, so the creator never
            // sees, waits for, or hears about a contract — the next screen is
            // the draft form. requires=true stays at `accepted` (the
            // creator-accepts-a-contract path, unchanged, D7). Both flips run
            // in ONE outer transaction (all-or-nothing). The contract-less
            // advance is audit-distinguished from the agency's manual
            // proceed-without-contract via `auto_advanced: true` (D6); the
            // machine permits the null advance regardless of the flag (D1).
            DB::transaction(function () use ($machine, $a, $actor): void {
                $machine->accept($a, $actor);

                $campaign = Campaign::query()
                    ->withoutGlobalScope(BelongsToAgencyScope::class)
                    ->find($a->campaign_id);

                if ($campaign !== null && ! $campaign->requires_per_campaign_contract) {
                    $machine->contract($a, null, $actor, ['auto_advanced' => true]);
                }
            });
        });
    }

    public function decline(Request $request, string $assignment, CampaignAssignmentStateMachine $machine): JsonResponse
    {
        return $this->transition($request, $assignment, 'assignment.declined', static function (CampaignAssignment $a, User $actor) use ($machine): void {
            $machine->decline($a, $actor);
        });
    }

    public function counter(CounterAssignmentRequest $request, string $assignment, CampaignAssignmentStateMachine $machine): JsonResponse
    {
        $validated = $request->validated();

        return $this->transition($request, $assignment, 'assignment.countered', static function (CampaignAssignment $a, User $actor) use ($machine, $validated): void {
            $machine->counter(
                $a,
                (int) $validated['countered_fee_minor_units'],
                strtoupper((string) $validated['countered_fee_currency']),
                $actor,
            );
        });
    }

    /**
     * Resolve the assignment within the creator's OWN assignments, fail-closed
     * guard it is `invited`, then delegate the flip to the state machine. A
     * non-owned ULID is simply not found (404) — the structural owner-only guard.
     *
     * @param  callable(CampaignAssignment, User): void  $transition
     */
    private function transition(Request $request, string $assignmentUlid, string $code, callable $transition): JsonResponse
    {
        $creator = $this->requireCreator($request);

        /** @var User $user */
        $user = $request->user();

        $assignment = CampaignAssignment::query()
            ->withoutGlobalScope(BelongsToAgencyScope::class)
            ->where('creator_id', $creator->id)
            ->where('ulid', $assignmentUlid)
            ->first();

        if ($assignment === null) {
            abort(response()->json([
                'errors' => [[
                    'status' => '404',
                    'code' => 'assignment.not_found',
                    'detail' => 'No assignment found.',
                ]],
            ], 404));
        }

        // Fail-closed: only an `invited` assignment may be accepted, declined or
        // countered. A countered / accepted / declined / contracted / … row is
        // rejected (the creator's window has closed).
        if ($assignment->status !== AssignmentStatus::Invited) {
            abort(response()->json([
                'errors' => [[
                    'status' => '422',
                    'code' => 'assignment.not_invited',
                    'detail' => 'This assignment is no longer awaiting your response.',
                ]],
            ], 422));
        }

        $transition($assignment, $user);

        $fresh = $assignment->fresh();

        return response()->json([
            'data' => [
                'type' => 'campaign_assignment',
                'id' => $assignment->ulid,
                'attributes' => [
                    'status' => ($fresh ?? $assignment)->status->value,
                ],
            ],
            'meta' => [
                'code' => $code,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function toRow(CampaignAssignment $assignment): array
    {
        $campaign = $assignment->campaign;
        $brand = $campaign?->brand;

        return [
            'id' => $assignment->ulid,
            'type' => 'campaign_assignment',
            'attributes' => [
                'status' => $assignment->status->value,
                'agreed_fee_minor_units' => $assignment->agreed_fee_minor_units,
                'agreed_fee_currency' => $assignment->agreed_fee_currency,
                // Invite-offer context (invite-offer-details batch). The signed
                // attachment URL is minted inside this already-owner-scoped row,
                // so the download inherits the creator's own view authz.
                'fee_per' => $assignment->fee_per,
                'offer_description' => $assignment->offer_description,
                'offer_attachment' => $assignment->offer_attachment_path !== null ? [
                    'name' => $assignment->offer_attachment_name,
                    'mime_type' => $assignment->offer_attachment_mime,
                    'size_bytes' => $assignment->offer_attachment_size_bytes,
                    'url' => AssignmentOfferAttachmentUploadService::signedViewUrl($assignment->offer_attachment_path),
                ] : null,
                'countered_fee_minor_units' => $assignment->countered_fee_minor_units,
                'countered_fee_currency' => $assignment->countered_fee_currency,
                'deliverables' => $assignment->deliverables,
                'posting_due_at' => $assignment->posting_due_at?->toIso8601String(),
                'invited_at' => $assignment->invited_at?->toIso8601String(),
                'campaign' => $campaign !== null ? [
                    'id' => $campaign->ulid,
                    'name' => $campaign->name,
                    'posting_window_starts_at' => ($campaign->posting_window_starts_at ?? $campaign->starts_at)?->toIso8601String(),
                    'posting_window_ends_at' => ($campaign->posting_window_ends_at ?? $campaign->ends_at)?->toIso8601String(),
                    'brand_name' => $brand?->name,
                ] : null,
            ],
        ];
    }

    private function requireCreator(Request $request): Creator
    {
        /** @var User $user */
        $user = $request->user();
        $creator = $user->creator;

        if ($creator === null) {
            abort(response()->json([
                'errors' => [[
                    'status' => '404',
                    'code' => 'creator.not_found',
                    'detail' => 'No creator profile is associated with this user.',
                ]],
            ], 404));
        }

        return $creator;
    }
}
