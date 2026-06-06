<?php

declare(strict_types=1);

namespace App\Modules\Boards\Models;

use App\Core\Concerns\HasUlid;
use App\Modules\Boards\Database\Factories\BoardAutomationFactory;
use App\Modules\Boards\Enums\BoardAutomationActionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An event-key → column-move mapping on a board (Sprint 12 Chunk 1, D-1/D-6).
 * See docs/03-DATA-MODEL.md §10 and docs/10-BOARD-AUTOMATION.md §5.
 *
 * Tenancy (D-2): scopes transitively through `board_id` (no own `agency_id`, no
 * BelongsToAgency) — matching the messaging design.
 *
 * `event_key` is the AuditAction verb string (= `AssignmentTransitioned::eventKey()`,
 * D-6). `action_type` is honored by the listener — `None` is inert by design.
 * `condition` is the P1 condition seam (§5.3) — present-but-unwritten this chunk.
 *
 * @property int $id
 * @property string $ulid
 * @property int $board_id
 * @property string $event_key
 * @property BoardAutomationActionType $action_type
 * @property int|null $target_column_id
 * @property array<string, mixed>|null $condition
 * @property bool $is_enabled
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class BoardAutomation extends Model
{
    /** @use HasFactory<BoardAutomationFactory> */
    use HasFactory;

    use HasUlid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'board_id',
        'event_key',
        'action_type',
        'target_column_id',
        'condition',
        'is_enabled',
    ];

    /**
     * @return BelongsTo<Board, $this>
     */
    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    /**
     * @return BelongsTo<BoardColumn, $this>
     */
    public function targetColumn(): BelongsTo
    {
        return $this->belongsTo(BoardColumn::class, 'target_column_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action_type' => BoardAutomationActionType::class,
            'condition' => 'array',
            'is_enabled' => 'boolean',
        ];
    }

    protected static function newFactory(): BoardAutomationFactory
    {
        return BoardAutomationFactory::new();
    }
}
