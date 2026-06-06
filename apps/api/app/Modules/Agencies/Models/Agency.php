<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Models;

use App\Core\Concerns\HasUlid;
use App\Modules\Agencies\Database\Factories\AgencyFactory;
use App\Modules\Agencies\Enums\AgencyRole;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Services\NotificationService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

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
     * The agency users who should receive agency-facing notifications
     * (S11.0 Chunk 2, D-5) — the admins + managers fan-out target that
     * replaces the single `invited_by_user_id` recipient. Staff are
     * EXCLUDED (the load-bearing exclusion); the inviter is one recipient
     * among the others, not specially flagged.
     *
     * Resolved through {@see self::memberships()} (a soft-delete-aware
     * `agency_users` Pivot, NOT BelongsToAgency — no tenant scope, so this
     * is safe to call from a queued listener without `runAs`, D-9). Deduped
     * by user id and returns hydrated {@see User} models ready for
     * {@see NotificationService::notify()}.
     *
     * @return Collection<int, User>
     */
    public function notifiableMembers(): Collection
    {
        return $this->memberships()
            ->whereIn('role', [
                AgencyRole::AgencyAdmin->value,
                AgencyRole::AgencyManager->value,
            ])
            ->with('user')
            ->get()
            ->map(static fn (AgencyMembership $membership): ?User => $membership->user)
            ->filter()
            ->unique(static fn (User $user): int => $user->getKey())
            ->values();
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
