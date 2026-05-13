<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Models;

use App\Core\Concerns\HasUlid;
use App\Core\Tenancy\BelongsToAgency;
use App\Modules\Agencies\Database\Factories\AgencyUserInvitationFactory;
use App\Modules\Agencies\Enums\AgencyRole;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $ulid
 * @property int $agency_id
 * @property string $email
 * @property AgencyRole $role
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property int|null $accepted_by_user_id
 * @property int $invited_by_user_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class AgencyUserInvitation extends Model
{
    use BelongsToAgency;

    /** @use HasFactory<AgencyUserInvitationFactory> */
    use HasFactory;

    use HasUlid;

    protected $table = 'agency_user_invitations';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'agency_id',
        'email',
        'role',
        'token_hash',
        'expires_at',
        'accepted_at',
        'accepted_by_user_id',
        'invited_by_user_id',
    ];

    /**
     * @return BelongsTo<Agency, $this>
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by_user_id');
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && ! $this->isExpired();
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => AgencyRole::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    protected static function newFactory(): AgencyUserInvitationFactory
    {
        return AgencyUserInvitationFactory::new();
    }
}
