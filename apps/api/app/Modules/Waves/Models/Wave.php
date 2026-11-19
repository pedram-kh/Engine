<?php

declare(strict_types=1);

namespace App\Modules\Waves\Models;

use App\Core\Concerns\HasUlid;
use App\Core\Tenancy\BelongsToAgency;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Identity\Models\User;
use App\Modules\Waves\Database\Factories\WaveFactory;
use App\Modules\Waves\Enums\WaveStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One wave of a brand (PL-1.1). The campaign link is optional; one live wave per campaign is held by the
 * partial unique index `unique_waves_campaign_id`, and the same-brand rule by WaveService.
 *
 * @property int $id
 * @property string $ulid
 * @property int $agency_id
 * @property int $brand_id
 * @property int|null $campaign_id
 * @property string $name
 * @property Carbon $go_live_on
 * @property list<string> $markets
 * @property WaveStatus $status
 * @property int $created_by_user_id
 * @property Carbon|null $steps_provisioned_at
 * @property string|null $usp_audience_title
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Brand $brand
 * @property-read Campaign|null $campaign
 * @property-read User $createdBy
 */
final class Wave extends Model
{
    use BelongsToAgency;

    /** @use HasFactory<WaveFactory> */
    use HasFactory;

    use HasUlid;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'agency_id',
        'brand_id',
        'campaign_id',
        'name',
        'go_live_on',
        'markets',
        'status',
        'created_by_user_id',
    ];

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return HasMany<WaveStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(WaveStep::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'go_live_on' => 'date:Y-m-d',
            'markets' => 'array',
            'status' => WaveStatus::class,
            'steps_provisioned_at' => 'datetime',
        ];
    }

    protected static function newFactory(): WaveFactory
    {
        return WaveFactory::new();
    }
}
