<?php

declare(strict_types=1);

namespace App\Modules\Boards\Models;

use App\Core\Concerns\HasUlid;
use App\Core\Tenancy\BelongsToAgency;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Boards\Database\Factories\BoardFactory;
use App\Modules\Campaigns\Models\Campaign;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One board per campaign (Sprint 12 Chunk 1, D-1). See docs/03-DATA-MODEL.md §10
 * and docs/10-BOARD-AUTOMATION.md §1.
 *
 * Tenancy (D-2): tenant-scoped via BelongsToAgency (`agency_id`, denormalized
 * from the campaign, mirroring message_threads). The board is lazily
 * provisioned on first GET (D-4) — the `campaign_id` UNIQUE backs firstOrCreate
 * idempotency, so no backfill migration is needed.
 *
 * @property int $id
 * @property string $ulid
 * @property int $agency_id
 * @property int $campaign_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class Board extends Model
{
    use BelongsToAgency;

    /** @use HasFactory<BoardFactory> */
    use HasFactory;

    use HasUlid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'agency_id',
        'campaign_id',
    ];

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * @return HasMany<BoardColumn, $this>
     */
    public function columns(): HasMany
    {
        return $this->hasMany(BoardColumn::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<BoardAutomation, $this>
     */
    public function automations(): HasMany
    {
        return $this->hasMany(BoardAutomation::class);
    }

    /**
     * @return HasMany<BoardCard, $this>
     */
    public function cards(): HasMany
    {
        return $this->hasMany(BoardCard::class);
    }

    /**
     * @return BelongsTo<Agency, $this>
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    protected static function newFactory(): BoardFactory
    {
        return BoardFactory::new();
    }
}
