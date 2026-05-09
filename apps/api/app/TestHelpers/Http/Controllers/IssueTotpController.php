<?php

declare(strict_types=1);

namespace App\TestHelpers\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/_test/totp  { "user_id": 123 }
 *
 * Returns the 6-digit TOTP code that is current at "now" for the
 * given user's stored 2FA secret. Used by the Playwright "2FA
 * enrollment + sign-in with code" spec (chunk 6 priority #19) so
 * the spec can drive the SPA without a real authenticator app.
 *
 * Hard isolation invariant (chunk 5 priority #1): the controller
 * routes the request through {@see TwoFactorService::currentCodeFor()},
 * which is the SOLE call site for the underlying TOTP library in the
 * codebase. Adding any other path into the library would break the
 * source-inspection regression test
 * `tests/Unit/Modules/Identity/Services/TwoFactorIsolationTest`.
 *
 * Failure modes:
 *   - Missing/invalid user_id → 422.
 *   - User row not found → 404 (matches the gate's bare-404 stance).
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
        $userId = $request->input('user_id');

        if (! is_numeric($userId)) {
            return new JsonResponse([
                'error' => 'user_id is required and must be numeric',
            ], 422);
        }

        $user = User::query()->find((int) $userId);

        if (! $user instanceof User) {
            return new JsonResponse([
                'error' => 'no user matched',
            ], 404);
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
}
