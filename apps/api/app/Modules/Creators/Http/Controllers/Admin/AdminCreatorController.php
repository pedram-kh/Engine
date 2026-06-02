<?php

declare(strict_types=1);

namespace App\Modules\Creators\Http\Controllers\Admin;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Creators\Enums\ApplicationStatus;
use App\Modules\Creators\Enums\KycMethod;
use App\Modules\Creators\Enums\KycStatus;
use App\Modules\Creators\Http\Requests\AdminApproveCreatorRequest;
use App\Modules\Creators\Http\Requests\AdminRejectCreatorRequest;
use App\Modules\Creators\Http\Requests\AdminUpdateCreatorRequest;
use App\Modules\Creators\Http\Requests\VerifyCreatorIdentityRequest;
use App\Modules\Creators\Http\Resources\CreatorResource;
use App\Modules\Creators\Mail\CreatorApprovedMail;
use App\Modules\Creators\Mail\CreatorRejectedMail;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Services\AdminCreatorUpdateService;
use App\Modules\Creators\Services\CompletenessScoreCalculator;
use App\Modules\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

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

    /**
     * GET /api/v1/admin/creators — the review queue (Sprint 4 Chunk 3,
     * Cluster 3). platform_admin-gated via CreatorPolicy::viewAny.
     *
     * Creators are a global entity (no agency tenancy on this admin
     * list — the admin sees all). Optional ?status= filter validated
     * against ApplicationStatus; an unknown value yields an empty page
     * rather than 422 (the SPA only ever sends known filter chips).
     * Paginated; returns the list-card fields only (display_name,
     * application_status, kyc_status, submitted_at, completeness) — the
     * full drill-in lives at the show route.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAny($request, 'viewAny');

        $perPage = (int) $request->integer('per_page', 25);
        $perPage = max(1, min($perPage, 100));

        $query = Creator::query()->orderByDesc('submitted_at')->orderByDesc('id');

        $statusInput = $request->query('status');
        if (is_string($statusInput) && $statusInput !== '') {
            $status = ApplicationStatus::tryFrom($statusInput);
            if ($status === null) {
                // Unknown status → no rows (the SPA only sends valid chips).
                $query->whereRaw('1 = 0');
            } else {
                $query->where('application_status', $status->value);
            }
        }

        $paginator = $query->paginate($perPage)->withQueryString();

        $data = array_map(static fn (Creator $creator): array => [
            'id' => $creator->ulid,
            'type' => 'creators',
            'attributes' => [
                'display_name' => $creator->display_name,
                'application_status' => $creator->application_status->value,
                'kyc_status' => $creator->kyc_status->value,
                'profile_completeness_score' => $creator->profile_completeness_score,
                'submitted_at' => $creator->submitted_at?->toIso8601String(),
                'created_at' => $creator->created_at->toIso8601String(),
            ],
        ], $paginator->items());

        return response()->json([
            'data' => $data,
            'meta' => [
                'total' => $paginator->total(),
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

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

        // D-c3-7 + D-NEW-1: approve requires identity cleared. `verified`
        // is the vendor/manual cleared state; `not_required` is the
        // flag-OFF terminal state (KYC waived at submit time). Any other
        // KYC status (none / pending / rejected) blocks the approval —
        // the admin must clear identity first (manual verify or vendor).
        if (! in_array($creator->kyc_status, [KycStatus::Verified, KycStatus::NotRequired], true)) {
            return response()->json([
                'errors' => [[
                    'status' => '422',
                    'code' => 'creator.kyc_not_verified',
                    'title' => 'Creator identity must be verified before approval.',
                ]],
            ], 422);
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

        $this->dispatchCreatorMail($creator, new CreatorApprovedMail(
            creatorDisplayName: $creator->display_name ?? '',
            welcomeMessage: $welcomeString,
        ));

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

        $this->dispatchCreatorMail($creator, new CreatorRejectedMail(
            creatorDisplayName: $creator->display_name ?? '',
            rejectionReason: $rejectionReason,
        ));

        return (new CreatorResource($creator, $this->calculator))
            ->withAdmin(true)
            ->response($request);
    }

    /**
     * POST /api/v1/admin/creators/{creator}/verify-identity.
     *
     * Sprint 4 Chunk 3 (D-c3-3) — manual KYC clearance, the live
     * identity-clearing action. Sets kyc_status=verified,
     * kyc_verified_at=now(), verified_by_user_id=acting admin, and
     * kyc_method=manual. Optional note captured in the audit metadata.
     *
     * Idempotent (#6, mirrors the approve-409 pattern): verifying an
     * already-verified creator returns 409 + creator.kyc_already_verified
     * and does NOT re-stamp the attribution columns. Emits the dedicated
     * {@see AuditAction::CreatorKycManuallyVerified} with the acting admin
     * as actor — the override is compliance-sensitive, so attribution +
     * audit are load-bearing, not optional.
     */
    public function verifyIdentity(VerifyCreatorIdentityRequest $request, Creator $creator): JsonResponse
    {
        $this->authorize($request, 'verifyIdentity', $creator);

        if ($creator->kyc_status === KycStatus::Verified) {
            return response()->json([
                'errors' => [[
                    'status' => '409',
                    'code' => 'creator.kyc_already_verified',
                    'title' => 'Creator identity has already been verified.',
                ]],
            ], 409);
        }

        /** @var User $admin */
        $admin = $request->user();

        $note = $request->input('note');
        $noteString = is_string($note) && trim($note) !== '' ? $note : null;

        DB::transaction(function () use ($creator, $admin, $noteString): void {
            $creator->forceFill([
                'kyc_status' => KycStatus::Verified->value,
                'kyc_verified_at' => now(),
                'kyc_method' => KycMethod::Manual->value,
                'verified_by_user_id' => $admin->id,
            ])->save();

            Audit::log(
                action: AuditAction::CreatorKycManuallyVerified,
                actor: $admin,
                subject: $creator,
                metadata: array_filter([
                    'note' => $noteString,
                ], static fn ($v): bool => $v !== null),
            );
        });

        $creator->refresh()->loadMissing(['socialAccounts', 'portfolioItems', 'kycVerifications']);

        return (new CreatorResource($creator, $this->calculator))
            ->withAdmin(true)
            ->response($request);
    }

    /**
     * Queue a creator-facing lifecycle mail in the creator's preferred
     * locale (D-c3-11). Mirrors the BulkInviteService locale convention
     * ($user->preferred_language ?: 'en'). No-op if the creator somehow
     * has no associated user (defensive).
     */
    private function dispatchCreatorMail(Creator $creator, Mailable $mailable): void
    {
        $user = $creator->user;
        if ($user === null) {
            return;
        }

        Mail::to($user->email)
            ->locale($user->preferred_language ?: 'en')
            ->queue($mailable);
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

    /**
     * Class-level authorize for the collection endpoint (no model
     * instance) — the index gates on CreatorPolicy::viewAny.
     */
    private function authorizeAny(Request $request, string $ability): void
    {
        $user = $request->user();
        if ($user === null) {
            abort(Response::HTTP_UNAUTHORIZED);
        }
        if (! $user->can($ability, Creator::class)) {
            abort(Response::HTTP_FORBIDDEN);
        }
    }
}
