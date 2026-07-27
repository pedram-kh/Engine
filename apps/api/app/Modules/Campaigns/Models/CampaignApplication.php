<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Models;

use App\Core\Concerns\HasUlid;
use App\Core\Tenancy\BelongsToAgency;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Campaigns\Database\Factories\CampaignApplicationFactory;
use App\Modules\Campaigns\Enums\CampaignApplicationStatus;
use App\Modules\Creators\Models\Creator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A creator's application to a campaign listed on the jobs board (Jobs Board
 * chunk 3, AH-056, D1). One row per (campaign, creator) pair, forever — the
 * unique constraint is what enforces "one application" AND "no re-apply after
 * rejection", because the rejected row is retained rather than deleted.
 *
 * Deliberately NOT a pre-`invited` {@see CampaignAssignment} state. See the
 * migration docblock for the decisive reason (the `store()` idempotency
 * collision would let an agency's invite be silently swallowed with the offer
 * never persisted).
 *
 * ⚠ `agency_id` is denormalized from the parent campaign and MUST be set
 * explicitly at the insert site. {@see BelongsToAgency} auto-fills it from the
 * ambient tenant, but the only writer is a CREATOR-authenticated request, whose
 * `tenancy.set` context holds no agency — so the trait's fallback would throw
 * rather than guess. Setting it from `campaign->agency_id` is both the correct
 * value and the only value that cannot drift; a test pins that the two can
 * never diverge.
 *
 * ⚠ Creator-side reads MUST drop the global scope
 * (`withoutGlobalScope(BelongsToAgencyScope::class)`) — a creator relates to
 * many agencies and the ambient tenant must not narrow their own applications.
 * This is the same discipline `CreatorAssignmentController` already applies.
 *
 * No SoftDeletes and no Audited trait: there is no row-removing path in the
 * arc, and the free-text `note` must never land in an audit snapshot (the
 * `campaign_assignments.notes` precedent). Application lifecycle events are
 * audited explicitly under the `campaign_application.*` verbs.
 *
 * @property int $id
 * @property string $ulid
 * @property int $agency_id
 * @property int $campaign_id
 * @property int $creator_id
 * @property CampaignApplicationStatus $status
 * @property string|null $note
 * @property Carbon|null $responded_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class CampaignApplication extends Model
{
    use BelongsToAgency;

    /** @use HasFactory<CampaignApplicationFactory> */
    use HasFactory;

    use HasUlid;

    /**
     * The creator's apply note cap (D1: "max ~1000"). Enforced in validation,
     * not by the column (`text`) — the `cancelled_reason` precedent. Exposed as
     * a constant so the FormRequest rule and any test read one number.
     */
    public const NOTE_MAX_LENGTH = 1000;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'agency_id',
        'campaign_id',
        'creator_id',
        'status',
        'note',
        'responded_at',
    ];

    /**
     * @return BelongsTo<Agency, $this>
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * @return BelongsTo<Creator, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Creator::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CampaignApplicationStatus::class,
            'responded_at' => 'datetime',
        ];
    }

    protected static function newFactory(): CampaignApplicationFactory
    {
        return CampaignApplicationFactory::new();
    }
}
