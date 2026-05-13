<?php

declare(strict_types=1);

namespace App\Modules\Creators\Models;

use App\Core\Concerns\HasUlid;
use App\Modules\Creators\Database\Factories\CreatorAvailabilityBlockFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Availability block on a Creator's calendar.
 *
 * TABLE/MODEL ONLY in Sprint 3 Chunk 1. CRUD endpoints + UI ship in
 * Sprint 5 (calendar). This model exists so cross-cutting code (e.g.
 * Sprint 7's auto-block on assignment_id) has the schema available.
 *
 * @property int $id
 * @property string $ulid
 * @property int $creator_id
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property bool $is_all_day
 * @property string $kind
 * @property string $block_type
 * @property string|null $reason
 * @property int|null $assignment_id
 * @property bool $is_recurring
 * @property string|null $recurrence_rule
 * @property string|null $external_calendar_id
 * @property string|null $external_event_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class CreatorAvailabilityBlock extends Model
{
    /** @use HasFactory<CreatorAvailabilityBlockFactory> */
    use HasFactory;

    use HasUlid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'creator_id',
        'starts_at',
        'ends_at',
        'is_all_day',
        'kind',
        'block_type',
        'reason',
        'assignment_id',
        'is_recurring',
        'recurrence_rule',
        'external_calendar_id',
        'external_event_id',
    ];

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
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_all_day' => 'boolean',
            'is_recurring' => 'boolean',
        ];
    }

    protected static function newFactory(): CreatorAvailabilityBlockFactory
    {
        return CreatorAvailabilityBlockFactory::new();
    }
}
