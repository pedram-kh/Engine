<?php

declare(strict_types=1);

namespace App\Modules\Audit\Contracts;

use App\Modules\Audit\Concerns\Audited;
use App\Modules\Audit\Enums\AuditAction;

/**
 * Contract every model that opts into audit logging via the
 * {@see Audited} trait must satisfy.
 *
 * The interface exists so static analysis can see, in the trait's boot
 * closures, that the Eloquent model has the trait's auxiliary methods
 * available (PHPStan cannot resolve trait names as types). Models that
 * use the trait MUST also implement this interface.
 */
interface Auditable
{
    /**
     * Authoritative allowlist of attribute names that may appear in the
     * audit `before` / `after` snapshots. Anything not on this list is
     * silently dropped — that is how secrets stay out of audit rows.
     *
     * @return list<string>
     */
    public function auditableAllowlist(): array;

    /**
     * Filter an attribute bag down to the allowlist returned by
     * {@see auditableAllowlist()}.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function auditableSnapshot(array $attributes): array;

    /**
     * Resolve the {@see AuditAction} to record for the given Eloquent
     * event verb (`created`, `updated`, `deleted`).
     */
    public function auditAction(string $event): AuditAction;

    /**
     * Reason recorded against the next deletion event on this instance.
     */
    public function auditDeletionReason(): ?string;

    /**
     * Read and clear the pending reason set via {@see withAuditReason()}.
     */
    public function consumePendingAuditReason(): ?string;

    /**
     * Attach a reason to the next mutation event on this instance.
     * Required for any action whose
     * {@see AuditAction::requiresReason()} returns true.
     */
    public function withAuditReason(?string $reason): static;
}
