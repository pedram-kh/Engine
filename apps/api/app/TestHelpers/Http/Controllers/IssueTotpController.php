<?php

declare(strict_types=1);

namespace App\TestHelpers\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/_test/totp  { "user_id": 123 }   — or — { "email": "jane@example.com" }
 *
 * Returns the 6-digit TOTP code that is current at "now" for the
 * given user's stored 2FA secret. Used by the Playwright "2FA
 * enrollment + sign-in with code" spec (chunk 6 priority #19) so
 * the spec can drive the SPA without a real authenticator app.
 *
 * Either `user_id` (numeric primary key) or `email` (looked up via
 * the same lower-case+trim normalisation as `SignUpService`) may be
 * supplied. The Playwright runner only ever sees the user's `ulid`
 * via the `/me` envelope, so the email branch is what spec #19 uses
 * end-to-end. The `user_id` branch is preserved for tests + manual
 * use — both branches share the same controller body once the user
 * row is resolved.
 *
 * Hard isolation invariant (chunk 5 priority #1): the controller
 * routes the request through {@see TwoFactorService::currentCodeFor()},
 * which is the SOLE call site for the underlying TOTP library in the
 * codebase. Adding any other path into the library would break the
 * source-inspection regression test
 * `tests/Unit/Modules/Identity/Services/TwoFactorIsolationTest`.
 *
 * Failure modes:
 *   - Missing both user_id and email → 422.
 *   - Invalid user_id (non-numeric) → 422.
 *   - User row not found (by id OR email) → 404 (matches the gate's
 *     bare-404 stance).
 *   - User has no two_factor_secret → 422; the spec should generate
 *     it via the standard `/2fa/enable` flow before requesting a
 *     code.
 *
 * Clock note: the issued code is derived from PHP's `time()` and does
 * NOT honor `Carbon::setTestNow()` — see the WARNING on
 * {@see TwoFactorService::currentCodeFor()} and the corresponding entry
 * in `docs/tech-debt.md`.
 */
final class IssueTotpController
{
    public function __invoke(Request $request, TwoFactorService $twoFactor): JsonResponse
    {
        $user = $this->resolveUser($request);

        if ($user instanceof JsonResponse) {
            return $user;
        }

        $secret = $user->two_factor_secret;

        if (! is_string($secret) || $secret === '') {
            return new JsonResponse([
                'error' => 'user has no two_factor_secret yet — run /2fa/enable first',
            ], 422);
        }

        return new JsonResponse([
            'data' => [
                'code' => $twoFactor->currentCodeFor($secret),
            ],
        ]);
    }

    /**
     * Resolve the target user from either `user_id` or `email`. Returns
     * the `User` row on success, or a `JsonResponse` carrying the
     * appropriate 4xx for the failure mode.
     *
     * The two branches share the validation envelope shape (`{error: …}`)
     * with the rest of the test-helpers surface so spec failures surface
     * a debuggable body.
     */
    private function resolveUser(Request $request): User|JsonResponse
    {
        $userId = $request->input('user_id');
        $email = $request->input('email');

        if ($userId !== null && $userId !== '') {
            if (! is_numeric($userId)) {
                return new JsonResponse([
                    'error' => 'user_id must be numeric',
                ], 422);
            }
            $user = User::query()->find((int) $userId);
            if (! $user instanceof User) {
                return new JsonResponse(['error' => 'no user matched'], 404);
            }

            return $user;
        }

        if (is_string($email) && trim($email) !== '') {
            $normalised = strtolower(trim($email));
            $user = User::query()->where('email', $normalised)->first();
            if (! $user instanceof User) {
                return new JsonResponse(['error' => 'no user matched'], 404);
            }

            return $user;
        }

        return new JsonResponse([
            'error' => 'either user_id (numeric) or email (string) is required',
        ], 422);
    }
}
