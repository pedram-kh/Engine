<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Models;

use App\Core\Tenancy\BelongsToAgency;
use App\Modules\Waves\Models\WaveMarket;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A LOCAL script language of a market (PL-3.2b). English is always there and is never a row. `position` is the order the editor
 * shows the languages in.
 *
 * @property int $id
 * @property int $agency_id
 * @property int $wave_id
 * @property int $market_id
 * @property string $language
 * @property int $position
 */
final class WaveMarketScriptLanguage extends Model
{
    use BelongsToAgency;

    /**
     * @var list<string>
     */
    protected $fillable = ['agency_id', 'wave_id', 'market_id', 'language', 'position'];

    /** @return BelongsTo<WaveMarket, $this> */
    public function market(): BelongsTo
    {
        return $this->belongsTo(WaveMarket::class, 'market_id');
    }
}
