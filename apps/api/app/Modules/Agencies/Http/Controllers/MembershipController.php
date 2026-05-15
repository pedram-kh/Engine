<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Http\Controllers;

use App\Modules\Agencies\Enums\AgencyRole;
use App\Modules\Agencies\Http\Resources\AgencyMembershipResource;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyMembership;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/agencies/{agency}/members — Sprint 3 Chunk 4 sub-step 3.
 *
 * Paginated members listing for the agency-users page. Replaces the
 * Sprint 2 placeholder which read `useAgencyStore.memberships` from
 * bootstrap (no pagination, no filtering, no search).
 *
 * Authorization: any authenticated member of the agency may LIST
 * members — the agency-users page is visible to all roles; only the
 * Invite + Manage actions are admin-gated. The `tenancy.agency`
 * middleware on the route group already enforces membership.
 *
 * Filtering: ?role=agency_admin|agency_user (optional).
 * Search: ?search=<email-or-name> (ilike on both columns, optional).
 * Sort: ?sort=name|email|created_at|-name|-email|-created_at
 *       (default -created_at).
 * Pagination: ?page=N&per_page=M (per_page hard cap 100; default 25).
 */
final class MembershipController
{
    public const int DEFAULT_PER_PAGE = 25;

    public const int MAX_PER_PAGE = 100;

    public function index(Request $request, Agency $agency): JsonResponse
    {
        $request->validate([
            'role' => ['sometimes', 'string'],
            'search' => ['sometimes', 'string', 'max:255'],
            'sort' => ['sometimes', 'string'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ]);

        $perPage = (int) $request->input('per_page', self::DEFAULT_PER_PAGE);

        $query = AgencyMembership::query()
            ->where('agency_id', $agency->id)
            ->whereNull('deleted_at')
            ->with('user');

        if ($request->has('role')) {
            $roleParam = $request->string('role')->value();
            $role = AgencyRole::tryFrom($roleParam);
            if ($role !== null) {
                $query->where('role', $role->value);
            }
        }

        if ($request->has('search')) {
            $search = trim($request->string('search')->value());
            if ($search !== '') {
                // Postgres supports the `ilike` operator natively; SQLite
                // (the test driver) doesn't. We pick the operator at query
                // time so both production (Postgres) and the unit-test path
                // (SQLite) execute the same intent — case-insensitive
                // substring match across the related User's email + name.
                $driver = $query->getConnection()->getDriverName();
                $isPostgres = $driver === 'pgsql';
                $needle = mb_strtolower($search);
                $like = '%'.str_replace('%', '\%', $needle).'%';

                $query->whereHas('user', function ($q) use ($isPostgres, $like): void {
                    if ($isPostgres) {
                        $q->where(function ($inner) use ($like): void {
                            $inner->where('email', 'ilike', $like)
                                ->orWhere('name', 'ilike', $like);
                        });
                    } else {
                        $q->where(function ($inner) use ($like): void {
                            $inner->whereRaw('LOWER(email) LIKE ?', [$like])
                                ->orWhereRaw('LOWER(name) LIKE ?', [$like]);
                        });
                    }
                });
            }
        }

        $sort = $request->string('sort')->value() ?: '-created_at';
        $this->applySort($query, $sort);

        $paginator = $query->paginate($perPage)->appends($request->query());

        return AgencyMembershipResource::collection($paginator)
            ->response()
            ->setStatusCode(200);
    }

    /**
     * Translate `sort` query string to an ORDER BY clause. Supported
     * keys: name, email, created_at. Prefixing the key with `-` flips
     * to descending. Unknown keys fall back to `-created_at`.
     *
     * `name` and `email` live on the related User row; we join via a
     * subquery to keep the pagination predictable and to avoid the
     * "ambiguous column" pitfall on the `users.created_at` column.
     */
    /**
     * @param  Builder<AgencyMembership>  $query
     */
    private function applySort(Builder $query, string $sort): void
    {
        $direction = 'desc';
        $key = $sort;
        if (str_starts_with($sort, '-')) {
            $direction = 'desc';
            $key = substr($sort, 1);
        } elseif (str_starts_with($sort, '+')) {
            $direction = 'asc';
            $key = substr($sort, 1);
        } else {
            $direction = 'asc';
            $key = $sort;
        }

        switch ($key) {
            case 'name':
                $query->orderBy(
                    User::query()
                        ->select('name')
                        ->whereColumn('users.id', 'agency_users.user_id'),
                    $direction,
                );
                break;

            case 'email':
                $query->orderBy(
                    User::query()
                        ->select('email')
                        ->whereColumn('users.id', 'agency_users.user_id'),
                    $direction,
                );
                break;

            case 'created_at':
                $query->orderBy('agency_users.created_at', $direction);
                break;

            default:
                $query->orderBy('agency_users.created_at', 'desc');
        }
    }
}
