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
     * The brand FLOOR (AH-053, D6) — what a brand must carry to be considered
     * complete. Every one of these is required at create, and an edit may not
     * leave any of them empty: the API refuses to leave a brand incomplete
     * after a write, whatever the caller.
     *
     * This is a deliberate escalation beyond the creator onboarding floor,
     * which is a page-edge FE block with a permissive API behind it. Brands
     * become creator-visible job cards in chunk 3, so a half-filled brand is a
     * broken public artefact rather than an internal inconvenience — hence a
     * server-side refusal, mirrored (not replaced) by the SPA.
     *
     * `logo_path` is in the floor but is NOT settable through the brand
     * create/update payload: it is written only by the logo upload endpoint
     * (D7). See {@see floorMissingFields()} for how create handles that.
     *
     * Mirrored FE-side by `BRAND_FLOOR_FIELDS` in
     * `apps/main/src/modules/brands/brandFloor.ts`, pinned by a
     * source-scanning parity spec.
     *
     * @var list<string>
     */
    public const array FLOOR_FIELDS = [
        'name',
        'slug',
        'description',
        'industry',
        'website_url',
        'logo_path',
    ];

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
     * The SINGLE floor predicate (AH-053, D6/A2) — consumed by
     * `UpdateBrandRequest` (the edit gate), `brands:audit-floor` (the
     * pre-deploy count) and, through the parity spec, the SPA mirror.
     *
     * Merged-state by design: `$overrides` carries the incoming payload, and
     * every field the payload does not mention is read from the stored row.
     * A PATCH is therefore judged on the state it PRODUCES, not on what it
     * happens to contain. The alternative — demanding the client echo all six
     * fields — is the shape that caused the AH-032 brief wipe: it forces
     * callers to re-send values they never saw.
     *
     * @param  array<string, mixed>  $overrides  the validated payload, if any
     * @return list<string> the floor fields that would still be empty
     */
    public function floorMissingFields(array $overrides = []): array
    {
        $missing = [];

        foreach (self::FLOOR_FIELDS as $field) {
            $value = array_key_exists($field, $overrides) ? $overrides[$field] : $this->{$field};

            if (! self::isFilled($value)) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /**
     * "Filled" means non-empty after trimming — a space bar does not satisfy
     * the floor. Agrees with the creator floor's `isFilled()` and with the
     * SPA mirror.
     */
    public static function isFilled(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
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
