<?php

declare(strict_types=1);

namespace App\Core\Impersonation;

use App\Core\Tenancy\TenancyContext;
use App\Modules\Audit\Services\AuditLogger;
use Closure;

/**
 * Per-request impersonation context (Sprint 13, D-9 / Q3).
 *
 * The exact mirror of {@see TenancyContext}: a
 * container singleton, written once per request by the impersonation
 * enforcement middleware, read by {@see AuditLogger}
 * to stamp every audit row with the impersonator behind the action.
 *
 * When an admin is impersonating a user, the `web`-guard actor IS the
 * impersonated user (the truthful actor of any action they take), and the
 * impersonator id recorded here is the platform_admin behind the keyboard —
 * so the dual-audit column `audit_logs.impersonator_user_id` is populated
 * automatically without any call site needing to know impersonation is in
 * play. Empty (null) for the overwhelming majority of requests.
 */
final class ImpersonationContext
{
    private ?int $impersonatorUserId = null;

    private ?string $sessionUlid = null;

    public function set(int $impersonatorUserId, string $sessionUlid): void
    {
        $this->impersonatorUserId = $impersonatorUserId;
        $this->sessionUlid = $sessionUlid;
    }

    public function impersonatorUserId(): ?int
    {
        return $this->impersonatorUserId;
    }

    public function sessionUlid(): ?string
    {
        return $this->sessionUlid;
    }

    public function isImpersonating(): bool
    {
        return $this->impersonatorUserId !== null;
    }

    public function forget(): void
    {
        $this->impersonatorUserId = null;
        $this->sessionUlid = null;
    }

    public function runAs(int $impersonatorUserId, string $sessionUlid, Closure $callback): mixed
    {
        $previousId = $this->impersonatorUserId;
        $previousUlid = $this->sessionUlid;
        $this->impersonatorUserId = $impersonatorUserId;
        $this->sessionUlid = $sessionUlid;

        try {
            return $callback();
        } finally {
            $this->impersonatorUserId = $previousId;
            $this->sessionUlid = $previousUlid;
        }
    }
}
