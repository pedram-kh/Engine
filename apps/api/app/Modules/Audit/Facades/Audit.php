<?php

declare(strict_types=1);

namespace App\Modules\Audit\Facades;

use App\Modules\Audit\Services\AuditLogger;
use Illuminate\Support\Facades\Facade;

/**
 * Facade for {@see AuditLogger}.
 *
 * The facade resolves the same singleton that direct DI consumers receive,
 * so writes through `Audit::log(...)` and writes through an injected
 * `AuditLogger` are operationally identical (asserted by
 * `tests/Feature/Modules/Audit/AuditLoggerTest.php`).
 *
 * @method static \App\Modules\Audit\Models\AuditLog log(\App\Modules\Audit\Enums\AuditAction $action, ?\Illuminate\Contracts\Auth\Authenticatable $actor = null, ?\Illuminate\Database\Eloquent\Model $subject = null, ?string $reason = null, array $before = [], array $after = [], array $metadata = [], ?int $agencyId = null, ?string $actorType = null, ?string $actorRole = null, ?string $ip = null, ?string $userAgent = null)
 *
 * @see AuditLogger
 */
final class Audit extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AuditLogger::class;
    }
}
