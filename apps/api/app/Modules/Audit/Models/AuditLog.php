<?php

declare(strict_types=1);

namespace App\Modules\Audit\Models;

use App\Core\Concerns\HasUlid;
use App\Modules\Audit\Database\Factories\AuditLogFactory;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Exceptions\AuditLogImmutableException;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Append-only privileged-action log row.
 *
 * @property int $id
 * @property string $ulid
 * @property int|null $agency_id
 * @property string $actor_type
 * @property int|null $actor_id
 * @property string|null $actor_role
 * @property AuditAction $action
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string|null $subject_ulid
 * @property string|null $reason
 * @property array<string, mixed>|null $metadata
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 * @property string|null $ip
 * @property string|null $user_agent
 * @property Carbon $created_at
 *
 * @see AuditLogImmutableException
 */
final class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory;

    use HasUlid;

    /**
     * Append-only contract: created_at is set on insert, never updated.
     */
    public $timestamps = false;

    protected $table = 'audit_logs';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'ulid',
        'agency_id',
        'actor_type',
        'actor_id',
        'actor_role',
        'action',
        'subject_type',
        'subject_id',
        'subject_ulid',
        'reason',
        'metadata',
        'before',
        'after',
        'ip',
        'user_agent',
        'created_at',
    ];

    /**
     * Authoritative actor when actor_type is 'user'. NULL when the action
     * was performed by the system (cron, queued job) or by a webhook.
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * Polymorphic subject of the action. May be null for system-wide events
     * such as feature-flag toggles that don't pin to a single subject row.
     *
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Append-only enforcement (application-layer half of §3.4).
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        throw AuditLogImmutableException::forUpdate();
    }

    /**
     * Append-only enforcement (application-layer half of §3.4).
     *
     * @return bool|null
     */
    public function delete()
    {
        throw AuditLogImmutableException::forDelete();
    }

    /**
     * Reject save() calls that would mutate an already-persisted row.
     * Insert (save() on a new model) is allowed; we explicitly delegate
     * to the parent's insert path.
     */
    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw AuditLogImmutableException::forUpdate();
        }

        return parent::save($options);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => AuditAction::class,
            'metadata' => 'array',
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function newFactory(): AuditLogFactory
    {
        return AuditLogFactory::new();
    }
}
