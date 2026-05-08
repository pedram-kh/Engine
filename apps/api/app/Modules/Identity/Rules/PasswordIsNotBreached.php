<?php

declare(strict_types=1);

namespace App\Modules\Identity\Rules;

use App\Modules\Identity\Contracts\PwnedPasswordsClientContract;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validation rule that fails if the password appears in the
 * HaveIBeenPwned breach dataset. Uses
 * {@see PwnedPasswordsClientContract} (resolved from the container) so
 * tests can swap in an in-memory fake without touching the network.
 *
 * Reference: docs/05-SECURITY-COMPLIANCE.md §6.1 (passwords are
 * breach-checked at signup AND password change AND password reset).
 *
 * The rule does not enforce any length / shape rules — pair it with
 * {@see StrongPassword}.
 */
final class PasswordIsNotBreached implements ValidationRule
{
    public function __construct(private readonly PwnedPasswordsClientContract $client) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        $count = $this->client->breachCount($value);

        if ($count > 0) {
            $fail(trans('auth.password.breached', ['count' => $count]));
        }
    }
}
