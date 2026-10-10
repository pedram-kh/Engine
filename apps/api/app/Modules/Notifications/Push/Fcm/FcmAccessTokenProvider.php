<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Push\Fcm;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Exchanges the service-account credential for a short-lived OAuth access
 * token (the Google "JWT bearer" grant) and caches it.
 *
 * The signed JWT is RS256 via `openssl_sign` — no JWT library. The token URL is
 * FIXED by config, never read from the credential JSON (a bad env value must not
 * be able to redirect the signed assertion to another host).
 *
 * Failure vocabulary is internal: a 4xx from the token endpoint means our
 * credential is wrong ({@see FcmConfigException}); a 5xx / timeout is
 * transient ({@see FcmTransientException}).
 */
final class FcmAccessTokenProvider
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    /** Refresh this many seconds BEFORE Google's stated expiry. */
    private const SAFETY_MARGIN = 120;

    public function __construct(private readonly HttpFactory $http) {}

    /**
     * @throws FcmConfigException credential unusable
     * @throws FcmTransientException token endpoint unreachable / 5xx
     */
    public function token(FcmServiceAccount $account, bool $forceRefresh = false): string
    {
        $key = $this->cacheKey($account);

        if (! $forceRefresh) {
            $cached = Cache::get($key);

            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }

        [$token, $expiresIn] = $this->exchange($account);

        Cache::put($key, $token, max(1, $expiresIn - self::SAFETY_MARGIN));

        return $token;
    }

    public function forget(FcmServiceAccount $account): void
    {
        Cache::forget($this->cacheKey($account));
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function exchange(FcmServiceAccount $account): array
    {
        $tokenUrl = (string) config('integrations.push.fcm.token_url');
        $timeout = max(1, (int) config('integrations.push.fcm.timeout', 5));

        $assertion = $this->signedAssertion($account, $tokenUrl);

        try {
            $response = $this->http
                ->asForm()
                ->connectTimeout($timeout)
                ->timeout($timeout)
                ->post($tokenUrl, [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $assertion,
                ]);
        } catch (ConnectionException $e) {
            throw new FcmTransientException('FCM token endpoint unreachable: '.$e->getMessage(), 0, $e);
        }

        if ($response->serverError() || $response->status() === 429) {
            throw new FcmTransientException('FCM token endpoint returned HTTP '.$response->status().'.');
        }

        if (! $response->successful()) {
            // invalid_grant / invalid_client / etc. — OUR credential is wrong.
            $error = $response->json('error');
            throw new FcmConfigException('FCM token exchange rejected (HTTP '.$response->status().', '.(is_string($error) ? $error : 'unknown').').');
        }

        $token = $response->json('access_token');
        $expiresIn = $response->json('expires_in');

        if (! is_string($token) || $token === '') {
            throw new FcmConfigException('FCM token exchange returned no access_token.');
        }

        return [$token, is_numeric($expiresIn) ? (int) $expiresIn : 3600];
    }

    private function signedAssertion(FcmServiceAccount $account, string $audience): string
    {
        $now = time();

        $header = $this->b64url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $claims = $this->b64url((string) json_encode([
            'iss' => $account->clientEmail,
            'scope' => self::SCOPE,
            'aud' => $audience,
            'iat' => $now,
            'exp' => $now + 3600,
        ], JSON_THROW_ON_ERROR));

        $signingInput = $header.'.'.$claims;

        $signature = '';

        try {
            $signed = openssl_sign($signingInput, $signature, $account->privateKey, OPENSSL_ALGO_SHA256);
        } catch (Throwable) {
            $signed = false;
        }

        if (! $signed) {
            throw new FcmConfigException('FCM service account private_key could not sign (not a valid RSA private key).');
        }

        return $signingInput.'.'.$this->b64url($signature);
    }

    /** One cache entry per credential, keyed by a hash so no identifying string is a key. */
    private function cacheKey(FcmServiceAccount $account): string
    {
        return 'push:fcm:access_token:'.hash('sha256', $account->clientEmail);
    }

    private function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
