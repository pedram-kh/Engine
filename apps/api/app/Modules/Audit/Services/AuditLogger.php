<?php

declare(strict_types=1);

namespace App\Modules\Audit\Services;

use App\Core\Tenancy\TenancyContext;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Exceptions\MissingAuditReasonException;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Audit\Models\AuditLog;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Single entry point for writing rows to `audit_logs`.
 *
 * Both direct DI consumption and the {@see Audit}
 * facade resolve the same singleton from the container, so writes through
 * either path produce equivalent rows. See AuditServiceProvider.
 *
 * Auto-derived defaults (when not explicitly passed):
 *   - actor / actor_id : derived from auth()->user() if any, else null.
 *   - actor_type       : 'user' when an actor is resolved; otherwise the
 *                        sentinel 'system'.
 *   - actor_role       : null when actor_type='user' (callers pass the
 *                        snapshot); the sentinel 'system' when
 *                        actor_type='system' (so admin queries can
 *                        filter system-initiated rows on either column).
 *   - agency_id        : derived from {@see TenancyContext}.
 *   - ip / user_agent  : derived from the current Request via the
 *                        framework helper. Always returns null in
 *                        non-HTTP contexts (artisan, queued jobs, the
 *                        Sprint1IdentitySeeder, anywhere request() is
 *                        bound to an empty/synthetic Request without
 *                        REMOTE_ADDR or User-Agent headers).
 *
 * Non-HTTP contract (verified by AuditLoggerTest):
 *   When called from a seeder, artisan command, or queued job with no
 *   real HTTP request and no overrides, the row is still written and
 *   contains: actor_id=null, actor_type='system', actor_role='system',
 *   ip=null, user_agent=null.
 */
final class AuditLogger
{
    public function __construct(
        private readonly AuthFactory $auth,
        private readonly TenancyContext $tenancyContext,
    ) {}

    /**
     * Write a single audit row and return the persisted model.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $metadata
     *
     * @throws MissingAuditReasonException When the action requires a reason
     *                                     and one is not supplied or is blank.
     */
    public function log(
        AuditAction $action,
        ?Authenticatable $actor = null,
        ?Model $subject = null,
        ?string $reason = null,
        array $before = [],
        array $after = [],
        array $metadata = [],
        ?int $agencyId = null,
        ?string $actorType = null,
        ?string $actorRole = null,
        ?string $ip = null,
        ?string $userAgent = null,
    ): AuditLog {
        $reason = $reason !== null ? trim($reason) : null;
        if ($reason === '') {
            $reason = null;
        }

        if ($action->requiresReason() && $reason === null) {
            throw MissingAuditReasonException::forAction($action);
        }

        $resolvedActor = $actor ?? $this->resolveCurrentActor();
        $resolvedActorType = $actorType ?? ($resolvedActor !== null ? 'user' : 'system');
        $resolvedActorRole = $actorRole ?? ($resolvedActorType === 'system' ? 'system' : null);

        return AuditLog::query()->create([
            'agency_id' => $agencyId ?? $this->resolveAgencyId(),
            'actor_type' => $resolvedActorType,
            'actor_id' => $this->extractActorId($resolvedActor),
            'actor_role' => $resolvedActorRole,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'subject_ulid' => $this->extractSubjectUlid($subject),
            'reason' => $reason,
            'metadata' => $metadata === [] ? null : $metadata,
            'before' => $before === [] ? null : $before,
            'after' => $after === [] ? null : $after,
            'ip' => $ip ?? $this->resolveIp(),
            'user_agent' => $userAgent ?? $this->resolveUserAgent(),
        ]);
    }

    private function resolveCurrentActor(): ?Authenticatable
    {
        return $this->auth->guard()->user();
    }

    private function extractActorId(?Authenticatable $actor): ?int
    {
        if ($actor === null) {
            return null;
        }

        $key = $actor->getAuthIdentifier();

        return is_int($key) ? $key : (is_numeric($key) ? (int) $key : null);
    }

    private function extractSubjectUlid(?Model $subject): ?string
    {
        if ($subject === null) {
            return null;
        }

        $ulid = $subject->getAttribute('ulid');

        return is_string($ulid) && $ulid !== '' ? $ulid : null;
    }

    private function resolveAgencyId(): ?int
    {
        return $this->tenancyContext->hasAgency() ? $this->tenancyContext->agencyId() : null;
    }

    private function resolveIp(): ?string
    {
        return request()->ip();
    }

    private function resolveUserAgent(): ?string
    {
        return request()->userAgent();
    }
}
