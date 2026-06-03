<?php

declare(strict_types=1);

namespace App\Modules\TalentPools\Models;

use App\Modules\Agencies\Models\AgencyMembership;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use App\Modules\TalentPools\Database\Factories\TalentPoolMembershipFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * The talent_pool_creators pivot, exposed as a first-class model because it
 * carries `added_by_user_id` (who added this creator to the pool) — exactly
 * the rationale that makes {@see AgencyMembership}
 * a first-class pivot.
 *
 * House style (agency_users): surrogate auto-incrementing `id` PK plus a named
 * composite unique on (talent_pool_id, creator_id), NOT a composite PK.
 *
 * @property int $id
 * @property int $talent_pool_id
 * @property int $creator_id
 * @property int|null $added_by_user_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class TalentPoolMembership extends Pivot
{
    /** @use HasFactory<TalentPoolMembershipFactory> */
    use HasFactory;

    public $incrementing = true;

    protected $table = 'talent_pool_creators';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'talent_pool_id',
        'creator_id',
        'added_by_user_id',
    ];

    /**
     * @return BelongsTo<TalentPool, $this>
     */
    public function talentPool(): BelongsTo
    {
        return $this->belongsTo(TalentPool::class);
    }

    /**
     * @return BelongsTo<Creator, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Creator::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by_user_id');
    }

    protected static function newFactory(): TalentPoolMembershipFactory
    {
        return TalentPoolMembershipFactory::new();
    }
}
