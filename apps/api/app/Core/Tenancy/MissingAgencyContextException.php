<?php

declare(strict_types=1);

namespace App\Core\Tenancy;

use RuntimeException;

/**
 * Thrown when a tenant-scoped model is created without an `agency_id`.
 *
 * This is a programmer error — the trait catches it before the row is
 * persisted so a missing tenant context can never silently leak data
 * across agencies (docs/00-MASTER-ARCHITECTURE.md §4).
 */
final class MissingAgencyContextException extends RuntimeException
{
    public static function onCreate(string $modelClass): self
    {
        return new self(sprintf(
            'Cannot persist [%s]: no agency_id was set and no tenant context is active. '.
            'Either set agency_id explicitly, run inside TenancyContext::runAs(), or '.
            'remove the BelongsToAgency trait from this model.',
            $modelClass,
        ));
    }
}
