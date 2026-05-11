<?php

declare(strict_types=1);

namespace App\TestHelpers\Http\Controllers;

use App\Modules\Identity\Services\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use SensitiveParameter;

/**
 * POST /api/v1/_test/totp/secret  { "secret": "<base32 secret>" }
 *
 * Returns the 6-digit TOTP code that is current at "now" for the given
 * secret. The companion to {@see IssueTotpController}, with a different
 * input contract:
 *
 *   - {@see IssueTotpController} reads the secret from
 *     `users.two_factor_secret` (the post-`/2fa/confirm` location) and
 *     services specs that simulate a "user with 2FA already enrolled"
 *     scenario.
 *
 *   - This controller takes the secret directly. During in-flight
 *     enrollment (after `/2fa/enable` but before `/2fa/confirm`) the
 *     secret lives in cache under
 *     `identity:2fa:enroll:{user_id}:{provisional_token}` — the
 *     provisional token is needed to look it up, and walking cache
 *     keys to find it is driver-specific (different for `array`,
 *     `database`, `redis`). The simplest contract that doesn't
 *     contaminate the user row, doesn't depend on cache backends, and
 *     stays inside the chunk-5 isolation invariant
 *     ({@see TwoFactorService::currentCodeFor()} is the SOLE call into
 *     the underlying TOTP library) is to take the secret as input.
 *     The SPA already exposes the secret via the `enable-totp-manual-key`
 *     `data-test` element (so the user can type it into an
 *     authenticator app); the spec reads the same DOM text and
 *     forwards it here.
 *
 * Honest deviation from the chunk-7.1 kickoff
 * -------------------------------------------
 * The kickoff text described this endpoint as accepting `email` and
 * minting "the user's pending two-factor-secret". The cleanest design
 * given the cache-vs-row state model is to accept the secret directly
 * — flagged in the chunk-7.1 review's "Open questions" section.
 *
 * Failure modes:
 *   - Missing or non-string `secret`         → 422.
 *   - Empty `secret` after trim              → 422.
 *   - Malformed `secret`                     → 422 with a debuggable
 *     message; the underlying TOTP library throws on a non-base32
 *     secret, and we want the spec author to see "you fed me garbage"
 *     instead of "the code didn't verify".
 *
 * Hard isolation invariant (chunk 5 priority #1): every call into the
 * underlying TOTP library continues to live inside
 * {@see TwoFactorService}. This controller routes through
 * {@see TwoFactorService::currentCodeFor()} just like
 * {@see IssueTotpController}, so the
 * `tests/Unit/Modules/Identity/Services/TwoFactorIsolationTest`
 * source-inspection test stays green. The IssueTotpFromSecretTest
 * source-inspection check at the bottom of that file pins the same
 * invariant for this controller specifically.
 *
 * Clock note: identical to {@see IssueTotpController}, the issued code
 * is derived from PHP's `time()` and does NOT honor
 * `Carbon::setTestNow()`. The chunk-7.1 spec does not need clock-skew
 * + TOTP combined; the docs/tech-debt.md "TOTP issuance does not
 * honor Carbon::setTestNow" entry remains the long-form trigger for
 * when that combo becomes necessary.
 */
final class IssueTotpFromSecretController
{
    public function __invoke(Request $request, TwoFactorService $twoFactor): JsonResponse
    {
        /** @var mixed $rawSecret */
        $rawSecret = $request->input('secret');

        if (! is_string($rawSecret)) {
            return new JsonResponse([
                'error' => 'secret field is required (base32 string)',
            ], 422);
        }

        $secret = trim($rawSecret);

        if ($secret === '') {
            return new JsonResponse([
                'error' => 'secret field is required (base32 string)',
            ], 422);
        }

        try {
            return $this->codeResponse($twoFactor, $secret);
        } catch (\Throwable $e) {
            // The Google2FA library throws on non-base32 input. We
            // expose the message verbatim so the spec author sees the
            // library's "Invalid characters in the base32 string."
            // diagnostic instead of a generic 500.
            return new JsonResponse([
                'error' => 'secret is not a valid base32 string ('.$e->getMessage().')',
            ], 422);
        }
    }

    /**
     * Tiny indirection so the try/catch above wraps only the
     * code-derivation path, not the JSON-response construction.
     */
    private function codeResponse(
        TwoFactorService $twoFactor,
        #[SensitiveParameter] string $secret,
    ): JsonResponse {
        return new JsonResponse([
            'data' => [
                'code' => $twoFactor->currentCodeFor($secret),
            ],
        ]);
    }
}
