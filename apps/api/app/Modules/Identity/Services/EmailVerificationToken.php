<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Models\User;
use Illuminate\Contracts\Config\Repository;

/**
 * Compact, signed verification token: `payload.signature`.
 *
 * - `payload` is the base64url JSON-encoded `{user_id, email_hash, expires_at}`.
 *   `user_id` is the internal numeric id (the URL never leaves the
 *   transport, and the email itself is in the user's own inbox).
 *   `email_hash` is `sha1(strtolower(email))` so a token written before
 *   the user changed their primary email cannot be replayed against the
 *   new address.
 * - `signature` is `hmac_sha256(payload, APP_KEY)` base64url-encoded.
 *
 * The token format is intentionally self-contained so we don't need a
 * `email_verifications` table for Phase 1. The single-use guarantee is
 * carried by `users.email_verified_at`: re-clicking after verification
 * short-circuits in {@see EmailVerificationService}.
 *
 * Reference: docs/05-SECURITY-COMPLIANCE.md §6.5.
 */
final class EmailVerificationToken
{
    /**
     * 24h per docs/05-SECURITY-COMPLIANCE.md §6.5.
     */
    public const int LIFETIME_HOURS = 24;

    public function __construct(private readonly Repository $config) {}

    public function mint(User $user, ?int $now = null): string
    {
        $now ??= time();

        $payload = [
            'user_id' => $user->id,
            'email_hash' => $this->hashEmail($user->email),
            'expires_at' => $now + (self::LIFETIME_HOURS * 3600),
        ];

        $encoded = $this->base64UrlEncode((string) json_encode($payload, JSON_THROW_ON_ERROR));
        $signature = $this->base64UrlEncode($this->hmac($encoded));

        return $encoded.'.'.$signature;
    }

    public function decode(string $token): EmailVerificationTokenPayload
    {
        $parts = explode('.', $token);

        if (count($parts) !== 2) {
            return EmailVerificationTokenPayload::malformed();
        }

        [$encoded, $signature] = $parts;

        $expectedSignature = $this->base64UrlEncode($this->hmac($encoded));

        if (! hash_equals($expectedSignature, $signature)) {
            return EmailVerificationTokenPayload::malformed();
        }

        $rawPayload = $this->base64UrlDecode($encoded);

        if ($rawPayload === false) {
            return EmailVerificationTokenPayload::malformed();
        }

        try {
            /** @var array{user_id?: mixed, email_hash?: mixed, expires_at?: mixed} $decoded */
            $decoded = json_decode($rawPayload, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return EmailVerificationTokenPayload::malformed();
        }

        if (! is_int($decoded['user_id'] ?? null)
            || ! is_string($decoded['email_hash'] ?? null)
            || ! is_int($decoded['expires_at'] ?? null)
        ) {
            return EmailVerificationTokenPayload::malformed();
        }

        return EmailVerificationTokenPayload::valid(
            userId: $decoded['user_id'],
            emailHash: $decoded['email_hash'],
            expiresAt: $decoded['expires_at'],
        );
    }

    public function hashEmail(string $email): string
    {
        return sha1(strtolower(trim($email)));
    }

    private function hmac(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->key(), binary: true);
    }

    private function key(): string
    {
        $key = (string) $this->config->get('app.key', '');

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            if ($decoded !== false) {
                return $decoded;
            }
        }

        return $key;
    }

    private function base64UrlEncode(string $input): string
    {
        return rtrim(strtr(base64_encode($input), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $input): string|false
    {
        $remainder = strlen($input) % 4;
        if ($remainder !== 0) {
            $input .= str_repeat('=', 4 - $remainder);
        }

        return base64_decode(strtr($input, '-_', '+/'), true);
    }
}
