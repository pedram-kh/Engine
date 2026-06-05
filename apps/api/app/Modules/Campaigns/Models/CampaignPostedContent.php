<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Models;

use App\Core\Concerns\HasUlid;
use App\Modules\Campaigns\Database\Factories\CampaignPostedContentFactory;
use App\Modules\Campaigns\Enums\PostedContentVerificationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The actual published post on social for a campaign assignment (Sprint 9
 * Chunk 1, D-2). A CHILD of the assignment — points UP via `assignment_id`.
 *
 * Chunk 1 WRITES only the creator-reported side (`platform`, `post_url`,
 * `posted_at`, `verification_status` default `pending`). Chunk 2's
 * verification job advances `verification_status` + stamps `verified_at` /
 * `platform_post_id`. The `markPosted()` transition audit
 * (`assignment.posted_by_creator`) carries the posted-content id + platform
 * in metadata (the free-text `post_url` is excluded, D-3).
 *
 * @property int $id
 * @property string $ulid
 * @property int $assignment_id
 * @property string $platform
 * @property string $post_url
 * @property string|null $platform_post_id
 * @property Carbon|null $posted_at
 * @property Carbon|null $verified_at
 * @property PostedContentVerificationStatus $verification_status
 * @property Carbon|null $last_metrics_synced_at
 * @property array<string, mixed>|null $metrics
 * @property array<int, array<string, mixed>>|null $metrics_history
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class CampaignPostedContent extends Model
{
    /** @use HasFactory<CampaignPostedContentFactory> */
    use HasFactory;

    use HasUlid;

    protected $table = 'campaign_posted_content';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'assignment_id',
        'platform',
        'post_url',
        'platform_post_id',
        'posted_at',
        'verified_at',
        'verification_status',
        'last_metrics_synced_at',
        'metrics',
        'metrics_history',
    ];

    /**
     * @return BelongsTo<CampaignAssignment, $this>
     */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(CampaignAssignment::class, 'assignment_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'posted_at' => 'datetime',
            'verified_at' => 'datetime',
            'verification_status' => PostedContentVerificationStatus::class,
            'last_metrics_synced_at' => 'datetime',
            'metrics' => 'array',
            'metrics_history' => 'array',
        ];
    }

    protected static function newFactory(): CampaignPostedContentFactory
    {
        return CampaignPostedContentFactory::new();
    }
}
