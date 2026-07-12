<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Models;

use App\Core\Concerns\HasUlid;
use App\Core\Tenancy\BelongsToAgency;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Boards\Models\BoardCard;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Database\Factories\CampaignAssignmentFactory;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Services\CampaignAssignmentStateMachine;
use App\Modules\Creators\Enums\ContractStatus;
use App\Modules\Creators\Models\Contract;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * The heart of the system — one creator engaged on one campaign (Sprint 8
 * Chunk 1, D-2). See docs/03-DATA-MODEL.md §7.
 *
 * The `status` column is driven exclusively by
 * {@see CampaignAssignmentStateMachine} —
 * no controller flips it directly (D-5). Transitions are audited + emit a
 * domain event there; this model intentionally does NOT use the Audited
 * trait (free-text `notes` / `cancelled_reason` must never land in a
 * snapshot, and the state machine owns the transition vocabulary).
 *
 * @property int $id
 * @property string $ulid
 * @property int $agency_id
 * @property int $campaign_id
 * @property int $brand_id
 * @property int $creator_id
 * @property AssignmentStatus $status
 * @property Carbon|null $invited_at
 * @property int|null $invited_by_user_id
 * @property Carbon|null $responded_at
 * @property Carbon|null $accepted_at
 * @property int|null $contract_id
 * @property int|null $agreed_fee_minor_units
 * @property string|null $agreed_fee_currency
 * @property string|null $fee_per
 * @property string|null $offer_description
 * @property string|null $offer_attachment_path
 * @property string|null $offer_attachment_name
 * @property string|null $offer_attachment_mime
 * @property int|null $offer_attachment_size_bytes
 * @property int|null $countered_fee_minor_units
 * @property string|null $countered_fee_currency
 * @property int|null $markup_minor_units
 * @property int|null $total_charged_to_brand_minor_units
 * @property array<string, mixed>|null $deliverables
 * @property Carbon|null $posting_due_at
 * @property Carbon|null $draft_due_at
 * @property Carbon|null $submitted_draft_at
 * @property Carbon|null $approved_at
 * @property Carbon|null $posted_at
 * @property Carbon|null $verified_live_at
 * @property Carbon|null $posting_overdue_flagged_at
 * @property Carbon|null $draft_overdue_flagged_at
 * @property int|null $payment_id
 * @property Carbon|null $cancelled_at
 * @property string|null $cancelled_reason
 * @property int|null $cancelled_by_user_id
 * @property string|null $notes
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 */
final class CampaignAssignment extends Model
{
    use BelongsToAgency;

    /** @use HasFactory<CampaignAssignmentFactory> */
    use HasFactory;

    use HasUlid;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'agency_id',
        'campaign_id',
        'brand_id',
        'creator_id',
        'status',
        'invited_at',
        'invited_by_user_id',
        'responded_at',
        'accepted_at',
        'contract_id',
        'agreed_fee_minor_units',
        'agreed_fee_currency',
        'fee_per',
        'offer_description',
        'offer_attachment_path',
        'offer_attachment_name',
        'offer_attachment_mime',
        'offer_attachment_size_bytes',
        'countered_fee_minor_units',
        'countered_fee_currency',
        'markup_minor_units',
        'total_charged_to_brand_minor_units',
        'deliverables',
        'posting_due_at',
        'draft_due_at',
        'submitted_draft_at',
        'approved_at',
        'posted_at',
        'verified_live_at',
        'posting_overdue_flagged_at',
        'draft_overdue_flagged_at',
        'payment_id',
        'cancelled_at',
        'cancelled_reason',
        'cancelled_by_user_id',
        'notes',
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
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    /**
     * @return BelongsTo<Contract, $this>
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /**
     * @return HasMany<CampaignPostedContent, $this>
     */
    public function postedContent(): HasMany
    {
        return $this->hasMany(CampaignPostedContent::class, 'assignment_id');
    }

    /**
     * The active posted-content row (verification-resolution chunk). The
     * verification job + the resolution surfaces order by id, so the
     * highest-id row is the current one (a fresh ACT2 resubmit supersedes the
     * failed post, which is kept as history).
     *
     * @return HasOne<CampaignPostedContent, $this>
     */
    public function latestPostedContent(): HasOne
    {
        return $this->hasOne(CampaignPostedContent::class, 'assignment_id')->latestOfMany();
    }

    /**
     * The per-campaign contract awaiting the creator's acceptance
     * (contract-issue visibility fix). The Contract subject is polymorphic
     * (`subject_type = campaign_assignment`, `subject_id = id`); the attach
     * endpoint refuses a second `sent` row, so at most one exists at a time.
     * Drives the agency Creators-tab "Contract sent — awaiting creator"
     * state. Emitted on the resource only when eager-loaded.
     *
     * @return HasOne<Contract, $this>
     */
    public function sentContract(): HasOne
    {
        return $this->hasOne(Contract::class, 'subject_id')
            ->where('subject_type', Contract::SUBJECT_CAMPAIGN_ASSIGNMENT)
            ->where('status', ContractStatus::Sent);
    }

    /**
     * The board card representing this assignment (Sprint 12 Chunk 1, D-5). 1:1
     * via the `board_cards.assignment_id` UNIQUE; null until the card is
     * provisioned (the CreateBoardCard invite listener or the lazy GET heal).
     *
     * @return HasOne<BoardCard, $this>
     */
    public function boardCard(): HasOne
    {
        return $this->hasOne(BoardCard::class, 'assignment_id');
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AssignmentStatus::class,
            'invited_at' => 'datetime',
            'responded_at' => 'datetime',
            'accepted_at' => 'datetime',
            'agreed_fee_minor_units' => 'integer',
            'offer_attachment_size_bytes' => 'integer',
            'countered_fee_minor_units' => 'integer',
            'markup_minor_units' => 'integer',
            'total_charged_to_brand_minor_units' => 'integer',
            'deliverables' => 'array',
            'posting_due_at' => 'datetime',
            'draft_due_at' => 'datetime',
            'submitted_draft_at' => 'datetime',
            'approved_at' => 'datetime',
            'posted_at' => 'datetime',
            'verified_live_at' => 'datetime',
            'posting_overdue_flagged_at' => 'datetime',
            'draft_overdue_flagged_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function newFactory(): CampaignAssignmentFactory
    {
        return CampaignAssignmentFactory::new();
    }
}
