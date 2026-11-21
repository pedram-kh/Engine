<?php

declare(strict_types=1);

namespace App\Modules\Waves\Models;

use App\Core\Concerns\HasUlid;
use App\Core\Tenancy\BelongsToAgency;
use App\Modules\Waves\Database\Factories\WaveMarketFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A market of a wave (PL-2.4): a NAMED GROUP of one or more countries. Personas, matrix cells, concepts and concept images
 * belong to a market row. `ulid` is the public identifier (the short `code` is editable, so it never is one); `name` is a
 * typed, agency-authored label (never put in an audit payload); `code` is the persona-code prefix.
 *
 * @property int $id
 * @property string $ulid
 * @property int $agency_id
 * @property int $wave_id
 * @property string $name
 * @property string $code
 * @property int $position
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Wave $wave
 * @property-read Collection<int, WaveMarketCountry> $countries
 */
final class WaveMarket extends Model
{
    use BelongsToAgency;

    /** @use HasFactory<WaveMarketFactory> */
    use HasFactory;

    use HasUlid;

    /**
     * @var list<string>
     */
    protected $fillable = ['agency_id', 'wave_id', 'name', 'code', 'position'];

    /**
     * @return BelongsTo<Wave, $this>
     */
    public function wave(): BelongsTo
    {
        return $this->belongsTo(Wave::class)->withTrashed();
    }

    /**
     * @return HasMany<WaveMarketCountry, $this>
     */
    public function countries(): HasMany
    {
        return $this->hasMany(WaveMarketCountry::class, 'market_id')->orderBy('id');
    }

    /**
     * @return list<string>
     */
    public function countryCodes(): array
    {
        $codes = [];
        foreach ($this->countries as $country) {
            $codes[] = $country->country;
        }

        return $codes;
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    protected static function newFactory(): WaveMarketFactory
    {
        return WaveMarketFactory::new();
    }
}
