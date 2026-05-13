<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Models;

use App\Core\Concerns\HasUlid;
use App\Core\Tenancy\BelongsToAgency;
use App\Modules\Agencies\Database\Factories\AgencyCreatorRelationFactory;
use App\Modules\Audit\Concerns\Audited;
use App\Modules\Audit\Contracts\Auditable;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Creators\Enums\RelationshipStatus;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Per-agency view of a Creator. Composite tenant scope: BelongsToAgency
 * on agency_id.
 *
 * Q1 (b-mod) lifecycle on prospect-to-roster transition:
 *   - relationship_status: 'prospect' → 'roster'
 *   - invitation_token_hash: <hash> → null (defense-in-depth)
 *   - invitation_expires_at, invitation_sent_at, invited_by_user_id:
 *     RETAINED as historical record.
 *
 * Audited per spec §20. Allowlist excludes the encrypted/sensitive
 * blacklist_reason and internal_notes (free text, GDPR-sensitive).
 *
 * @property int $id
 * @property string $ulid
 * @property int $agency_id
 * @property int $creator_id
 * @property RelationshipStatus $relationship_status
 * @property bool $is_blacklisted
 * @property string|null $blacklist_scope
 * @property string|null $blacklist_reason
 * @property string|null $blacklist_type
 * @property Carbon|null $blacklisted_at
 * @property int|null $blacklisted_by_user_id
 * @property Carbon|null $notification_sent_at
 * @property string|null $appeal_status
 * @property Carbon|null $appeal_submitted_at
 * @property int|null $internal_rating
 * @property string|null $internal_notes
 * @property int $total_campaigns_completed
 * @property int $total_paid_minor_units
 * @property Carbon|null $last_engaged_at
 * @property string|null $invitation_token_hash
 * @property Carbon|null $invitation_expires_at
 * @property Carbon|null $invitation_sent_at
 * @property int|null $invited_by_user_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class AgencyCreatorRelation extends Model implements Auditable
{
    use Audited;
    use BelongsToAgency;

    /** @use HasFactory<AgencyCreatorRelationFactory> */
    use HasFactory;

    use HasUlid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'agency_id',
        'creator_id',
        'relationship_status',
        'is_blacklisted',
        'blacklist_scope',
        'blacklist_reason',
        'blacklist_type',
        'blacklisted_at',
        'blacklisted_by_user_id',
        'notification_sent_at',
        'appeal_status',
        'appeal_submitted_at',
        'internal_rating',
        'internal_notes',
        'total_campaigns_completed',
        'total_paid_minor_units',
        'last_engaged_at',
        'invitation_token_hash',
        'invitation_expires_at',
        'invitation_sent_at',
        'invited_by_user_id',
    ];

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
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function blacklistedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blacklisted_by_user_id');
    }

    public function isProspect(): bool
    {
        return $this->relationship_status === RelationshipStatus::Prospect;
    }

    public function isInvitationExpired(): bool
    {
        return $this->invitation_expires_at?->isPast() ?? false;
    }

    /**
     * @return list<string>
     */
    public function auditableAllowlist(): array
    {
        return [
            'agency_id',
            'creator_id',
            'relationship_status',
            'is_blacklisted',
            'blacklist_scope',
            'blacklist_type',
            'blacklisted_at',
            'blacklisted_by_user_id',
            'invitation_sent_at',
            'invitation_expires_at',
            'invited_by_user_id',
            'internal_rating',
            'last_engaged_at',
        ];
    }

    public function auditAction(string $event): AuditAction
    {
        return match ($event) {
            'created' => AuditAction::AgencyCreatorRelationCreated,
            'updated' => AuditAction::AgencyCreatorRelationUpdated,
            'deleted' => AuditAction::AgencyCreatorRelationDeleted,
            default => AuditAction::AgencyCreatorRelationUpdated,
        };
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'relationship_status' => RelationshipStatus::class,
            'is_blacklisted' => 'boolean',
            'blacklisted_at' => 'datetime',
            'notification_sent_at' => 'datetime',
            'appeal_submitted_at' => 'datetime',
            'last_engaged_at' => 'datetime',
            'invitation_expires_at' => 'datetime',
            'invitation_sent_at' => 'datetime',
            'internal_rating' => 'integer',
            'total_campaigns_completed' => 'integer',
            'total_paid_minor_units' => 'integer',
        ];
    }

    protected static function newFactory(): AgencyCreatorRelationFactory
    {
        return AgencyCreatorRelationFactory::new();
    }
}
