<?php

declare(strict_types=1);

namespace App\Modules\TrackedJobs\Models;

use App\Core\Concerns\HasUlid;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Identity\Models\User;
use App\Modules\TrackedJobs\Database\Factories\TrackedJobFactory;
use App\Modules\TrackedJobs\Enums\TrackedJobStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Reusable tracked-job record.
 *
 * Sprint 3 Chunk 1 introduces this for the bulk creator-invitation flow;
 * future sprints reuse it (Sprint 14 GDPR exports, Sprint 10 payments).
 *
 * The estimated_completion_at field is intentionally nullable — bulk
 * invite has no good way to estimate completion (per D-pause-8 the
 * spec allows null, and we don't fabricate estimates).
 *
 * @property int $id
 * @property string $ulid
 * @property string $kind
 * @property int|null $initiator_user_id
 * @property int|null $agency_id
 * @property TrackedJobStatus $status
 * @property float $progress
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $estimated_completion_at
 * @property array<string, mixed>|null $result
 * @property string|null $failure_reason
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class TrackedJob extends Model
{
    /** @use HasFactory<TrackedJobFactory> */
    use HasFactory;

    use HasUlid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'kind',
        'initiator_user_id',
        'agency_id',
        'status',
        'progress',
        'started_at',
        'completed_at',
        'estimated_completion_at',
        'result',
        'failure_reason',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'queued',
        'progress' => 0,
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiator_user_id');
    }

    /**
     * @return BelongsTo<Agency, $this>
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TrackedJobStatus::class,
            'progress' => 'float',
            'result' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'estimated_completion_at' => 'datetime',
        ];
    }

    protected static function newFactory(): TrackedJobFactory
    {
        return TrackedJobFactory::new();
    }
}
