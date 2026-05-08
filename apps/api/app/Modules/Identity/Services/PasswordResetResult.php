<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

/**
 * Outcome of {@see PasswordResetService::complete()}.
 *
 *   - Completed   → password updated, sessions invalidated, audit emitted.
 *   - InvalidToken → token unknown, expired, or email mismatch. Controllers
 *                    should respond with 400 + `auth.password.invalid_token`.
 */
enum PasswordResetResult: string
{
    case Completed = 'completed';
    case InvalidToken = 'invalid_token';
}
