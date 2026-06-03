<?php

declare(strict_types=1);

namespace App\Modules\Creators\Models;

use App\Core\Concerns\HasUlid;
use App\Modules\Creators\Database\Factories\CreatorAvailabilityBlockFactory;
use App\Modules\Creators\Enums\BlockType;
use App\Modules\Creators\Enums\Kind;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Availability block on a Creator's calendar.
 *
 * Schema + model shipped in Sprint 3 Chunk 1; CRUD + the recurrence
 * expansion engine + conflict-detection + the agency read-view shipped
 * in Sprint 5 Chunk A. The calendar UI is Sprint 5 Chunk B.
 *
 * `kind` + `block_type` are backed by enums + casts as of Sprint 5
 * Chunk A (D-a2) — both were bare unvalidated strings before (B4).
 *
 * @property int $id
 * @property string $ulid
 * @property int $creator_id
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property bool $is_all_day
 * @property Kind $kind
 * @property BlockType $block_type
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
            'kind' => Kind::class,
            'block_type' => BlockType::class,
        ];
    }

    protected static function newFactory(): CreatorAvailabilityBlockFactory
    {
        return CreatorAvailabilityBlockFactory::new();
    }
}
