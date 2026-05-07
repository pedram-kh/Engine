<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Models;

use App\Core\Concerns\HasUlid;
use App\Modules\Agencies\Database\Factories\AgencyFactory;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $ulid
 * @property string $name
 * @property string $slug
 * @property string $country_code
 * @property string $default_currency
 * @property string $default_language
 * @property string|null $logo_path
 * @property string|null $primary_color
 * @property string $subscription_tier
 * @property string $subscription_status
 * @property string|null $billing_email
 * @property string|null $tax_id
 * @property string|null $tax_id_country
 * @property array<string, mixed>|null $address
 * @property array<string, mixed> $settings
 * @property bool $is_active
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 */
final class Agency extends Model
{
    /** @use HasFactory<AgencyFactory> */
    use HasFactory;

    use HasUlid;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'country_code',
        'default_currency',
        'default_language',
        'logo_path',
        'primary_color',
        'subscription_tier',
        'subscription_status',
        'billing_email',
        'tax_id',
        'tax_id_country',
        'address',
        'settings',
        'is_active',
    ];

    /**
     * @return BelongsToMany<User, $this, AgencyMembership, 'pivot'>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'agency_users')
            ->using(AgencyMembership::class)
            ->withPivot([
                'role',
                'invited_by_user_id',
                'invited_at',
                'accepted_at',
            ])
            ->withTimestamps();
    }

    /**
     * @return HasMany<AgencyMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(AgencyMembership::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'address' => 'array',
            'settings' => 'array',
            'is_active' => 'boolean',
        ];
    }

    protected static function newFactory(): AgencyFactory
    {
        return AgencyFactory::new();
    }
}
