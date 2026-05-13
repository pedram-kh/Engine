<?php

declare(strict_types=1);

namespace App\Modules\Brands\Models;

use App\Core\Concerns\HasUlid;
use App\Core\Tenancy\BelongsToAgency;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Brands\Database\Factories\BrandFactory;
use App\Modules\Brands\Enums\BrandStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $ulid
 * @property int $agency_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $industry
 * @property string|null $website_url
 * @property string|null $logo_path
 * @property string $default_currency
 * @property string $default_language
 * @property BrandStatus $status
 * @property array<string, mixed>|null $brand_safety_rules
 * @property int|null $exclusivity_window_days
 * @property bool $client_portal_enabled
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 */
final class Brand extends Model
{
    use BelongsToAgency;

    /** @use HasFactory<BrandFactory> */
    use HasFactory;
    use HasUlid;
    use SoftDeletes;

    /**
     * Eloquent-level defaults mirror the database column defaults so that
     * freshly-created model instances have the correct values before a
     * round-trip to the database.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
        'default_currency' => 'EUR',
        'default_language' => 'en',
        'client_portal_enabled' => false,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'agency_id',
        'name',
        'slug',
        'description',
        'industry',
        'website_url',
        'logo_path',
        'default_currency',
        'default_language',
        'status',
        'brand_safety_rules',
        'exclusivity_window_days',
        'client_portal_enabled',
    ];

    /**
     * @return BelongsTo<Agency, $this>
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function isArchived(): bool
    {
        return $this->status === BrandStatus::Archived;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BrandStatus::class,
            'brand_safety_rules' => 'array',
            'exclusivity_window_days' => 'integer',
            'client_portal_enabled' => 'boolean',
        ];
    }

    protected static function newFactory(): BrandFactory
    {
        return BrandFactory::new();
    }
}
