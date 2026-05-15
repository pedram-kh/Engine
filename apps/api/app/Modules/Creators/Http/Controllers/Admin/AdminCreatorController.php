<?php

declare(strict_types=1);

namespace App\Modules\Creators\Http\Controllers\Admin;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Creators\Enums\ApplicationStatus;
use App\Modules\Creators\Http\Requests\AdminApproveCreatorRequest;
use App\Modules\Creators\Http\Requests\AdminRejectCreatorRequest;
use App\Modules\Creators\Http\Requests\AdminUpdateCreatorRequest;
use App\Modules\Creators\Http\Resources\CreatorResource;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Services\AdminCreatorUpdateService;
use App\Modules\Creators\Services\CompletenessScoreCalculator;
use App\Modules\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Admin-facing controller for the single-Creator drill-in.
 *
 * Show (Chunk 3): GET /api/v1/admin/creators/{creator}.
 * Update (Chunk 4): PATCH /api/v1/admin/creators/{creator} —
 *   per-field edit, exactly one field per request, audit + idempotency
 *   handled by {@see AdminCreatorUpdateService}.
 * Approve / Reject (Chunk 4): POST /api/v1/admin/creators/{creator}/approve
 *   and /reject — dedicated workflow per Decision E2=b. application_status
 *   transitions emit their own audit codes
 *   ({@see AuditAction::CreatorApproved}, {@see AuditAction::CreatorRejected})
 *   and carry their own invariants (welcome_message optional on approve,
 *   rejection_reason required on reject).
 *
 * Path-scoped admin tooling per docs/security/tenancy.md § 4 — Creator
 * is a global entity and platform_admin users have no agency membership.
 * The PATCH + approve + reject routes share the same tenant-less
 * category as the existing GET.
 */
final class AdminCreatorController
{
    public function __construct(
        private readonly CompletenessScoreCalculator $calculator,
        private readonly AdminCreatorUpdateService $updateService,
    ) {}

    public function show(Request $request, Creator $creator): JsonResponse
    {
        $this->authorize($request, 'view', $creator);

        $creator->loadMissing(['socialAccounts', 'portfolioItems', 'kycVerifications']);

        return (new CreatorResource($creator, $this->calculator))
            ->withAdmin(true)
            ->response($request);
    }

    /**
     * PATCH /api/v1/admin/creators/{creator} — per-field admin edit.
     *
     * Exactly one of the 7 editable fields per request (validation
     * enforced by {@see AdminUpdateCreatorRequest}). Same-value updates
     * are no-ops per #6 idempotency. application_status is refused with
     * `creator.admin.field_status_immutable` per Q-chunk-4-2 (a).
     */
    public function update(AdminUpdateCreatorRequest $request, Creator $creator): JsonResponse
    {
        $this->authorize($request, 'adminUpdate', $creator);

        /** @var User $admin */
        $admin = $request->user();

        $field = $request->editableField();
        $value = $request->input($field);
        $reason = $request->input('reason');
        $reasonString = is_string($reason) && trim($reason) !== '' ? $reason : null;

        $this->updateService->updateField(
            creator: $creator,
            admin: $admin,
            field: $field,
            value: $value,
            reason: $reasonString,
        );

        $creator->refresh()->loadMissing(['socialAccounts', 'portfolioItems', 'kycVerifications']);

        return (new CreatorResource($creator, $this->calculator))
            ->withAdmin(true)
            ->response($request);
    }

    /**
     * POST /api/v1/admin/creators/{creator}/approve.
     *
     * Status transition pending → approved (per Decision E2=b — dedicated
     * workflow, not the generic PATCH). Optional welcome_message stamped
     * into `creators.welcome_message` (column added in Chunk 4 migration).
     * Emits {@see AuditAction::CreatorApproved}.
     *
     * Idempotent (#6): calling approve on a creator already approved
     * returns 409 + `creator.already_approved` (does NOT re-stamp the
     * approved_at / approved_by_user_id columns).
     */
    public function approve(AdminApproveCreatorRequest $request, Creator $creator): JsonResponse
    {
        $this->authorize($request, 'approve', $creator);

        if ($creator->application_status === ApplicationStatus::Approved) {
            return response()->json([
                'errors' => [[
                    'status' => '409',
                    'code' => 'creator.already_approved',
                    'title' => 'Creator has already been approved.',
                ]],
            ], 409);
        }

        /** @var User $admin */
        $admin = $request->user();

        $welcomeMessage = $request->input('welcome_message');
        $welcomeString = is_string($welcomeMessage) && trim($welcomeMessage) !== ''
            ? $welcomeMessage
            : null;

        DB::transaction(function () use ($creator, $admin, $welcomeString): void {
            $creator->forceFill([
                'application_status' => ApplicationStatus::Approved->value,
                'approved_at' => now(),
                'approved_by_user_id' => $admin->id,
                'rejected_at' => null,
                'rejection_reason' => null,
                'welcome_message' => $welcomeString,
            ])->save();

            Audit::log(
                action: AuditAction::CreatorApproved,
                actor: $admin,
                subject: $creator,
                metadata: array_filter([
                    'welcome_message' => $welcomeString,
                ], static fn ($v): bool => $v !== null),
            );
        });

        $creator->refresh()->loadMissing(['socialAccounts', 'portfolioItems', 'kycVerifications']);

        return (new CreatorResource($creator, $this->calculator))
            ->withAdmin(true)
            ->response($request);
    }

    /**
     * POST /api/v1/admin/creators/{creator}/reject.
     *
     * Status transition pending → rejected. Mandatory rejection_reason
     * (min 10 chars, max 2000) enforced by {@see AdminRejectCreatorRequest}.
     * Emits {@see AuditAction::CreatorRejected}.
     *
     * Idempotent (#6): rejecting an already-rejected creator returns 409
     * + `creator.already_rejected`.
     */
    public function reject(AdminRejectCreatorRequest $request, Creator $creator): JsonResponse
    {
        $this->authorize($request, 'reject', $creator);

        if ($creator->application_status === ApplicationStatus::Rejected) {
            return response()->json([
                'errors' => [[
                    'status' => '409',
                    'code' => 'creator.already_rejected',
                    'title' => 'Creator has already been rejected.',
                ]],
            ], 409);
        }

        /** @var User $admin */
        $admin = $request->user();

        $rejectionReason = (string) $request->input('rejection_reason');

        DB::transaction(function () use ($creator, $admin, $rejectionReason): void {
            $creator->forceFill([
                'application_status' => ApplicationStatus::Rejected->value,
                'rejected_at' => now(),
                'rejection_reason' => $rejectionReason,
                'approved_at' => null,
                'approved_by_user_id' => null,
            ])->save();

            Audit::log(
                action: AuditAction::CreatorRejected,
                actor: $admin,
                subject: $creator,
                metadata: [
                    'rejection_reason' => $rejectionReason,
                ],
            );
        });

        $creator->refresh()->loadMissing(['socialAccounts', 'portfolioItems', 'kycVerifications']);

        return (new CreatorResource($creator, $this->calculator))
            ->withAdmin(true)
            ->response($request);
    }

    private function authorize(Request $request, string $ability, Creator $creator): void
    {
        $user = $request->user();
        if ($user === null) {
            abort(Response::HTTP_UNAUTHORIZED);
        }
        if (! $user->can($ability, $creator)) {
            abort(Response::HTTP_FORBIDDEN);
        }
    }
}
