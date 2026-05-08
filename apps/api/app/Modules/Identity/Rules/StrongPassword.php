<?php

declare(strict_types=1);

namespace App\Modules\Identity\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Enforces docs/05-SECURITY-COMPLIANCE.md §6.1 length policy:
 *
 *   - minimum 12 characters
 *   - maximum 128 characters (Argon2id input ceiling we accept)
 *   - no complexity rules (NIST guidance: length over complexity)
 *
 * Combine with {@see PasswordIsNotBreached} to add the breach check.
 *
 * Failure message is a stable, namespaced i18n key so the SPA can render
 * a translated, contextual error without parsing the English string.
 */
final class StrongPassword implements ValidationRule
{
    public const MIN_LENGTH = 12;

    public const MAX_LENGTH = 128;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail(trans('auth.password.invalid_type'));

            return;
        }

        $length = mb_strlen($value);

        if ($length < self::MIN_LENGTH) {
            $fail(trans('auth.password.too_short', ['min' => self::MIN_LENGTH]));

            return;
        }

        if ($length > self::MAX_LENGTH) {
            $fail(trans('auth.password.too_long', ['max' => self::MAX_LENGTH]));
        }
    }
}
