<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Models;

use App\Core\Concerns\HasUlid;
use App\Core\Tenancy\BelongsToAgency;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Database\Factories\CampaignFactory;
use App\Modules\Campaigns\Enums\CampaignObjective;
use App\Modules\Campaigns\Enums\CampaignStatus;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A campaign — a first-class Phase 1 entity owned by an agency and a brand
 * (Sprint 8 Chunk 1, D-1). See docs/03-DATA-MODEL.md §7.
 *
 * Not Audited via the trait: `campaign.created` / `campaign.updated` are
 * logged MANUALLY by CampaignController (mirroring the Brand precedent),
 * because the free-text `brief` blob must never land in an audit snapshot.
 *
 * @property int $id
 * @property string $ulid
 * @property int $agency_id
 * @property int $brand_id
 * @property string $name
 * @property string|null $description
 * @property CampaignObjective $objective
 * @property CampaignStatus $status
 * @property int|null $budget_minor_units
 * @property string|null $budget_currency
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property Carbon|null $posting_window_starts_at
 * @property Carbon|null $posting_window_ends_at
 * @property array<string, mixed>|null $brief
 * @property int|null $target_creator_count
 * @property int $created_by_user_id
 * @property Carbon|null $published_at
 * @property Carbon|null $completed_at
 * @property bool $is_marketplace_visible
 * @property Carbon|null $marketplace_open_at
 * @property Carbon|null $marketplace_close_at
 * @property bool $requires_per_campaign_contract
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 */
final class Campaign extends Model
{
    use BelongsToAgency;

    /** @use HasFactory<CampaignFactory> */
    use HasFactory;

    use HasUlid;
    use SoftDeletes;

    protected $attributes = [
        'status' => 'draft',
        'is_marketplace_visible' => false,
        'requires_per_campaign_contract' => false,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'agency_id',
        'brand_id',
        'name',
        'description',
        'objective',
        'status',
        'budget_minor_units',
        'budget_currency',
        'starts_at',
        'ends_at',
        'posting_window_starts_at',
        'posting_window_ends_at',
        'brief',
        'target_creator_count',
        'created_by_user_id',
        'published_at',
        'completed_at',
        'is_marketplace_visible',
        'marketplace_open_at',
        'marketplace_close_at',
        'requires_per_campaign_contract',
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
     * @return HasMany<CampaignAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(CampaignAssignment::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'objective' => CampaignObjective::class,
            'status' => CampaignStatus::class,
            'budget_minor_units' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'posting_window_starts_at' => 'datetime',
            'posting_window_ends_at' => 'datetime',
            'brief' => 'array',
            'target_creator_count' => 'integer',
            'published_at' => 'datetime',
            'completed_at' => 'datetime',
            'is_marketplace_visible' => 'boolean',
            'marketplace_open_at' => 'datetime',
            'marketplace_close_at' => 'datetime',
            'requires_per_campaign_contract' => 'boolean',
        ];
    }

    protected static function newFactory(): CampaignFactory
    {
        return CampaignFactory::new();
    }
}
