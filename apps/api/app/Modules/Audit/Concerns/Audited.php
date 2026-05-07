<?php

declare(strict_types=1);

namespace App\Modules\Audit\Concerns;

use App\Modules\Audit\Contracts\Auditable;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Auto-emits audit rows for the model's create / update / delete events.
 *
 * Implementing models MUST also implement {@see Auditable}; the interface
 * exists so PHPStan can see the trait-provided methods on the model
 * inside the boot closures.
 *
 * The model declares its own {@see Auditable::auditableAllowlist()} (the
 * trait keeps it abstract). Only attributes named in that list ever
 * appear in `before` / `after` snapshots — sensitive fields (passwords,
 * 2FA secrets, remember tokens, etc.) are excluded by virtue of being
 * absent from the allowlist.
 *
 * Action naming convention: `<class-basename-lowercase>.<event>`,
 * e.g. `User` + `updated` ⇒ `user.updated`. Models that need different
 * action names should override {@see Auditable::auditAction()}.
 *
 * Update events emit a row only if at least one allowlisted attribute
 * actually changed; pure no-op saves and changes that touch only
 * non-allowlisted attributes (e.g. a password change) do not produce
 * `*.updated` rows.
 */
trait Audited
{
    /**
     * Per-instance reason consumed by the next audit-emitting event on this
     * model. Cleared after each emission. Set via {@see withAuditReason()};
     * read by {@see auditDeletionReason()} and {@see consumePendingAuditReason()}.
     */
    private ?string $pendingAuditReason = null;

    public function withAuditReason(?string $reason): static
    {
        $trimmed = $reason !== null ? trim($reason) : null;
        $this->pendingAuditReason = $trimmed === '' ? null : $trimmed;

        return $this;
    }

    public static function bootAudited(): void
    {
        static::created(function (Model&Auditable $model): void {
            $after = $model->auditableSnapshot($model->getAttributes());

            self::auditLogger()->log(
                action: $model->auditAction('created'),
                subject: $model,
                reason: $model->consumePendingAuditReason(),
                after: $after,
            );
        });

        static::updated(function (Model&Auditable $model): void {
            $changed = $model->getChanges();
            $allowlistedChanges = $model->auditableSnapshot($changed);

            if ($allowlistedChanges === []) {
                return;
            }

            $original = [];
            foreach (array_keys($allowlistedChanges) as $attribute) {
                $original[$attribute] = $model->getOriginal($attribute);
            }

            self::auditLogger()->log(
                action: $model->auditAction('updated'),
                subject: $model,
                reason: $model->consumePendingAuditReason(),
                before: $original,
                after: $allowlistedChanges,
            );
        });

        static::deleted(function (Model&Auditable $model): void {
            $before = $model->auditableSnapshot($model->getOriginal());

            self::auditLogger()->log(
                action: $model->auditAction('deleted'),
                subject: $model,
                reason: $model->auditDeletionReason(),
                before: $before,
            );
        });
    }

    public function auditAction(string $event): AuditAction
    {
        return AuditAction::from(Str::lower(class_basename($this)).'.'.$event);
    }

    public function auditDeletionReason(): ?string
    {
        return $this->consumePendingAuditReason();
    }

    public function consumePendingAuditReason(): ?string
    {
        $reason = $this->pendingAuditReason;
        $this->pendingAuditReason = null;

        return $reason;
    }

    public function auditableSnapshot(array $attributes): array
    {
        return array_intersect_key(
            $attributes,
            array_flip($this->auditableAllowlist()),
        );
    }

    /**
     * Concrete classes provide the allowlist. Required by {@see Auditable}.
     *
     * @return list<string>
     */
    abstract public function auditableAllowlist(): array;

    private static function auditLogger(): AuditLogger
    {
        return app(AuditLogger::class);
    }
}
