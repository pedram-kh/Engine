<?php

declare(strict_types=1);

namespace App\Core\Tenancy;

/**
 * Holds the current request's agency context.
 *
 * The container binds this as a singleton per request. The
 * `BelongsToAgencyScope` global scope reads from it; route-resolution
 * middleware (added in Sprint 2 when the first /api/v1/agencies/{agency}/*
 * routes ship) writes to it. Background jobs that need cross-tenant
 * access call `forget()` and use `Model::withoutGlobalScope(...)`
 * explicitly so the bypass is visible at the call site.
 *
 * See docs/00-MASTER-ARCHITECTURE.md §4 for the model.
 */
final class TenancyContext
{
    private ?int $currentAgencyId = null;

    public function setAgencyId(?int $agencyId): void
    {
        $this->currentAgencyId = $agencyId;
    }

    public function agencyId(): ?int
    {
        return $this->currentAgencyId;
    }

    public function hasAgency(): bool
    {
        return $this->currentAgencyId !== null;
    }

    public function forget(): void
    {
        $this->currentAgencyId = null;
    }

    /**
     * Run a closure with a temporary agency context, restoring whatever
     * was set before. Useful in tests and admin tooling.
     *
     * @template TReturn
     *
     * @param  \Closure(): TReturn  $callback
     * @return TReturn
     */
    public function runAs(?int $agencyId, \Closure $callback): mixed
    {
        $previous = $this->currentAgencyId;
        $this->currentAgencyId = $agencyId;

        try {
            return $callback();
        } finally {
            $this->currentAgencyId = $previous;
        }
    }
}
