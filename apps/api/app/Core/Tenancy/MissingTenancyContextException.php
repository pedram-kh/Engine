<?php

declare(strict_types=1);

namespace App\Core\Tenancy;

use RuntimeException;

/**
 * Thrown when an HTTP request reaches a tenant-scoped route without an
 * active TenancyContext.
 *
 * Distinct from MissingAgencyContextException, which is thrown at the
 * Eloquent layer on writes; this one is thrown at the HTTP boundary on
 * reads (and writes) so the error surfaces with the actual URL that
 * triggered it.
 *
 * See docs/security/tenancy.md for the contract this enforces.
 */
final class MissingTenancyContextException extends RuntimeException
{
    public static function onRoute(string $path): self
    {
        return new self(sprintf(
            'Route [/%s] requires an active TenancyContext but none was set. '.
            'Either add the SetTenancyContext middleware before the `tenancy` '.
            'guard, or move this route into the explicit cross-tenant allowlist '.
            'documented in docs/security/tenancy.md.',
            ltrim($path, '/'),
        ));
    }
}
