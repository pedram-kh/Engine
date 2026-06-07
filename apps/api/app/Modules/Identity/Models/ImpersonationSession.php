<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Core\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A single admin → user impersonation (Sprint 13, D-9).
 *
 * The server-authoritative record the enforcement middleware reads on every
 * impersonated request. `expires_at` is the TTL authority (Q2); `ended_at`
 * marks an explicit end; `token_hash` is the one-time hand-off bridging the
 * admin (`web_admin`) and main (`web`) SPAs across the two-cookie boundary.
 *
 * @property int $id
 * @property string $ulid
 * @property int $admin_user_id
 * @property int $impersonated_user_id
 * @property string $reason
 * @property string|null $token_hash
 * @property Carbon $expires_at
 * @property Carbon $started_at
 * @property Carbon|null $claimed_at
 * @property Carbon|null $ended_at
 * @property string|null $ip
 * @property Carbon $created_at
 */
final class ImpersonationSession extends Model
{
    use HasUlid;

    public $timestamps = false;

    protected $table = 'admin_impersonation_sessions';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'ulid',
        'admin_user_id',
        'impersonated_user_id',
        'reason',
        'token_hash',
        'expires_at',
        'started_at',
        'claimed_at',
        'ended_at',
        'ip',
        'created_at',
    ];

    /**
     * The impersonator (platform_admin).
     *
     * @return BelongsTo<User, $this>
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }

    /**
     * The impersonated user.
     *
     * @return BelongsTo<User, $this>
     */
    public function impersonatedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impersonated_user_id');
    }

    /**
     * Live = not ended AND not past its TTL. This is the single predicate
     * the enforcement middleware trusts; the frontend timer is advisory.
     */
    public function isActive(): bool
    {
        return $this->ended_at === null && $this->expires_at->isFuture();
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'started_at' => 'datetime',
            'claimed_at' => 'datetime',
            'ended_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
