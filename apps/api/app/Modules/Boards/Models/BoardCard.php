<?php

declare(strict_types=1);

namespace App\Modules\Boards\Models;

use App\Core\Concerns\HasUlid;
use App\Core\Tenancy\BelongsToAgency;
use App\Modules\Boards\Database\Factories\BoardCardFactory;
use App\Modules\Campaigns\Models\CampaignAssignment;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One card per CampaignAssignment (Sprint 12 Chunk 1, D-1/D-5). See
 * docs/03-DATA-MODEL.md §10 and docs/10-BOARD-AUTOMATION.md §4.
 *
 * Tenancy (D-2): directly addressable (the move + movements endpoints), so it
 * carries a denormalized `agency_id` + BelongsToAgency.
 *
 * The `assignment_id` UNIQUE backs firstOrCreate idempotency across the
 * CreateBoardCard invite listener + the lazy GET card-heal (D-5).
 *
 * `position` is present per §10 but INERT in P1 (intra-column ordering is P2).
 *
 * @property int $id
 * @property string $ulid
 * @property int $board_id
 * @property int $column_id
 * @property int $agency_id
 * @property int $assignment_id
 * @property int $position
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class BoardCard extends Model
{
    use BelongsToAgency;

    /** @use HasFactory<BoardCardFactory> */
    use HasFactory;

    use HasUlid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'board_id',
        'column_id',
        'agency_id',
        'assignment_id',
        'position',
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
    public function column(): BelongsTo
    {
        return $this->belongsTo(BoardColumn::class, 'column_id');
    }

    /**
     * @return BelongsTo<CampaignAssignment, $this>
     */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(CampaignAssignment::class, 'assignment_id');
    }

    /**
     * @return HasMany<BoardCardMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(BoardCardMovement::class, 'card_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    protected static function newFactory(): BoardCardFactory
    {
        return BoardCardFactory::new();
    }
}
