<?php

declare(strict_types=1);

namespace App\Modules\Boards\Models;

use App\Modules\Boards\Database\Factories\BoardCardMovementFactory;
use App\Modules\Boards\Enums\MovementTrigger;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An append-only record of one card movement (Sprint 12 Chunk 1, D-7/D-9). See
 * docs/03-DATA-MODEL.md §10 and docs/10-BOARD-AUTOMATION.md §13.
 *
 * Tenancy (D-2): scopes transitively through `card_id` (no own `agency_id`).
 *
 * Append-only: no `updated_at` (only `created_at`), no soft-delete. `triggered_by`
 * is `event` (automation) or `user` (manual, Q1). A manual move writes BOTH this
 * row (triggered_by=user) and an `audit_logs` row (`board.card_moved_manually`,
 * D-9); an automation move writes ONLY this row (triggered_by=event).
 *
 * @property int $id
 * @property int $card_id
 * @property int|null $from_column_id
 * @property int|null $to_column_id
 * @property MovementTrigger $triggered_by
 * @property string|null $triggered_event_key
 * @property int|null $triggered_by_user_id
 * @property string|null $reason
 * @property Carbon $created_at
 */
final class BoardCardMovement extends Model
{
    /** @use HasFactory<BoardCardMovementFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'card_id',
        'from_column_id',
        'to_column_id',
        'triggered_by',
        'triggered_event_key',
        'triggered_by_user_id',
        'reason',
    ];

    /**
     * @return BelongsTo<BoardCard, $this>
     */
    public function card(): BelongsTo
    {
        return $this->belongsTo(BoardCard::class, 'card_id');
    }

    /**
     * @return BelongsTo<BoardColumn, $this>
     */
    public function fromColumn(): BelongsTo
    {
        return $this->belongsTo(BoardColumn::class, 'from_column_id');
    }

    /**
     * @return BelongsTo<BoardColumn, $this>
     */
    public function toColumn(): BelongsTo
    {
        return $this->belongsTo(BoardColumn::class, 'to_column_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function triggeredByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'triggered_by' => MovementTrigger::class,
            'created_at' => 'datetime',
        ];
    }

    protected static function newFactory(): BoardCardMovementFactory
    {
        return BoardCardMovementFactory::new();
    }
}
