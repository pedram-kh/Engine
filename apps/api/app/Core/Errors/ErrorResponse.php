<?php

declare(strict_types=1);

namespace App\Core\Errors;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Builder for the standard error envelope from docs/04-API-DESIGN.md §8.
 *
 * Every error response in the API matches the shape:
 *
 *   {
 *     "errors": [
 *       { "id", "status", "code", "title", "detail", "source", "meta" }
 *     ],
 *     "meta": { "request_id": "..." }
 *   }
 *
 * This helper centralises construction so individual controllers do not
 * hand-roll the structure (and inevitably drift from the spec).
 */
final class ErrorResponse
{
    /**
     * @param  array<string, scalar|null>|null  $source
     * @param  array<string, mixed>|null  $meta
     * @param  array<string, string>  $headers
     */
    public static function single(
        Request $request,
        int $status,
        string $code,
        string $title,
        ?string $detail = null,
        ?array $source = null,
        ?array $meta = null,
        array $headers = [],
    ): JsonResponse {
        $error = [
            'id' => (string) Str::ulid(),
            'status' => (string) $status,
            'code' => $code,
            'title' => $title,
        ];

        if ($detail !== null) {
            $error['detail'] = $detail;
        }

        if ($source !== null) {
            $error['source'] = $source;
        }

        if ($meta !== null) {
            $error['meta'] = $meta;
        }

        return new JsonResponse([
            'errors' => [$error],
            'meta' => [
                'request_id' => $request->headers->get('X-Request-Id', (string) Str::ulid()),
            ],
        ], $status, $headers);
    }
}
