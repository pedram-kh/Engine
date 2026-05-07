<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Models;

use App\Modules\Agencies\Database\Factories\AgencyMembershipFactory;
use App\Modules\Agencies\Enums\AgencyRole;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * The agency_users pivot, exposed as a first-class model because it
 * carries role, invited_by_user_id, invited_at, accepted_at.
 *
 * Naming: the table is `agency_users` (data-model spec), but the model
 * is `AgencyMembership` to read naturally as "this user has a membership
 * in this agency with role X" rather than "this user is an AgencyUser".
 *
 * @property int $id
 * @property int $agency_id
 * @property int $user_id
 * @property AgencyRole $role
 * @property int|null $invited_by_user_id
 * @property Carbon|null $invited_at
 * @property Carbon|null $accepted_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 */
final class AgencyMembership extends Pivot
{
    /** @use HasFactory<AgencyMembershipFactory> */
    use HasFactory;

    use SoftDeletes;

    public $incrementing = true;

    protected $table = 'agency_users';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'agency_id',
        'user_id',
        'role',
        'invited_by_user_id',
        'invited_at',
        'accepted_at',
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
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
            'invited_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    protected static function newFactory(): AgencyMembershipFactory
    {
        return AgencyMembershipFactory::new();
    }
}
