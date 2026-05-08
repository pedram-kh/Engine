<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Contracts\PwnedPasswordsClientContract;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * HaveIBeenPwned k-anonymity client.
 *
 * Per docs/05-SECURITY-COMPLIANCE.md §6.1, every password set on the
 * platform is checked against breached-password datasets. The
 * k-anonymity protocol guarantees we never transmit the password (or its
 * full hash) over the wire:
 *
 *   1. Hash the plaintext locally with SHA-1.
 *   2. Split the 40-character upper-case hex hash into a 5-char prefix
 *      and a 35-char suffix.
 *   3. GET https://api.pwnedpasswords.com/range/{prefix}.
 *   4. The response is a list of `SUFFIX:COUNT` lines. Look for our
 *      suffix locally; the server has no way to know which hash we cared
 *      about.
 *
 * Failure mode: any upstream error (timeout, 5xx, network) is logged at
 * warning level and the call returns 0 (fail-open). Blocking signups
 * because HIBP is down is worse than the marginal risk of accepting a
 * breached password during an outage. This trade-off is documented in
 * docs/05-SECURITY-COMPLIANCE.md §6.1.
 *
 * SHA-1 is the protocol-mandated algorithm (HIBP's choice). It is used
 * here only as a content addressing scheme — we never store these hashes.
 * Passwords on disk continue to use Argon2id per
 * docs/05-SECURITY-COMPLIANCE.md §6.1.
 */
final class PwnedPasswordsClient implements PwnedPasswordsClientContract
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly Repository $config,
    ) {}

    public function breachCount(string $plaintextPassword): int
    {
        $hash = strtoupper(sha1($plaintextPassword));
        $prefix = substr($hash, 0, 5);
        $suffix = substr($hash, 5);

        $baseUrl = (string) $this->config->get('services.hibp.url', 'https://api.pwnedpasswords.com');
        $timeout = (int) $this->config->get('services.hibp.timeout', 3);

        try {
            $response = $this->http
                ->withHeaders([
                    'Add-Padding' => 'true',
                    'User-Agent' => 'CatalystEngine/1.0 (+security@catalystengine.local)',
                ])
                ->timeout($timeout)
                ->get($baseUrl.'/range/'.$prefix);

            if (! $response->successful()) {
                Log::warning('hibp.upstream_failure', [
                    'status' => $response->status(),
                    'prefix' => $prefix,
                ]);

                return 0;
            }
        } catch (Throwable $exception) {
            Log::warning('hibp.upstream_exception', [
                'message' => $exception->getMessage(),
                'prefix' => $prefix,
            ]);

            return 0;
        }

        return $this->extractCount($response->body(), $suffix);
    }

    private function extractCount(string $body, string $suffix): int
    {
        $lines = preg_split('/\r?\n/', trim($body)) ?: [];

        foreach ($lines as $line) {
            $parts = explode(':', $line, 2);

            if (count($parts) !== 2) {
                continue;
            }

            [$candidateSuffix, $count] = $parts;

            if (strcasecmp($candidateSuffix, $suffix) === 0) {
                $numeric = (int) trim($count);

                // HIBP padding rows have count=0; skip them.
                return $numeric > 0 ? $numeric : 0;
            }
        }

        return 0;
    }
}
