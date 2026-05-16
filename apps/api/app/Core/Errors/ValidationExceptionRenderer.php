<?php

declare(strict_types=1);

namespace App\Core\Errors;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Converts a Laravel {@see ValidationException} into the canonical JSON:API
 * error envelope documented in `docs/04-API-DESIGN.md §8`.
 *
 * Without this normalizer, FormRequest validation failures slip through
 * Laravel's default renderer and return the legacy shape:
 *
 *   { "message": "...", "errors": { "field": ["msg", ...] } }
 *
 * That shape is incompatible with the SPA's `ApiError.fromEnvelope`
 * parser (packages/api-client/src/errors.ts), which expects an
 * `errors[]` array of `{ id, status, code, title, detail, source, meta }`
 * entries. The mismatch was discovered in the wild on
 * `POST /api/v1/agencies/{agency}/brands` (Sprint 3 chunk 4) — every
 * 422 surfaced as `[http.invalid_response_body] Unrecognized error
 * response (HTTP 422)` in the UI, hiding the real validation message.
 *
 * Emits one envelope entry per field violation (one entry per
 * `(field, message)` pair when Laravel reports multiple messages for
 * the same field). Each entry carries:
 *
 *   - `code: 'validation.failed'` — single canonical code per Sprint 1
 *     chunk-4 standard 5.4 (non-fingerprinting); per-rule disambiguation
 *     lives in `meta.rule` when the validator exposes it.
 *   - `source.pointer: '/data/attributes/<field>'` — JSON Pointer to the
 *     offending field, matching the JSON:API contract.
 *   - `meta.field: '<field>'` — denormalised field name for callers
 *     that prefer scanning meta over parsing the pointer.
 *   - `detail` and `title` set to Laravel's human-readable message.
 */
final class ValidationExceptionRenderer
{
    public const CODE = 'validation.failed';

    public static function render(ValidationException $exception, Request $request): JsonResponse
    {
        // Honor the `failedValidation()` escape hatch on FormRequest:
        // when a request handler builds its own response (typically to
        // emit a domain-specific envelope code like
        // `creator.admin.field_status_immutable`), Laravel attaches it
        // to `ValidationException::$response`. We must not regenerate
        // a generic `validation.failed` envelope over the top of it,
        // or the caller's bespoke code is silently lost. See
        // `AdminUpdateCreatorRequest::failedValidation()` for the
        // canonical in-tree consumer of this seam.
        $preBuilt = $exception->response;
        if ($preBuilt instanceof JsonResponse) {
            return $preBuilt;
        }

        $status = $exception->status;
        $errors = $exception->errors();
        $failed = $exception->validator->failed();

        $entries = [];
        foreach ($errors as $field => $messages) {
            if (! is_string($field) || ! is_array($messages)) {
                continue;
            }

            $ruleName = self::firstRuleName($failed[$field] ?? null);

            foreach ($messages as $message) {
                if (! is_string($message)) {
                    continue;
                }

                $entry = [
                    'id' => (string) Str::ulid(),
                    'status' => (string) $status,
                    'code' => self::CODE,
                    'title' => $message,
                    'detail' => $message,
                    'source' => ['pointer' => '/data/attributes/'.$field],
                    'meta' => ['field' => $field],
                ];

                if ($ruleName !== null) {
                    $entry['meta']['rule'] = $ruleName;
                }

                $entries[] = $entry;
            }
        }

        return new JsonResponse([
            'errors' => $entries,
            'meta' => [
                'request_id' => $request->headers->get('X-Request-Id', (string) Str::ulid()),
            ],
        ], $status);
    }

    /**
     * Extract the first rule name from the validator's `failed()` output
     * for a single field. The shape is `[RuleClass => [parameters]]`
     * (e.g. `['Required' => []]` or `['Min' => [2]]`). We expose only
     * the rule class name — the parameters are noisy for clients and
     * the human-readable message already encodes them.
     *
     * @param  array<string, array<int, mixed>>|null  $fieldFailures
     */
    private static function firstRuleName(?array $fieldFailures): ?string
    {
        if ($fieldFailures === null || $fieldFailures === []) {
            return null;
        }

        $first = array_key_first($fieldFailures);

        return is_string($first) ? $first : null;
    }
}
