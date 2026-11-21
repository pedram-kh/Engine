<?php

declare(strict_types=1);

namespace App\Modules\Waves\Models;

use App\Core\Tenancy\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One country of a market (PL-2.4). `unique (wave_id, country)` makes "a country belongs to one market within a wave" true
 * in the database (ruling M2).
 *
 * @property int $id
 * @property int $agency_id
 * @property int $wave_id
 * @property int $market_id
 * @property string $country
 * @property Carbon|null $created_at
 */
final class WaveMarketCountry extends Model
{
    use BelongsToAgency;

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = ['agency_id', 'wave_id', 'market_id', 'country'];

    /**
     * @return BelongsTo<WaveMarket, $this>
     */
    public function market(): BelongsTo
    {
        return $this->belongsTo(WaveMarket::class, 'market_id');
    }
}
