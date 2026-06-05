<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Models;

use App\Core\Concerns\HasUlid;
use App\Modules\Campaigns\Database\Factories\CampaignDraftFactory;
use App\Modules\Campaigns\Enums\DraftReviewStatus;
use App\Modules\Campaigns\Services\CampaignAssignmentStateMachine;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A single draft submission for a campaign assignment (Sprint 9 Chunk 1, D-1).
 * A CHILD of the assignment — points UP via `assignment_id`. One row per
 * submission attempt; `version` increments per resubmission so the full
 * history is preserved (D-6).
 *
 * Chunk 1 WRITES only the submission side (`version`, `submitted_*`,
 * `caption`, `hashtags`, `mentions`, `media_attachments`); the review-trail
 * columns are populated by Chunk 2 (agency review).
 *
 * This model intentionally does NOT use the Audited trait — the free-text
 * `caption` / `review_feedback` must never land in an audit snapshot (the
 * hand-written-audit discipline, D-3). The draft-submitted fact is recorded by
 * {@see CampaignAssignmentStateMachine::submitDraft()}
 * via the `assignment.draft_submitted` transition audit (which carries the
 * draft id + version + media count in metadata, free text excluded).
 *
 * @property int $id
 * @property string $ulid
 * @property int $assignment_id
 * @property int $version
 * @property int|null $submitted_by_creator_id
 * @property Carbon|null $submitted_at
 * @property string|null $caption
 * @property array<int, string>|null $hashtags
 * @property array<int, string>|null $mentions
 * @property array<int, array<string, mixed>>|null $media_attachments
 * @property DraftReviewStatus $review_status
 * @property Carbon|null $reviewed_at
 * @property int|null $reviewed_by_user_id
 * @property string|null $review_feedback
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class CampaignDraft extends Model
{
    /** @use HasFactory<CampaignDraftFactory> */
    use HasFactory;

    use HasUlid;

    protected $table = 'campaign_drafts';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'assignment_id',
        'version',
        'submitted_by_creator_id',
        'submitted_at',
        'caption',
        'hashtags',
        'mentions',
        'media_attachments',
        'review_status',
        'reviewed_at',
        'reviewed_by_user_id',
        'review_feedback',
    ];

    /**
     * @return BelongsTo<CampaignAssignment, $this>
     */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(CampaignAssignment::class, 'assignment_id');
    }

    /**
     * @return BelongsTo<Creator, $this>
     */
    public function submittedByCreator(): BelongsTo
    {
        return $this->belongsTo(Creator::class, 'submitted_by_creator_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'submitted_at' => 'datetime',
            'hashtags' => 'array',
            'mentions' => 'array',
            'media_attachments' => 'array',
            'review_status' => DraftReviewStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    protected static function newFactory(): CampaignDraftFactory
    {
        return CampaignDraftFactory::new();
    }
}
