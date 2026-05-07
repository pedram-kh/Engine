<?php

declare(strict_types=1);

namespace App\Modules\Audit\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces the X-Action-Reason header on destructive / sensitive endpoints.
 *
 * Routes that mutate sensitive state declare `->middleware('action.reason')`.
 * If the header is missing or contains only whitespace, the middleware
 * short-circuits with the documented `validation.reason_required` 400 envelope
 * (docs/04-API-DESIGN.md §26, error envelope shape from §8).
 *
 * The reason value is trimmed and re-attached to the request via the
 * `X-Action-Reason` header so downstream controllers / services see a
 * normalised, non-empty string.
 */
final class RequireActionReason
{
    public const HEADER = 'X-Action-Reason';

    public function handle(Request $request, Closure $next): Response
    {
        $reason = $request->header(self::HEADER);

        if (! is_string($reason)) {
            return $this->missingReasonResponse($request);
        }

        $trimmed = trim($reason);
        if ($trimmed === '') {
            return $this->missingReasonResponse($request);
        }

        $request->headers->set(self::HEADER, $trimmed);

        return $next($request);
    }

    private function missingReasonResponse(Request $request): JsonResponse
    {
        return new JsonResponse([
            'errors' => [
                [
                    'id' => (string) Str::ulid(),
                    'status' => '400',
                    'code' => 'validation.reason_required',
                    'title' => 'A reason is required for this action.',
                    'detail' => sprintf(
                        'This endpoint requires a non-empty %s header explaining why the action is being performed.',
                        self::HEADER,
                    ),
                    'source' => [
                        'header' => self::HEADER,
                    ],
                ],
            ],
            'meta' => [
                'request_id' => $request->headers->get('X-Request-Id', (string) Str::ulid()),
            ],
        ], 400);
    }
}
