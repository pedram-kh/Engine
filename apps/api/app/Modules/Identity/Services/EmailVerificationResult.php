<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

/**
 * Outcome of {@see EmailVerificationService::verify()}.
 *
 * - `Verified`           — token valid, user marked verified, event fired.
 * - `AlreadyVerified`    — token valid but user.email_verified_at was
 *                          already set; idempotent re-click.
 * - `InvalidToken`       — bad signature, malformed payload, mismatched
 *                          email_hash, or referenced user_id not found.
 *                          We do NOT distinguish "user not found" from
 *                          "wrong signature" to avoid leaking whether the
 *                          embedded user_id ever existed.
 * - `ExpiredToken`       — token decoded cleanly but expires_at <= now().
 */
enum EmailVerificationResult: string
{
    case Verified = 'verified';
    case AlreadyVerified = 'already_verified';
    case InvalidToken = 'invalid_token';
    case ExpiredToken = 'expired_token';
}
