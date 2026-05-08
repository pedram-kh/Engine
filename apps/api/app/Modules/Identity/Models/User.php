<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Core\Concerns\HasUlid;
use App\Modules\Admin\Models\AdminProfile;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyMembership;
use App\Modules\Audit\Concerns\Audited;
use App\Modules\Audit\Contracts\Auditable;
use App\Modules\Identity\Database\Factories\UserFactory;
use App\Modules\Identity\Enums\ThemePreference;
use App\Modules\Identity\Enums\UserType;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $ulid
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property UserType $type
 * @property string $name
 * @property string $preferred_language
 * @property string $preferred_currency
 * @property string $timezone
 * @property ThemePreference $theme_preference
 * @property Carbon|null $last_login_at
 * @property string|null $last_login_ip
 * @property string|null $two_factor_secret
 * @property array<int, string>|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property Carbon|null $two_factor_enrollment_suspended_at
 * @property bool $mfa_required
 * @property bool $is_suspended
 * @property string|null $suspended_reason
 * @property Carbon|null $suspended_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 */
final class User extends Authenticatable implements Auditable, MustVerifyEmail
{
    use Audited;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasUlid;
    use Notifiable;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'email',
        'password',
        'type',
        'name',
        'preferred_language',
        'preferred_currency',
        'timezone',
        'theme_preference',
        'last_login_at',
        'last_login_ip',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
        'two_factor_enrollment_suspended_at',
        'mfa_required',
        'is_suspended',
        'suspended_reason',
        'suspended_at',
        'email_verified_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * Explicit allowlist of attributes that may appear in audit `before` /
     * `after` snapshots. Required by {@see Auditable}.
     *
     * Sensitive fields are excluded by absence:
     *   - password                              (never in audits)
     *   - two_factor_secret                     (never in audits)
     *   - two_factor_recovery_codes             (never in audits)
     *   - two_factor_confirmed_at               (never in audits)
     *   - two_factor_enrollment_suspended_at    (never in audits)
     *   - remember_token                        (never in audits)
     *
     * Asserted by tests/Feature/Modules/Audit/AuditedTraitTest.php and
     * tests/Feature/Modules/Identity/TwoFactorAuditTest.php.
     *
     * @return list<string>
     */
    public function auditableAllowlist(): array
    {
        return [
            'email',
            'email_verified_at',
            'type',
            'name',
            'preferred_language',
            'preferred_currency',
            'timezone',
            'theme_preference',
            'last_login_at',
            'last_login_ip',
            'mfa_required',
            'is_suspended',
            'suspended_reason',
            'suspended_at',
            'deleted_at',
        ];
    }

    /**
     * Memberships in agencies. A creator-typed user has zero memberships;
     * an agency_user-typed user has one or more (Phase 1 only ever has one).
     *
     * @return BelongsToMany<Agency, $this, AgencyMembership, 'pivot'>
     */
    public function agencies(): BelongsToMany
    {
        return $this->belongsToMany(Agency::class, 'agency_users')
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
     * Platform-admin satellite. Present iff users.type == 'platform_admin'.
     *
     * @return HasOne<AdminProfile, $this>
     */
    public function adminProfile(): HasOne
    {
        return $this->hasOne(AdminProfile::class);
    }

    public function isPlatformAdmin(): bool
    {
        return $this->type === UserType::PlatformAdmin;
    }

    public function isCreator(): bool
    {
        return $this->type === UserType::Creator;
    }

    public function isSuspended(): bool
    {
        return $this->is_suspended;
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    public function hasTwoFactorEnrollmentSuspended(): bool
    {
        return $this->two_factor_enrollment_suspended_at !== null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'type' => UserType::class,
            'theme_preference' => ThemePreference::class,
            'last_login_at' => 'datetime',
            'two_factor_secret' => 'encrypted',
            // Stored as JSON array of bcrypt hashes (one per recovery code),
            // then encrypted at rest as defense-in-depth. Plaintext codes are
            // shown to the user once at confirmation/regeneration and never
            // retrievable from the database. See chunk 5 priority #3.
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_enrollment_suspended_at' => 'datetime',
            'mfa_required' => 'boolean',
            'is_suspended' => 'boolean',
            'suspended_at' => 'datetime',
        ];
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
