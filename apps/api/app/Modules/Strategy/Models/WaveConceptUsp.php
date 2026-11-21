<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Models;

use App\Core\Tenancy\BelongsToAgency;
use App\Modules\Waves\Models\WaveMarket;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A USP chip of a concept (PL-2.3): a snapshot, not a live view of the matrix.
 *
 * @property int $id
 * @property int $agency_id
 * @property int $wave_id
 * @property int $market_id
 * @property-read WaveMarket $market
 * @property int $concept_id
 * @property int $usp_id
 * @property int $position
 * @property-read WaveUsp $usp
 * @property-read WaveConcept $concept
 */
final class WaveConceptUsp extends Model
{
    use BelongsToAgency;

    /**
     * @var list<string>
     */
    protected $fillable = ['agency_id', 'wave_id', 'market_id', 'concept_id', 'usp_id', 'position'];

    /**
     * PL-2.4: the market (a named group of countries) this row belongs to.
     *
     * @return BelongsTo<WaveMarket, $this>
     */
    public function market(): BelongsTo
    {
        return $this->belongsTo(WaveMarket::class, 'market_id');
    }

    /** @return BelongsTo<WaveUsp, $this> */
    public function usp(): BelongsTo
    {
        return $this->belongsTo(WaveUsp::class, 'usp_id');
    }

    /** @return BelongsTo<WaveConcept, $this> */
    public function concept(): BelongsTo
    {
        return $this->belongsTo(WaveConcept::class, 'concept_id');
    }
}
