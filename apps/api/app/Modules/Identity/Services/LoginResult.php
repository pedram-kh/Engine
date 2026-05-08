<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Models\User;

/**
 * Discriminated result of {@see AuthService::login()}.
 *
 * One of these states applies on every login attempt; controllers map
 * directly onto the documented error envelopes from
 * docs/04-API-DESIGN.md §8 + §4 without inspecting any other state.
 *
 *   - Success            → user authenticated, session attached. The
 *                          User instance is populated for downstream
 *                          response serialization.
 *   - InvalidCredentials → wrong email or wrong password.
 *                          `auth.invalid_credentials`. 401.
 *   - MfaRequired        → password OK, but the user has 2FA confirmed.
 *                          The Sprint-1-chunk-3 codepath stops here and
 *                          returns 401 with `auth.mfa_required`. The
 *                          actual challenge endpoint lands in chunk 5.
 *   - AccountSuspended   → user is hard-suspended (is_suspended=true).
 *                          `auth.account_locked`. 423.
 *   - TemporarilyLocked  → too many failures in the 15-minute window.
 *                          `auth.account_locked.temporary`. 423.
 */
final readonly class LoginResult
{
    private function __construct(
        public LoginResultStatus $status,
        public ?User $user = null,
        public ?int $retryAfterSeconds = null,
    ) {}

    public static function success(User $user): self
    {
        return new self(LoginResultStatus::Success, user: $user);
    }

    public static function invalidCredentials(): self
    {
        return new self(LoginResultStatus::InvalidCredentials);
    }

    public static function mfaRequired(User $user): self
    {
        return new self(LoginResultStatus::MfaRequired, user: $user);
    }

    public static function accountSuspended(User $user): self
    {
        return new self(LoginResultStatus::AccountSuspended, user: $user);
    }

    public static function temporarilyLocked(int $retryAfterSeconds): self
    {
        return new self(LoginResultStatus::TemporarilyLocked, retryAfterSeconds: $retryAfterSeconds);
    }
}
