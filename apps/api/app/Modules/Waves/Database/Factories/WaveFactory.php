<?php

declare(strict_types=1);

namespace App\Modules\Waves\Database\Factories;

use App\Modules\Agencies\Database\Factories\AgencyFactory;
use App\Modules\Brands\Database\Factories\BrandFactory;
use App\Modules\Identity\Database\Factories\UserFactory;
use App\Modules\Waves\Enums\WaveStatus;
use App\Modules\Waves\Models\Wave;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Wave>
 */
final class WaveFactory extends Factory
{
    protected $model = Wave::class;

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
            'markets' => ['SK'],
            'status' => WaveStatus::Draft,
            'created_by_user_id' => UserFactory::new(),
        ];
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
}
