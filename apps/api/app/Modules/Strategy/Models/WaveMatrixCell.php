<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Models;

use App\Core\Concerns\HasUlid;
use App\Core\Tenancy\BelongsToAgency;
use App\Modules\Strategy\Database\Factories\WaveMatrixCellFactory;
use App\Modules\Strategy\Enums\MatrixLevel;
use App\Modules\Waves\Models\WaveMarket;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One SET cell of the messaging matrix (PL-2.2): a level, a one-line rationale and a message line for one persona and
 * one USP of the same wave. "Not set" is no row. The composite foreign keys make "same wave, persona's market" true in
 * the database.
 *
 * @property int $id
 * @property string $ulid
 * @property int $agency_id
 * @property int $wave_id
 * @property int $market_id
 * @property-read WaveMarket $market
 * @property int $persona_id
 * @property int $usp_id
 * @property MatrixLevel $level
 * @property string|null $rationale
 * @property string|null $message_line
 * @property string $source
 * @property Carbon|null $edited_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read WavePersona $persona
 * @property-read WaveUsp $usp
 */
final class WaveMatrixCell extends Model
{
    use BelongsToAgency;

    /** @use HasFactory<WaveMatrixCellFactory> */
    use HasFactory;

    use HasUlid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'agency_id',
        'wave_id',
        'market_id',
        'persona_id',
        'usp_id',
        'level',
        'rationale',
        'message_line',
        'source',
        'edited_at',
    ];

    /**
     * PL-2.4: the market (a named group of countries) this row belongs to.
     *
     * @return BelongsTo<WaveMarket, $this>
     */
    public function market(): BelongsTo
    {
        return $this->belongsTo(WaveMarket::class, 'market_id');
    }

    /**
     * @return BelongsTo<WavePersona, $this>
     */
    public function persona(): BelongsTo
    {
        return $this->belongsTo(WavePersona::class, 'persona_id');
    }

    /**
     * @return BelongsTo<WaveUsp, $this>
     */
    public function usp(): BelongsTo
    {
        return $this->belongsTo(WaveUsp::class, 'usp_id');
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'level' => MatrixLevel::class,
            'edited_at' => 'datetime',
        ];
    }

    protected static function newFactory(): WaveMatrixCellFactory
    {
        return WaveMatrixCellFactory::new();
    }
}
