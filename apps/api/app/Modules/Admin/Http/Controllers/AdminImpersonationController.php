<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Core\Errors\ErrorResponse;
use App\Modules\Admin\Http\Requests\StartImpersonationRequest;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Exceptions\ImpersonationException;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\ImpersonationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Admin-side impersonation surface (Sprint 13, D-9).
 *
 *   GET  /api/v1/admin/impersonate/users?q=  — target search
 *   POST /api/v1/admin/impersonate           — start (reason required)
 *   POST /api/v1/admin/impersonate/end       — end the active session
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
