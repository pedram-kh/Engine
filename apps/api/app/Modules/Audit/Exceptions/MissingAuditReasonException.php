<?php

declare(strict_types=1);

namespace App\Modules\Audit\Exceptions;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use RuntimeException;

/**
 * Thrown by {@see AuditLogger::log()} when a
 * destructive or otherwise reason-mandatory action is logged without a
 * non-empty reason, per docs/05-SECURITY-COMPLIANCE.md §3.3.
 *
 * The HTTP layer's `action.reason` middleware short-circuits requests
 * before they reach the service layer; this exception catches anyone
 * who tries to call the service directly without supplying a reason
 * (e.g. a queued job).
 */
final class MissingAuditReasonException extends RuntimeException
{
    public static function forAction(AuditAction $action): self
    {
        return new self(sprintf(
            'Audit action "%s" requires a non-empty reason. '
            .'See docs/05-SECURITY-COMPLIANCE.md §3.3.',
            $action->value,
        ));
    }
}
