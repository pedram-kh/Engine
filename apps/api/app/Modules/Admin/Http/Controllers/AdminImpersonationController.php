<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Core\Errors\ErrorResponse;
use App\Modules\Admin\Http\Requests\StartImpersonationRequest;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Exceptions\ImpersonationException;
use App\Modules\Identity\Models\ImpersonationSession;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\ImpersonationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * Admin-side impersonation surface (Sprint 13, D-9).
 *
 *   GET  /api/v1/admin/impersonate/users?q=     — target search
 *   POST /api/v1/admin/impersonate              — start (reason required)
 *   POST /api/v1/admin/impersonate/end          — end the active session
 *   GET  /api/v1/admin/impersonate/sessions     — the impersonation log
 *
 * Runs on the `web_admin` guard + EnsureMfaForAdmins. The start returns a
 * one-time hand-off token + the main-SPA base URL; the admin SPA opens the
 * main SPA in a new tab and claims it there (the two-cookie bridge). The
 * admin's own session is never touched — the admin stays logged in as
 * themselves on the admin SPA throughout.
 */
final class AdminImpersonationController
{
    public function __construct(private readonly ImpersonationService $impersonation) {}

    public function searchUsers(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin($request);

        $q = trim((string) $request->query('q', ''));

        $query = User::query()
            // No-escalation: platform admins are never impersonation targets,
            // so they are not even searchable as candidates.
            ->where('type', '!=', UserType::PlatformAdmin->value)
            ->orderBy('name')
            ->limit(20);

        if ($q !== '') {
            $query->where(function ($inner) use ($q): void {
                $inner->where('email', 'like', "%{$q}%")
                    ->orWhere('name', 'like', "%{$q}%")
                    ->orWhere('ulid', $q);
            });
        }

        $users = $query->get(['ulid', 'name', 'email', 'type']);

        $data = $users
            ->map(static fn (User $user): array => [
                'id' => $user->ulid,
                'type' => 'users',
                'attributes' => [
                    'name' => $user->name,
                    'email' => $user->email,
                    'user_type' => $user->type->value,
                ],
            ])
            ->all();

        return response()->json(['data' => $data]);
    }

    public function start(StartImpersonationRequest $request): JsonResponse
    {
        $this->authorizePlatformAdmin($request);

        /** @var User $admin */
        $admin = $request->user();

        /** @var User|null $target */
        $target = User::query()->where('ulid', $request->userUlid())->first();
        if ($target === null) {
            abort(Response::HTTP_NOT_FOUND);
        }

        try {
            $result = $this->impersonation->start($admin, $target, $request->reason(), $request->ip());
        } catch (ImpersonationException $e) {
            return ErrorResponse::single(
                request: $request,
                status: $e->status,
                code: $e->errorCode,
                title: $e->getMessage(),
            );
        }

        return response()->json([
            'data' => [
                'id' => $result['session']->ulid,
                'type' => 'impersonation_sessions',
                'attributes' => [
                    'handoff_token' => $result['token'],
                    'main_spa_url' => (string) config('app.frontend_url'),
                    'impersonated_user_ulid' => $target->ulid,
                    'impersonated_user_name' => $target->name,
                    'expires_at' => $result['session']->expires_at->toIso8601String(),
                ],
            ],
        ], Response::HTTP_CREATED);
    }

    public function end(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin($request);

        /** @var User $admin */
        $admin = $request->user();

        $session = $this->impersonation->endForAdmin($admin);

        return response()->json([
            'data' => [
                'ended' => $session !== null,
            ],
        ]);
    }

    /**
     * GET /api/v1/admin/impersonate/sessions — the impersonation log.
     *
     * Read-only, cross-agency, CURSOR-paginated view over the append-only
     * `admin_impersonation_sessions` table (the §6.8 log of record). Mirrors
     * the audit-log viewer (D-5): keyset pagination on the monotonic `id`,
     * opaque next/prev tokens. The `status` filter is derived from the TTL
     * authority (`ended_at` / `expires_at`); `q` matches either party.
     */
    public function sessions(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin($request);

        $perPage = max(1, min((int) $request->integer('per_page', 50), 100));
        $now = Carbon::now();

        $query = ImpersonationSession::query()
            ->with(['admin:id,name,email,ulid', 'impersonatedUser:id,name,email,ulid'])
            ->orderByDesc('id');

        $status = $request->query('status');
        if ($status === 'active') {
            $query->whereNull('ended_at')->where('expires_at', '>', $now);
        } elseif ($status === 'ended') {
            $query->whereNotNull('ended_at');
        } elseif ($status === 'expired') {
            $query->whereNull('ended_at')->where('expires_at', '<=', $now);
        }

        $q = $request->query('q');
        if (is_string($q) && trim($q) !== '') {
            $needle = trim($q);
            $query->where(function ($outer) use ($needle): void {
                $outer->whereHas('admin', function ($inner) use ($needle): void {
                    $inner->where('name', 'like', "%{$needle}%")
                        ->orWhere('email', 'like', "%{$needle}%")
                        ->orWhere('ulid', $needle);
                })->orWhereHas('impersonatedUser', function ($inner) use ($needle): void {
                    $inner->where('name', 'like', "%{$needle}%")
                        ->orWhere('email', 'like', "%{$needle}%")
                        ->orWhere('ulid', $needle);
                });
            });
        }

        $dateFrom = $this->parseDate($request->query('date_from'));
        if ($dateFrom !== null) {
            $query->where('started_at', '>=', $dateFrom->startOfDay());
        }

        $dateTo = $this->parseDate($request->query('date_to'));
        if ($dateTo !== null) {
            $query->where('started_at', '<=', $dateTo->endOfDay());
        }

        $paginator = $query->cursorPaginate($perPage)->withQueryString();

        $data = array_map(
            static fn (ImpersonationSession $session): array => self::serializeSession($session, $now),
            $paginator->items(),
        );

        return response()->json([
            'data' => $data,
            'meta' => [
                'per_page' => $paginator->perPage(),
                'next_cursor' => $paginator->nextCursor()?->encode(),
                'prev_cursor' => $paginator->previousCursor()?->encode(),
                'has_more' => $paginator->hasMorePages(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function serializeSession(ImpersonationSession $session, Carbon $now): array
    {
        return [
            'id' => $session->ulid,
            'type' => 'impersonation_sessions',
            'attributes' => [
                'admin_name' => $session->admin?->name,
                'admin_email' => $session->admin?->email,
                'impersonated_user_name' => $session->impersonatedUser?->name,
                'impersonated_user_email' => $session->impersonatedUser?->email,
                'impersonated_user_ulid' => $session->impersonatedUser?->ulid,
                'reason' => $session->reason,
                'status' => self::sessionStatus($session, $now),
                'started_at' => $session->started_at->toIso8601String(),
                'claimed_at' => $session->claimed_at?->toIso8601String(),
                'ended_at' => $session->ended_at?->toIso8601String(),
                'expires_at' => $session->expires_at->toIso8601String(),
                'ip' => $session->ip,
            ],
        ];
    }

    /**
     * Derive the lifecycle status from the TTL authority: an explicit
     * `ended_at` wins, otherwise a past `expires_at` means the TTL lapsed,
     * otherwise it is still live.
     */
    private static function sessionStatus(ImpersonationSession $session, Carbon $now): string
    {
        if ($session->ended_at !== null) {
            return 'ended';
        }

        if ($session->expires_at->lessThanOrEqualTo($now)) {
            return 'expired';
        }

        return 'active';
    }

    private function parseDate(mixed $raw): ?Carbon
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    private function authorizePlatformAdmin(Request $request): void
    {
        $user = $request->user();
        if ($user === null) {
            abort(Response::HTTP_UNAUTHORIZED);
        }
        if ($user->type !== UserType::PlatformAdmin) {
            abort(Response::HTTP_FORBIDDEN);
        }
    }
}
