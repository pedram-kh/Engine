<?php

declare(strict_types=1);

namespace App\Modules\Identity\Exceptions;

use RuntimeException;

/**
 * Domain failures in the impersonation lifecycle (Sprint 13, D-9).
 *
 * Each carries the canonical error `code` + HTTP `status` so the controller
 * surfaces them through the standard ErrorResponse envelope without
 * hand-rolling. The no-escalation refusals (admin target, self target) are
 * 422; an invalid/expired/consumed hand-off is 403 (the token is the bearer
 * credential and it did not authorize).
 */
final class ImpersonationException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function cannotImpersonateAdmin(): self
    {
        return new self(
            'admin.impersonation.target_admin',
            422,
            'A platform admin cannot be impersonated.',
        );
    }

    public static function cannotImpersonateSelf(): self
    {
        return new self(
            'admin.impersonation.target_self',
            422,
            'You cannot impersonate yourself.',
        );
    }

    public static function invalidHandoff(): self
    {
        return new self(
            'admin.impersonation.invalid_handoff',
            403,
            'This impersonation hand-off is invalid, already used, or expired.',
        );
    }

    public static function alreadyImpersonating(): self
    {
        return new self(
            'admin.impersonation.already_active',
            409,
            'You already have an active impersonation. End it before starting another.',
        );
    }
}
