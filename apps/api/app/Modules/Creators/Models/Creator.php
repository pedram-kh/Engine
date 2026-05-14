<?php

declare(strict_types=1);

namespace App\Modules\Creators\Models;

use App\Core\Concerns\HasUlid;
use App\Modules\Audit\Concerns\Audited;
use App\Modules\Audit\Contracts\Auditable;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Enums\ApplicationStatus;
use App\Modules\Creators\Enums\KycStatus;
use App\Modules\Creators\Enums\VerificationLevel;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Global creator entity.
 *
 * Tenancy: Creator does NOT use BelongsToAgency. The agency-creator
 * relationship lives on agency_creator_relations. See
 * docs/security/tenancy.md and docs/03-DATA-MODEL.md §5.
 *
 * Audited per spec §20. The auditableAllowlist excludes free-text bio
 * and file paths; status / lifecycle / verification fields are included.
 *
 * @property int $id
 * @property string $ulid
 * @property int $user_id
 * @property string|null $display_name
 * @property string|null $bio
 * @property string|null $country_code
 * @property string|null $region
 * @property string|null $primary_language
 * @property array<int, string>|null $secondary_languages
 * @property string|null $avatar_path
 * @property string|null $cover_path
 * @property array<int, string>|null $categories
 * @property VerificationLevel $verification_level
 * @property string|null $tier
 * @property ApplicationStatus $application_status
 * @property Carbon|null $approved_at
 * @property int|null $approved_by_user_id
 * @property Carbon|null $rejected_at
 * @property string|null $rejection_reason
 * @property int $profile_completeness_score
 * @property Carbon|null $last_active_at
 * @property int|null $signed_master_contract_id
 * @property Carbon|null $click_through_accepted_at
 * @property KycStatus $kyc_status
 * @property Carbon|null $kyc_verified_at
 * @property bool $tax_profile_complete
 * @property bool $payout_method_set
 * @property Carbon|null $submitted_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 */
final class Creator extends Model implements Auditable
{
    use Audited;

    /** @use HasFactory<CreatorFactory> */
    use HasFactory;

    use HasUlid;
    use SoftDeletes;

    /**
     * Eloquent-level defaults mirror the database defaults so freshly-built
     * model instances have the correct enum values before the round-trip.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'verification_level' => 'unverified',
        'application_status' => 'incomplete',
        'kyc_status' => 'none',
        'profile_completeness_score' => 0,
        'tax_profile_complete' => false,
        'payout_method_set' => false,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'display_name',
        'bio',
        'country_code',
        'region',
        'primary_language',
        'secondary_languages',
        'avatar_path',
        'cover_path',
        'categories',
        'verification_level',
        'tier',
        'application_status',
        'approved_at',
        'approved_by_user_id',
        'rejected_at',
        'rejection_reason',
        'profile_completeness_score',
        'last_active_at',
        'signed_master_contract_id',
        'click_through_accepted_at',
        'kyc_status',
        'kyc_verified_at',
        'tax_profile_complete',
        'payout_method_set',
        'submitted_at',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<CreatorSocialAccount, $this>
     */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(CreatorSocialAccount::class);
    }

    /**
     * @return HasMany<CreatorPortfolioItem, $this>
     */
    public function portfolioItems(): HasMany
    {
        return $this->hasMany(CreatorPortfolioItem::class)->orderBy('position');
    }

    /**
     * @return HasMany<CreatorAvailabilityBlock, $this>
     */
    public function availabilityBlocks(): HasMany
    {
        return $this->hasMany(CreatorAvailabilityBlock::class);
    }

    /**
     * @return HasOne<CreatorTaxProfile, $this>
     */
    public function taxProfile(): HasOne
    {
        return $this->hasOne(CreatorTaxProfile::class);
    }

    /**
     * @return HasOne<CreatorPayoutMethod, $this>
     */
    public function payoutMethod(): HasOne
    {
        return $this->hasOne(CreatorPayoutMethod::class)->where('is_default', true);
    }

    /**
     * @return HasMany<CreatorPayoutMethod, $this>
     */
    public function payoutMethods(): HasMany
    {
        return $this->hasMany(CreatorPayoutMethod::class);
    }

    /**
     * @return HasMany<CreatorKycVerification, $this>
     */
    public function kycVerifications(): HasMany
    {
        return $this->hasMany(CreatorKycVerification::class);
    }

    /**
     * Allowlist of attributes that may appear in audit before/after
     * snapshots. Excludes:
     *   - bio                  (free-text; GDPR-sensitive)
     *   - secondary_languages  (free-form list)
     *   - avatar_path / cover_path (file paths, not state changes)
     *   - region               (free-text)
     *   - rejection_reason     (free-text — captured separately as audit reason)
     *
     * Asserted by tests/Feature/Modules/Creators/CreatorAuditTest.php.
     *
     * @return list<string>
     */
    public function auditableAllowlist(): array
    {
        return [
            'display_name',
            'country_code',
            'primary_language',
            'categories',
            'verification_level',
            'tier',
            'application_status',
            'approved_at',
            'approved_by_user_id',
            'rejected_at',
            'profile_completeness_score',
            'kyc_status',
            'kyc_verified_at',
            'tax_profile_complete',
            'payout_method_set',
            'submitted_at',
            'last_active_at',
            'deleted_at',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'secondary_languages' => 'array',
            'categories' => 'array',
            'verification_level' => VerificationLevel::class,
            'application_status' => ApplicationStatus::class,
            'kyc_status' => KycStatus::class,
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'kyc_verified_at' => 'datetime',
            'last_active_at' => 'datetime',
            'submitted_at' => 'datetime',
            'click_through_accepted_at' => 'datetime',
            'tax_profile_complete' => 'boolean',
            'payout_method_set' => 'boolean',
            'profile_completeness_score' => 'integer',
        ];
    }

    protected static function newFactory(): CreatorFactory
    {
        return CreatorFactory::new();
    }
}
