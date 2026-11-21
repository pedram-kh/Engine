<?php

declare(strict_types=1);

namespace App\Modules\Waves\Database\Factories;

use App\Modules\Agencies\Database\Factories\AgencyFactory;
use App\Modules\Brands\Database\Factories\BrandFactory;
use App\Modules\Identity\Database\Factories\UserFactory;
use App\Modules\Waves\Enums\WaveStatus;
use App\Modules\Waves\Models\Wave;
use App\Modules\Waves\Models\WaveMarket;
use App\Modules\Waves\Models\WaveMarketCountry;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<Wave>
 */
final class WaveFactory extends Factory
{
    /** English names for the country codes the walk seeders and tests use. */
    private const NAMES = ['SK' => 'Slovakia', 'RO' => 'Romania', 'CZ' => 'Czechia', 'HU' => 'Hungary', 'PL' => 'Poland', 'DE' => 'Germany', 'AT' => 'Austria', 'GB' => 'United Kingdom', 'FR' => 'France', 'IT' => 'Italy', 'IE' => 'Ireland', 'SE' => 'Sweden', 'NO' => 'Norway', 'DK' => 'Denmark', 'FI' => 'Finland'];

    protected $model = Wave::class;

    /**
     * PL-2.4: the markets a created wave gets. `null` means the default, one market "Slovakia" (code SK, country SK), so a
     * test that does not care about markets stays small. Set with {@see withMarkets()}; `[]` creates a wave with none.
     *
     * @var list<array{name: string, code?: string, countries: list<string>}>|null
     */
    private ?array $marketSpecs = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agency_id' => AgencyFactory::new(),
            // The brand MUST belong to the same agency as the wave.
            'brand_id' => fn (array $attributes) => BrandFactory::new()->forAgency((int) $attributes['agency_id']),
            'campaign_id' => null,
            'name' => 'Wave '.fake()->unique()->numberBetween(1, 99999),
            'go_live_on' => '2026-12-01',
            'status' => WaveStatus::Draft,
            'created_by_user_id' => UserFactory::new(),
        ];
    }

    /**
     * @param  list<array{name: string, code?: string, countries: list<string>}>  $specs
     */
    public function withMarkets(array $specs): static
    {
        $clone = clone $this;
        $clone->marketSpecs = $specs;

        return $clone;
    }

    /**
     * @param  array<string, mixed>|(callable(array<string, mixed>): array<string, mixed>)  $attributes
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        $result = parent::create($attributes, $parent);

        $waves = $result instanceof Collection ? $result->all() : [$result];
        foreach ($waves as $wave) {
            // `create($attributes)` re-enters create() on a state copy, so the same wave can come back here twice.
            if ($wave instanceof Wave && ! WaveMarket::withoutGlobalScopes()->where('wave_id', $wave->id)->exists()) {
                self::attachMarkets($wave, $this->marketSpecs ?? [['name' => 'Slovakia', 'code' => 'SK', 'countries' => ['SK']]]);
            }
        }

        return $result;
    }

    /**
     * One single-country market per code (name = the country, short code = the country code), as the web's "add a country"
     * creates. For tests and seeders.
     *
     * @param  array<int, string>  $codes
     * @return list<array{name: string, code: string, countries: list<string>}>
     */
    public static function singleCountryMarkets(array $codes): array
    {
        return array_map(static fn (string $c): array => ['name' => self::NAMES[$c] ?? $c, 'code' => $c, 'countries' => [$c]], array_values($codes));
    }

    /** The id of the wave's market with this short code (tests and seeders). */
    public static function marketId(Wave $wave, string $code): int
    {
        return (int) WaveMarket::withoutGlobalScopes()->where('wave_id', $wave->id)->where('code', $code)->value('id');
    }

    /** The ulid of the wave's market with this short code (tests and seeders). */
    public static function marketUlid(Wave $wave, string $code): string
    {
        return (string) WaveMarket::withoutGlobalScopes()->where('wave_id', $wave->id)->where('code', $code)->value('ulid');
    }

    /**
     * Create market rows and country rows for a wave (tests and seeders). No rule is checked here: the database keys are.
     *
     * @param  list<array{name: string, code?: string, countries: list<string>}>  $specs
     * @return list<WaveMarket>
     */
    public static function attachMarkets(Wave $wave, array $specs): array
    {
        $made = [];
        $position = 1 + (int) WaveMarket::withoutGlobalScopes()->where('wave_id', $wave->id)->max('position');
        foreach ($specs as $spec) {
            $market = WaveMarket::query()->create([
                'agency_id' => $wave->agency_id,
                'wave_id' => $wave->id,
                'name' => $spec['name'],
                'code' => $spec['code'] ?? (count($spec['countries']) === 1 ? $spec['countries'][0] : strtoupper(substr($spec['name'], 0, 3))),
                'position' => $position++,
            ]);
            foreach ($spec['countries'] as $country) {
                WaveMarketCountry::query()->create(['agency_id' => $wave->agency_id, 'wave_id' => $wave->id, 'market_id' => $market->id, 'country' => $country]);
            }
            $made[] = $market;
        }
        $wave->unsetRelation('markets');

        return $made;
    }

    public function forBrand(int $agencyId, int $brandId): static
    {
        return $this->state(fn (array $attributes): array => [
            'agency_id' => $agencyId,
            'brand_id' => $brandId,
        ]);
    }

    public function linkedTo(int $campaignId): static
    {
        return $this->state(fn (array $attributes): array => ['campaign_id' => $campaignId]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function newInstance(array $arguments = []): static
    {
        $instance = parent::newInstance($arguments);
        $instance->marketSpecs = $this->marketSpecs;

        return $instance;
    }
}
