<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Models;

use App\Core\Tenancy\BelongsToAgency;
use App\Modules\Campaigns\Database\Factories\CampaignJobNotificationFactory;
use App\Modules\Creators\Models\Creator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The per-(campaign, creator) once-only stamp for the job-posted fan-out (Jobs
 * Board chunk 3, AH-056, D7). One row means "this creator has been told about
 * this job"; the unique composite means a re-list sends nothing.
 *
 * Deliberately minimal. No `ulid` (never route-bound, never emitted), no
 * {@see BelongsToAgency} (never read by an agency-scoped
 * query — the fan-out service scopes by `campaign_id`), and `$timestamps` off
 * because `notified_at` is the only time this table has to know.
 *
 * ⚠ Written BEFORE the row's meaning is fully earned: the fan-out queues the
 * mail and then stamps, per recipient, inside the loop (the AH-048 ordering).
 * That makes a worker retry skip everyone already stamped — the fan-out is
 * idempotent at recipient granularity — at the cost of one accepted failure
 * mode: a creator stamped whose mail then dies at the transport layer is never
 * re-notified for that job. Ratified as the right side of the trade (a
 * double-send to a live roster is worse than a single silent miss).
 *
 * @property int $id
 * @property int $campaign_id
 * @property int $creator_id
 * @property Carbon $notified_at
 */
final class CampaignJobNotification extends Model
{
    /** @use HasFactory<CampaignJobNotificationFactory> */
    use HasFactory;

    /**
     * `notified_at` carries the only timestamp this table needs.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'campaign_id',
        'creator_id',
        'notified_at',
    ];

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
            'notified_at' => 'datetime',
        ];
    }

    protected static function newFactory(): CampaignJobNotificationFactory
    {
        return CampaignJobNotificationFactory::new();
    }
}
