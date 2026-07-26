<?php

declare(strict_types=1);

namespace App\Core\Errors;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Converts a 403 into the canonical JSON:API error envelope documented in
 * `docs/04-API-DESIGN.md §8`.
 *
 * This is the 403 half of the same bug {@see ValidationExceptionRenderer}
 * fixed for 422s (Sprint 3 chunk 5). Every authorization denial in the API —
 * all 82 `Gate::authorize()` / `$this->authorize()` call sites plus the
 * `abort(403)` sites in the admin controllers — was returning Laravel's
 * default shape:
 *
 *   { "message": "This action is unauthorized." }
 *
 * There is no `errors[]` array, so the SPA's `ApiError.fromEnvelope`
 * (packages/api-client/src/errors.ts) rejects the body as malformed and
 * renders the useless `Unrecognized error response (HTTP 403).` — which is
 * exactly what a creator saw when trying to message an agency they had been
 * disconnected from (AH-051 follow-up).
 *
 * ⚠ WHY THIS HOOKS `HttpExceptionInterface` AND NOT `AuthorizationException`:
 * Laravel's `Handler::render()` calls `prepareException()` BEFORE
 * `renderViaCallbacks()`, and `prepareException()` has already rewritten a
 * `Gate` denial into `AccessDeniedHttpException` (or a plain `HttpException`
 * when the policy set an explicit status) by the time any registered render
 * callback is consulted. A callback typed `AuthorizationException` therefore
 * never fires. Typing the interface also picks up the bare `abort(403)`
 * sites, which were never `AuthorizationException`s at all. The status filter
 * keeps this renderer to 403 only — 404/419/etc. keep their current
 * behaviour, which existing tests pin.
 */
final class ForbiddenExceptionRenderer
{
    public const CODE = 'auth.forbidden';

    /**
     * Shown when the thrown exception carries no message of its own — the
     * `abort(403)` case. Deliberately actor-agnostic and reason-free: a
     * generic gate denial must not become an oracle for what exists or why
     * access failed. Surfaces that can say something more useful map
     * {@see self::CODE} to their own copy client-side.
     */
    public const FALLBACK_TITLE = 'You do not have permission to perform this action.';

    public static function render(HttpExceptionInterface $exception, Request $request): JsonResponse
    {
        // Preserve a deliberate `Response::deny('…')` message from a policy;
        // fall back to the canonical sentence for `abort(403)` (empty message)
        // so the SPA never renders a blank title.
        $message = trim($exception->getMessage());
        $title = $message !== '' ? $message : self::FALLBACK_TITLE;

        return ErrorResponse::single(
            $request,
            $exception->getStatusCode(),
            self::CODE,
            $title,
        );
    }
}
