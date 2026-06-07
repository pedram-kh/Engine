<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Core\Errors\ErrorResponse;
use App\Core\Impersonation\ImpersonationContext;
use App\Modules\Identity\Exceptions\ImpersonationException;
use App\Modules\Identity\Models\ImpersonationSession;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\ImpersonationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Main-SPA impersonation hand-off (Sprint 13, D-9).
 *
 *   POST /api/v1/auth/impersonation/claim — consume the one-time token
 *   POST /api/v1/auth/impersonation/end   — end from the impersonated tab
 *
 * Runs on the MAIN SPA origin so the cookie stays `catalyst_main_session`
 * (UseAdminSessionCookie does NOT fire here). The claim logs the
 * impersonated user into the `web` guard; the admin's `web_admin` session,
 * living in a separate cookie, is never touched. `claim` is intentionally
 * UNAUTHENTICATED — the one-time, short-lived, single-use token IS the
 * bearer credential (the magic-link pattern), validated server-side.
 */
final class ImpersonationController
{
    public function __construct(
        private readonly ImpersonationService $impersonation,
        private readonly ImpersonationContext $context,
    ) {}

    /**
     * Banner hydration on cold load. The enforcement middleware has already
     * run by the time this resolves, so {@see ImpersonationContext} is the
     * source of truth: populated (and TTL-validated) for a live impersonation,
     * empty otherwise. Returns a cheap flag + the authoritative expiry so the
     * main SPA can paint its "you are impersonating" banner + countdown.
     */
    public function status(Request $request): JsonResponse
    {
        if (! $this->context->isImpersonating()) {
            return response()->json(['data' => ['active' => false]]);
        }

        /** @var ImpersonationSession|null $session */
        $session = ImpersonationSession::query()
            ->where('ulid', $this->context->sessionUlid())
            ->first();

        if ($session === null) {
            return response()->json(['data' => ['active' => false]]);
        }

        return response()->json([
            'data' => [
                'active' => true,
                'expires_at' => $session->expires_at->toIso8601String(),
            ],
        ]);
    }

    public function claim(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
        ]);

        try {
            $session = $this->impersonation->claim($validated['token'], $request);
        } catch (ImpersonationException $e) {
            return ErrorResponse::single(
                request: $request,
                status: $e->status,
                code: $e->errorCode,
                title: $e->getMessage(),
            );
        }

        /** @var User $target */
        $target = $this->impersonation->resolveImpersonatedUser($session);

        return response()->json([
            'data' => [
                'id' => $target->ulid,
                'type' => 'users',
                'attributes' => [
                    'name' => $target->name,
                    'email' => $target->email,
                    'user_type' => $target->type->value,
                    'impersonated' => true,
                    'expires_at' => $session->expires_at->toIso8601String(),
                ],
            ],
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function end(Request $request): JsonResponse
    {
        $this->impersonation->endFromMain($request);

        return response()->json(['data' => ['ended' => true]]);
    }
}
