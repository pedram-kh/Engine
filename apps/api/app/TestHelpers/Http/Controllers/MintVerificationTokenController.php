<?php

declare(strict_types=1);

namespace App\TestHelpers\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\EmailVerificationToken;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/_test/verification-token?email=...
 *
 * Returns a freshly-minted email-verification token for the given
 * (unverified) user, plus the SPA URL the verification email would
 * normally contain. The Playwright spec for the signup → verify flow
 * uses this so it does not have to read Mailhog or rely on a header
 * trap; it can drive the verify page directly with a valid token.
 *
 * Lookup is by exact-match email (lower-cased + trimmed, mirroring
 * the SignUpService normalisation). The endpoint returns 404 for
 * unknown emails — the same status the gate uses for missing tokens,
 * so probes on the gated surface continue to be uninformative.
 *
 * The endpoint deliberately does NOT call SignUpService::sendVerificationMail()
 * — sending an actual mail is irrelevant for E2E. We only need a
 * valid signed token, which {@see EmailVerificationToken::mint()}
 * produces deterministically given the user row.
 */
final class MintVerificationTokenController
{
    public function __invoke(
        Request $request,
        EmailVerificationToken $tokens,
        ConfigRepository $config,
    ): JsonResponse {
        $email = strtolower(trim((string) $request->query('email', '')));

        if ($email === '') {
            return new JsonResponse(['error' => 'email query parameter is required'], 400);
        }

        $user = User::query()->where('email', $email)->first();

        if (! $user instanceof User) {
            return new JsonResponse(['error' => 'no user matched'], 404);
        }

        $token = $tokens->mint($user);
        $base = rtrim((string) $config->get('app.frontend_main_url', 'http://127.0.0.1:5173'), '/');
        $verificationUrl = $base.'/auth/verify-email?'.http_build_query(['token' => $token]);

        return new JsonResponse([
            'data' => [
                'token' => $token,
                'verification_url' => $verificationUrl,
            ],
        ]);
    }
}
