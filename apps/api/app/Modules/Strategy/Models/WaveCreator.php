<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Models;

use App\Core\Concerns\HasUlid;
use App\Core\Tenancy\BelongsToAgency;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Database\Factories\WaveCreatorFactory;
use App\Modules\Waves\Models\Wave;
use App\Modules\Waves\Models\WaveMarket;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One creator's place in one market of one wave (PL-3.1). There is NO status column: the status is derived from the
 * campaign (WaveCreatorStatus). One creator per wave (unique `(wave_id, creator_id)`). `slot_code` is the label on the
 * card (`SK-C1`), kept when the creator is replaced.
 *
 * @property int $id
 * @property string $ulid
 * @property int $agency_id
 * @property int $wave_id
 * @property int $market_id
 * @property int $creator_id
 * @property int|null $persona_id
 * @property int $slot
 * @property string $slot_code
 * @property int|null $added_by_user_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Wave $wave
 * @property-read WaveMarket $market
 * @property-read Creator $creator
 * @property-read WavePersona|null $persona
 */
final class WaveCreator extends Model
{
    use BelongsToAgency;

    /** @use HasFactory<WaveCreatorFactory> */
    use HasFactory;

    use HasUlid;

    /**
     * @var list<string>
     */
    protected $fillable = ['agency_id', 'wave_id', 'market_id', 'creator_id', 'persona_id', 'slot', 'slot_code', 'added_by_user_id'];

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

    /** @return BelongsTo<WavePersona, $this> */
    public function persona(): BelongsTo
    {
        return $this->belongsTo(WavePersona::class, 'persona_id');
    }

    /** @return BelongsTo<User, $this> */
    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by_user_id');
    }

    /** @return HasMany<WaveCreatorDecision, $this> */
    public function decisions(): HasMany
    {
        return $this->hasMany(WaveCreatorDecision::class, 'wave_creator_id');
    }

    protected static function newFactory(): WaveCreatorFactory
    {
        return WaveCreatorFactory::new();
    }
}
