<?php

declare(strict_types=1);

namespace App\Modules\Boards\Models;

use App\Core\Concerns\HasUlid;
use App\Core\Tenancy\BelongsToAgency;
use App\Modules\Boards\Database\Factories\BoardColumnFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A user-defined, ordered column on a board (Sprint 12 Chunk 1, D-1). See
 * docs/03-DATA-MODEL.md §10 and docs/10-BOARD-AUTOMATION.md §7.
 *
 * Tenancy (D-2): directly addressable (the column CRUD endpoints), so it
 * carries a denormalized `agency_id` + BelongsToAgency for automatic scope
 * enforcement.
 *
 * `color_token` is the design-system status token in the §3.1 spelling
 * (`status-todefine`, …); the Chunk 2 SPA maps it to the `boardStatus` palette.
 *
 * @property int $id
 * @property string $ulid
 * @property int $board_id
 * @property int $agency_id
 * @property string $name
 * @property int $position
 * @property string $color_token
 * @property bool $is_terminal_success
 * @property bool $is_terminal_failure
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class BoardColumn extends Model
{
    use BelongsToAgency;

    /** @use HasFactory<BoardColumnFactory> */
    use HasFactory;

    use HasUlid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'board_id',
        'agency_id',
        'name',
        'position',
        'color_token',
        'is_terminal_success',
        'is_terminal_failure',
    ];

    /**
     * @return BelongsTo<Board, $this>
     */
    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    /**
     * @return HasMany<BoardCard, $this>
     */
    public function cards(): HasMany
    {
        return $this->hasMany(BoardCard::class, 'column_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_terminal_success' => 'boolean',
            'is_terminal_failure' => 'boolean',
        ];
    }

    protected static function newFactory(): BoardColumnFactory
    {
        return BoardColumnFactory::new();
    }
}
