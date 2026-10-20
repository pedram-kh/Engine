<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Core\Concerns\HasUlid;
use App\Modules\Admin\Models\AdminProfile;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyMembership;
use App\Modules\Audit\Concerns\Audited;
use App\Modules\Audit\Contracts\Auditable;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Database\Factories\UserFactory;
use App\Modules\Identity\Enums\ThemePreference;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Services\AuthService;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $ulid
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property UserType $type
 * @property string $name
 * @property string|null $last_name
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
 * @property Carbon|null $mfa_exempted_at
 * @property string|null $mfa_exemption_reason
 * @property bool $is_suspended
 * @property string|null $suspended_reason
 * @property Carbon|null $suspended_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 * @property Carbon|null $erased_at AH-104a: set by the erasure engine only; the login block
 */
final class User extends Authenticatable implements Auditable, MustVerifyEmail
{
    use Audited;

    // AH-099 — personal access tokens for the creator app (bearer auth).
    // The trait is inert for every cookie/session request: it adds the
    // `tokens()` relation + `createToken()` and nothing reads it unless a
    // request authenticates through the `creator_app` guard.
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasUlid;
    use SoftDeletes;

    // Laravel's Notifiable trait was removed in S11.0 Chunk 1 (D-11) under the
    // reasoned-removal discipline (PROJECT-WORKFLOW.md §5.8). It was scaffold
    // dead code — the app has never used Laravel's notification channels (no
    // app/Notifications, zero ->notify() calls). The custom notification
    // subsystem (App\Modules\Notifications) supersedes it: emit via
    // NotificationService, store in the `notifications` table. Re-adding
    // Notifiable would be an intentional decision to adopt Laravel channels.

    /**
     * @var list<string>
     */
    protected $fillable = [
        'email',
        'password',
        'type',
        'name',
        'last_name',
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
        'mfa_exempted_at',
        'mfa_exemption_reason',
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
     *   - mfa_exemption_reason                  (never in audits — AH-087)
     *
     * `mfa_exemption_reason` is free-text an admin typed about a named
     * person. It is excluded under the same redaction discipline as the
     * other operator-authored reasons: the reason reaches the trail
     * through the audit row's own `reason` field (the verbs
     * user.mfa_exempted / user.mfa_exemption_removed both
     * requiresReason()), so allowlisting the column would duplicate it
     * into every unrelated `user.updated` snapshot for the rest of that
     * user's life. `mfa_exempted_at` IS allowlisted — the state is not
     * sensitive, and seeing it move in a snapshot is the point.
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
            'last_name',
            'preferred_language',
            'preferred_currency',
            'timezone',
            'theme_preference',
            'last_login_at',
            'last_login_ip',
            'mfa_required',
            'mfa_exempted_at',
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

    /**
     * Creator satellite. Present iff users.type == 'creator' AND
     * CreatorBootstrapService has run (post-Sprint-3 sign-up always
     * creates the row in the same transaction). Returns null for
     * agency_user / platform_admin types.
     *
     * @return HasOne<Creator, $this>
     */
    public function creator(): HasOne
    {
        return $this->hasOne(Creator::class);
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

    /**
     * AH-104a: true once the erasure engine has anonymised this account. The
     * marker is the login block ({@see AuthService}) and the exclusion predicate
     * for fan-outs; it is never set by anything but the engine.
     */
    public function isErased(): bool
    {
        return $this->erased_at !== null;
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
     * True when a platform admin has exempted this user from the 2FA
     * enrollment requirement (AH-087).
     *
     * The exemption removes the REQUIREMENT to enroll, never the
     * enrollment itself: an exempt user who is also enrolled keeps their
     * authenticator and is still challenged at sign-in, because the login
     * gate reads {@see hasTwoFactorEnabled()} and knows nothing about
     * this column. Platform admins are never exemptable — the admin
     * guard's server-side gate (EnsureMfaForAdmins) does not consult
     * this method, and the exemption endpoint refuses admin targets.
     */
    public function isMfaExempt(): bool
    {
        return $this->mfa_exempted_at !== null;
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
            'mfa_exempted_at' => 'datetime',
            'is_suspended' => 'boolean',
            'suspended_at' => 'datetime',
            'erased_at' => 'datetime',
        ];
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
