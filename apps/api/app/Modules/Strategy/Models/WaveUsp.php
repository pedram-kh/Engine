<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Models;

use App\Core\Concerns\HasUlid;
use App\Core\Tenancy\BelongsToAgency;
use App\Modules\Strategy\Database\Factories\WaveUspFactory;
use App\Modules\Strategy\Enums\UspColor;
use App\Modules\Waves\Models\Wave;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One USP of a wave (PL-2.1). The colour is stored and fixed for the life of the USP; `position` is the card number
 * and the future matrix row order.
 *
 * @property int $id
 * @property string $ulid
 * @property int $agency_id
 * @property int $wave_id
 * @property UspColor $color_key
 * @property int $position
 * @property string $title
 * @property string|null $explainer
 * @property string|null $creative_angle
 * @property string|null $copy_seed
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Wave $wave
 */
final class WaveUsp extends Model
{
    use BelongsToAgency;

    /** @use HasFactory<WaveUspFactory> */
    use HasFactory;

    use HasUlid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'agency_id',
        'wave_id',
        'color_key',
        'position',
        'title',
        'explainer',
        'creative_angle',
        'copy_seed',
    ];

    /**
     * @return BelongsTo<Wave, $this>
     */
    public function wave(): BelongsTo
    {
        return $this->belongsTo(Wave::class)->withTrashed();
    }

    /**
     * @return HasMany<WavePersonaAngle, $this>
     */
    public function angles(): HasMany
    {
        return $this->hasMany(WavePersonaAngle::class, 'usp_id');
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'color_key' => UspColor::class,
            'position' => 'integer',
        ];
    }

    protected static function newFactory(): WaveUspFactory
    {
        return WaveUspFactory::new();
    }
}
