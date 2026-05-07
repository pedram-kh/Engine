<?php

declare(strict_types=1);

namespace App\Modules\Audit\Database\Factories;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AuditLog>
 */
final class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ulid' => (string) Str::ulid(),
            'agency_id' => null,
            'actor_type' => 'system',
            'actor_id' => null,
            'actor_role' => null,
            'action' => AuditAction::AuthLoginSucceeded,
            'subject_type' => null,
            'subject_id' => null,
            'subject_ulid' => null,
            'reason' => null,
            'metadata' => null,
            'before' => null,
            'after' => null,
            'ip' => '127.0.0.1',
            'user_agent' => 'phpunit',
        ];
    }
}
