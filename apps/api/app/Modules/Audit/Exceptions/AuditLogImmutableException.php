<?php

declare(strict_types=1);

namespace App\Modules\Audit\Exceptions;

use App\Modules\Audit\Models\AuditLog;
use RuntimeException;

/**
 * Thrown when application code attempts to mutate or remove an existing
 * {@see AuditLog} row.
 *
 * The {@see AuditLog} model overrides
 * `update()`, `save()` for existing rows, and `delete()` to throw this
 * exception. This is the application-layer half of the append-only
 * contract documented in docs/05-SECURITY-COMPLIANCE.md §3.4; the other
 * half is the database role (INSERT + SELECT only) introduced in Phase 2.
 *
 * @see AuditLog
 */
final class AuditLogImmutableException extends RuntimeException
{
    public static function forUpdate(): self
    {
        return new self(
            'AuditLog rows are append-only and cannot be updated. '
            .'See docs/05-SECURITY-COMPLIANCE.md §3.4.',
        );
    }

    public static function forDelete(): self
    {
        return new self(
            'AuditLog rows are append-only and cannot be deleted from application code. '
            .'Retention is performed by the scheduled job under a privileged DB role. '
            .'See docs/05-SECURITY-COMPLIANCE.md §3.4 and §3.5.',
        );
    }
}
