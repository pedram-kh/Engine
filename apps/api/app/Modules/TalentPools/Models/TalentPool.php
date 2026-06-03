<?php

declare(strict_types=1);

namespace App\Modules\TalentPools\Models;

use App\Core\Concerns\HasUlid;
use App\Core\Tenancy\BelongsToAgency;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Brands\Models\Brand;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use App\Modules\TalentPools\Database\Factories\TalentPoolFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A saved, per-agency (optionally per-brand) collection of creators
 * (Sprint 6 Chunk 2b). Mirrors the Brand entity's tenancy + soft-delete
 * shape; membership is carried on the talent_pool_creators pivot via the
 * {@see TalentPoolMembership} first-class pivot model.
 *
 * @property int $id
 * @property string $ulid
 * @property int $agency_id
 * @property int|null $brand_id
 * @property string $name
 * @property string|null $description
 * @property int|null $created_by_user_id
 * @property int|null $creators_count
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 */
final class TalentPool extends Model
{
    use BelongsToAgency;

    /** @use HasFactory<TalentPoolFactory> */
    use HasFactory;

    use HasUlid;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'agency_id',
        'brand_id',
        'name',
        'description',
        'created_by_user_id',
    ];

    /**
     * @return BelongsTo<Agency, $this>
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * The pool's creator members. Mirrors {@see Agency::members()}: a
     * belongsToMany through the first-class pivot model, exposing the
     * `added_by_user_id` pivot column + timestamps.
     *
     * @return BelongsToMany<Creator, $this, TalentPoolMembership, 'pivot'>
     */
    public function creators(): BelongsToMany
    {
        return $this->belongsToMany(Creator::class, 'talent_pool_creators')
            ->using(TalentPoolMembership::class)
            ->withPivot(['added_by_user_id'])
            ->withTimestamps();
    }

    protected static function newFactory(): TalentPoolFactory
    {
        return TalentPoolFactory::new();
    }
}
