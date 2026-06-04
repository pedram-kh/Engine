<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Models;

use App\Core\Concerns\HasUlid;
use App\Core\Tenancy\BelongsToAgency;
use App\Modules\Agencies\Database\Factories\BrandCreatorBlacklistFactory;
use App\Modules\Agencies\Enums\BlacklistType;
use App\Modules\Audit\Concerns\Audited;
use App\Modules\Audit\Contracts\Auditable;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Brands\Models\Brand;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A brand-scoped creator blacklist (Sprint 7, A1 / D-2). The SOLE source of
 * truth for brand-scoped blacklists: a row here keyed (brand_id, creator_id)
 * means the creator is blocked for THAT brand only, ok for the rest of the
 * agency. It does NOT touch the agency_creator_relations row (no dual-write,
 * D-2) — the brand → agency link derives through brands.agency_id.
 *
 * NOT tenant-scoped via {@see BelongsToAgency}: the table
 * carries no agency_id column (the spec keys it on brand_id). Tenant isolation
 * is enforced at the write surface instead — the route is agency-path-scoped,
 * and the brand is resolved through the agency-scoped {@see Brand} model (whose
 * BelongsToAgency global scope makes a cross-agency brand_id simply unresolvable
 * inside the calling agency's tenancy context).
 *
 * Audited per docs/03-DATA-MODEL.md §10.4. The allowlist EXCLUDES `reason`
 * (free-text, GDPR-sensitive — the same data class as the relation's
 * blacklist_reason / internal_notes, which are redacted by construction).
 *
 * Un-blacklist = soft-delete (D-3): the trait emits a brand_creator_blacklist.deleted
 * row and history is preserved.
 *
 * @property int $id
 * @property string $ulid
 * @property int $brand_id
 * @property int $creator_id
 * @property BlacklistType $blacklist_type
 * @property string $reason
 * @property Carbon $blacklisted_at
 * @property int|null $blacklisted_by_user_id
 * @property Carbon|null $notification_sent_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 */
final class BrandCreatorBlacklist extends Model implements Auditable
{
    use Audited;

    /** @use HasFactory<BrandCreatorBlacklistFactory> */
    use HasFactory;

    use HasUlid;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'brand_id',
        'creator_id',
        'blacklist_type',
        'reason',
        'blacklisted_at',
        'blacklisted_by_user_id',
        'notification_sent_at',
    ];

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return BelongsTo<Creator, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Creator::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function blacklistedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blacklisted_by_user_id');
    }

    /**
     * Allowlist EXCLUDES `reason` — the audit store never copies the
     * free-text justification (B4 privacy invariant; mirrors the relation's
     * blacklist_reason / internal_notes redaction).
     *
     * @return list<string>
     */
    public function auditableAllowlist(): array
    {
        return [
            'brand_id',
            'creator_id',
            'blacklist_type',
            'blacklisted_at',
            'blacklisted_by_user_id',
        ];
    }

    public function auditAction(string $event): AuditAction
    {
        return match ($event) {
            'created' => AuditAction::BrandCreatorBlacklistCreated,
            'deleted' => AuditAction::BrandCreatorBlacklistDeleted,
            default => AuditAction::BrandCreatorBlacklistCreated,
        };
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'blacklist_type' => BlacklistType::class,
            'blacklisted_at' => 'datetime',
            'notification_sent_at' => 'datetime',
        ];
    }

    protected static function newFactory(): BrandCreatorBlacklistFactory
    {
        return BrandCreatorBlacklistFactory::new();
    }
}
