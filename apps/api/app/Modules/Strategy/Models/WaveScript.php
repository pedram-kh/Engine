<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Models;

use App\Core\Concerns\HasUlid;
use App\Core\Tenancy\BelongsToAgency;
use App\Modules\Creators\Models\Creator;
use App\Modules\Strategy\Database\Factories\WaveScriptFactory;
use App\Modules\Waves\Models\Wave;
use App\Modules\Waves\Models\WaveMarket;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One script of one creator's slot (PL-3.2a). At most three per creator per wave (CHECK and unique key). Archived, not
 * deleted, when its slot is removed or replaced: `wave_creator_id` is then NULL and `archived_at` is set. `angle` null means
 * "live": the brief shows the persona's angle. `revision` moves on every change of the script or a block of it;
 * `brief_revision` only on a change of the brief.
 *
 * @property int $id
 * @property string $ulid
 * @property int $agency_id
 * @property int $wave_id
 * @property int $market_id
 * @property int $creator_id
 * @property int|null $wave_creator_id
 * @property Carbon|null $archived_at
 * @property int $number
 * @property string|null $angle
 * @property int $duration_seconds
 * @property string|null $language
 * @property int $revision
 * @property int $brief_revision
 * @property int|null $created_by_user_id
 * @property int|null $updated_by_user_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Wave $wave
 * @property-read WaveMarket $market
 * @property-read Creator $creator
 */
final class WaveScript extends Model
{
    use BelongsToAgency;

    /** @use HasFactory<WaveScriptFactory> */
    use HasFactory;

    use HasUlid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'agency_id', 'wave_id', 'market_id', 'creator_id', 'wave_creator_id', 'archived_at', 'number', 'angle', 'duration_seconds',
        'language', 'revision', 'brief_revision', 'created_by_user_id', 'updated_by_user_id',
    ];

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** @return BelongsTo<Wave, $this> */
    public function wave(): BelongsTo
    {
        return $this->belongsTo(Wave::class);
    }

    /** @return BelongsTo<WaveMarket, $this> */
    public function market(): BelongsTo
    {
        return $this->belongsTo(WaveMarket::class, 'market_id');
    }

    /** @return BelongsTo<Creator, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Creator::class);
    }

    /** @return BelongsTo<WaveCreator, $this> */
    public function slot(): BelongsTo
    {
        return $this->belongsTo(WaveCreator::class, 'wave_creator_id');
    }

    /** @return HasMany<WaveScriptHook, $this> */
    public function hooks(): HasMany
    {
        return $this->hasMany(WaveScriptHook::class, 'script_id')->orderBy('position')->orderBy('id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['archived_at' => 'datetime'];
    }

    protected static function newFactory(): WaveScriptFactory
    {
        return WaveScriptFactory::new();
    }
}
