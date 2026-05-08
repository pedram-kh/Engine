<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

/**
 * Decoded {@see EmailVerificationToken} payload.
 *
 * `valid === false` means the token failed cryptographic / structural
 * checks (bad signature, malformed JSON, wrong field types). Callers
 * MUST treat that case as `verification_invalid` without reading any of
 * the other fields. The `expires_at` and `user_id` fields are zero in
 * that case as a safety net, but no logic should depend on them when
 * `valid` is false.
 */
final readonly class EmailVerificationTokenPayload
{
    private function __construct(
        public bool $valid,
        public int $userId,
        public string $emailHash,
        public int $expiresAt,
    ) {}

    public static function valid(int $userId, string $emailHash, int $expiresAt): self
    {
        return new self(true, $userId, $emailHash, $expiresAt);
    }

    public static function malformed(): self
    {
        return new self(false, 0, '', 0);
    }

    public function isExpired(?int $now = null): bool
    {
        return $this->expiresAt <= ($now ?? time());
    }
}
