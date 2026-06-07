<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Http\Requests\SuspendAgencyRequest;
use App\Modules\Agencies\Enums\AgencyRole;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyMembership;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Admin agency management (Sprint 13, D-3).
 *
 *   GET  /api/v1/admin/agencies                 — list (cross-agency)
 *   GET  /api/v1/admin/agencies/{agency}        — detail
 *   POST /api/v1/admin/agencies/{agency}/suspend     — suspend (reason req.)
 *   POST /api/v1/admin/agencies/{agency}/reactivate  — reactivate
 *
 * Cross-agency BY DESIGN — the platform_admin bounded bypass
 * (docs/security/tenancy.md § 4). Agency is itself the tenant root, not a
 * tenant-scoped model, so there is no BelongsToAgencyScope to strip here;
 * the agency_users membership counts ARE tenant-scoped, so they are read
 * through a tenant-less aggregate (whereIn over agency ids), never a
 * scoped relation.
 *
 * Suspend sets `suspended_at` + `suspended_reason` and flips
 * `is_active=false`; the auth-layer login block reads `suspended_at`.
 * Both transitions are transactional with their audit row (the
 * agency.suspended / agency.reactivated verbs); suspend carries a
 * mandatory reason.
 */
final class AdminAgencyController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAny($request, 'viewAny');

        $perPage = max(1, min((int) $request->integer('per_page', 25), 100));

        $query = Agency::query()->orderBy('name');

        $search = $request->query('search');
        if (is_string($search) && trim($search) !== '') {
            $needle = trim($search);
            $query->where('name', 'like', "%{$needle}%");
        }

        $status = $request->query('status');
        if ($status === 'suspended') {
            $query->whereNotNull('suspended_at');
        } elseif ($status === 'active') {
            $query->whereNull('suspended_at');
        }

        $paginator = $query->paginate($perPage)->withQueryString();

        /** @var list<Agency> $agencies */
        $agencies = $paginator->items();
        $memberCounts = $this->memberCountsFor($agencies);

        $data = array_map(
            fn (Agency $agency): array => $this->serialize($agency, $memberCounts[$agency->id] ?? 0),
            $agencies,
        );

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

    public function show(Request $request, Agency $agency): JsonResponse
    {
        $this->authorize($request, 'view', $agency);

        $memberCounts = $this->memberCountsFor([$agency]);

        return response()->json([
            'data' => $this->serialize($agency, $memberCounts[$agency->id] ?? 0),
        ]);
    }

    /**
     * POST /api/v1/admin/agencies/{agency}/suspend.
     *
     * Idempotency: suspending an already-suspended agency is 409 +
     * `agency.already_suspended` (the approve-409 precedent) — we never
     * re-stamp `suspended_at` or overwrite the original reason.
     */
    public function suspend(SuspendAgencyRequest $request, Agency $agency): JsonResponse
    {
        $this->authorize($request, 'suspend', $agency);

        if ($agency->isSuspended()) {
            return $this->conflict('agency.already_suspended', 'This agency is already suspended.');
        }

        /** @var User $admin */
        $admin = $request->user();
        $reason = $request->reason();

        DB::transaction(function () use ($agency, $admin, $reason): void {
            $agency->forceFill([
                'suspended_at' => Carbon::now(),
                'suspended_reason' => $reason,
                'is_active' => false,
            ])->save();

            Audit::log(
                action: AuditAction::AgencySuspended,
                actor: $admin,
                subject: $agency,
                reason: $reason,
            );
        });

        $memberCounts = $this->memberCountsFor([$agency]);

        return response()->json([
            'data' => $this->serialize($agency->refresh(), $memberCounts[$agency->id] ?? 0),
        ]);
    }

    /**
     * POST /api/v1/admin/agencies/{agency}/reactivate.
     *
     * Idempotency: reactivating an agency that is not suspended is 409 +
     * `agency.not_suspended`. Clears both suspension columns and restores
     * `is_active=true`. No mandatory reason (restoring the safe state).
     */
    public function reactivate(Request $request, Agency $agency): JsonResponse
    {
        $this->authorize($request, 'reactivate', $agency);

        if (! $agency->isSuspended()) {
            return $this->conflict('agency.not_suspended', 'This agency is not suspended.');
        }

        /** @var User $admin */
        $admin = $request->user();

        DB::transaction(function () use ($agency, $admin): void {
            $agency->forceFill([
                'suspended_at' => null,
                'suspended_reason' => null,
                'is_active' => true,
            ])->save();

            Audit::log(
                action: AuditAction::AgencyReactivated,
                actor: $admin,
                subject: $agency,
            );
        });

        $memberCounts = $this->memberCountsFor([$agency]);

        return response()->json([
            'data' => $this->serialize($agency->refresh(), $memberCounts[$agency->id] ?? 0),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Agency $agency, int $memberCount): array
    {
        return [
            'id' => $agency->ulid,
            'type' => 'agencies',
            'attributes' => [
                'name' => $agency->name,
                'slug' => $agency->slug,
                'country_code' => $agency->country_code,
                'subscription_tier' => $agency->subscription_tier,
                'subscription_status' => $agency->subscription_status,
                'is_active' => $agency->is_active,
                'is_suspended' => $agency->isSuspended(),
                'suspended_at' => $agency->suspended_at?->toIso8601String(),
                'suspended_reason' => $agency->suspended_reason,
                'member_count' => $memberCount,
                'created_at' => $agency->created_at->toIso8601String(),
            ],
        ];
    }

    /**
     * Accepted-membership counts keyed by agency id, computed as a single
     * tenant-less aggregate over the supplied agencies (no per-row N+1,
     * no tenant-scoped relation load).
     *
     * @param  iterable<Agency>  $agencies
     * @return array<int, int>
     */
    private function memberCountsFor(iterable $agencies): array
    {
        $ids = [];
        foreach ($agencies as $agency) {
            $ids[] = $agency->id;
        }

        if ($ids === []) {
            return [];
        }

        /** @var array<int, int> $counts */
        $counts = AgencyMembership::query()
            ->whereIn('agency_id', $ids)
            ->whereNotNull('accepted_at')
            ->whereIn('role', [
                AgencyRole::AgencyAdmin->value,
                AgencyRole::AgencyManager->value,
                AgencyRole::AgencyStaff->value,
            ])
            ->selectRaw('agency_id, count(*) as aggregate')
            ->groupBy('agency_id')
            ->pluck('aggregate', 'agency_id')
            ->map(static fn ($v): int => (int) $v)
            ->all();

        return $counts;
    }

    private function conflict(string $code, string $title): JsonResponse
    {
        return response()->json([
            'errors' => [[
                'status' => '409',
                'code' => $code,
                'title' => $title,
            ]],
        ], 409);
    }

    private function authorize(Request $request, string $ability, Agency $agency): void
    {
        $user = $request->user();
        if ($user === null) {
            abort(Response::HTTP_UNAUTHORIZED);
        }
        if (! $user->can($ability, $agency)) {
            abort(Response::HTTP_FORBIDDEN);
        }
    }

    private function authorizeAny(Request $request, string $ability): void
    {
        $user = $request->user();
        if ($user === null) {
            abort(Response::HTTP_UNAUTHORIZED);
        }
        if (! $user->can($ability, Agency::class)) {
            abort(Response::HTTP_FORBIDDEN);
        }
    }
}
