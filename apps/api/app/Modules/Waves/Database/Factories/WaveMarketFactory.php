<?php

declare(strict_types=1);

namespace App\Modules\Waves\Database\Factories;

use App\Modules\Waves\Models\Wave;
use App\Modules\Waves\Models\WaveMarket;
use App\Modules\Waves\Models\WaveMarketCountry;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<WaveMarket>
 *
 * Tests only. A market is created with its countries; with none given it takes the first country code no market of the
 * wave uses yet (so two factory markets of one wave never collide on `unique (wave_id, country)`).
 */
final class WaveMarketFactory extends Factory
{
    protected $model = WaveMarket::class;

    /** @var list<string>|null */
    private ?array $countryCodes = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'wave_id' => fn () => Wave::factory()->create()->id,
            'agency_id' => fn (array $a) => (int) Wave::withoutGlobalScopes()->whereKey($a['wave_id'])->value('agency_id'),
            'name' => 'Market '.fake()->unique()->numberBetween(1, 999999),
            'code' => fn () => strtoupper(substr(str_replace(['-', '_'], '', fake()->unique()->bothify('?#?#?#')), 0, 6)),
            'position' => fn (array $a) => 1 + (int) WaveMarket::withoutGlobalScopes()->where('wave_id', $a['wave_id'])->max('position'),
        ];
    }

    /**
     * @param  list<string>  $codes  uppercase ISO alpha-2 codes
     */
    public function withCountries(array $codes): static
    {
        $clone = clone $this;
        $clone->countryCodes = $codes;

        return $clone;
    }

    public function create($attributes = [], ?Model $parent = null)
    {
        $result = parent::create($attributes, $parent);

        $models = $result instanceof Collection ? $result->all() : [$result];
        foreach ($models as $market) {
            if ($market instanceof WaveMarket && ! WaveMarketCountry::withoutGlobalScopes()->where('market_id', $market->id)->exists()) {
                $codes = $this->countryCodes ?? [self::firstFreeCountry($market->wave_id)];
                foreach ($codes as $code) {
                    WaveMarketCountry::query()->create(['agency_id' => $market->agency_id, 'wave_id' => $market->wave_id, 'market_id' => $market->id, 'country' => $code]);
                }
                $market->unsetRelation('countries');
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function newInstance(array $arguments = []): static
    {
        $instance = parent::newInstance($arguments);
        $instance->countryCodes = $this->countryCodes;

        return $instance;
    }

    private static function firstFreeCountry(int $waveId): string
    {
        $used = WaveMarketCountry::withoutGlobalScopes()->where('wave_id', $waveId)->pluck('country')->all();
        foreach (['SK', 'RO', 'CZ', 'HU', 'PL', 'AT', 'DE', 'FR', 'ES', 'IT', 'PT', 'NL', 'BE', 'IE', 'GB', 'SE', 'NO', 'DK', 'FI', 'GR', 'BG', 'HR', 'SI', 'LT', 'LV', 'EE', 'LU', 'MT', 'CY', 'CH'] as $code) {
            if (! in_array($code, $used, true)) {
                return $code;
            }
        }

        return 'SK';
    }
}
