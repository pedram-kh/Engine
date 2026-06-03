<?php

declare(strict_types=1);

namespace App\Modules\TalentPools\Database\Factories;

use App\Modules\Agencies\Database\Factories\AgencyFactory;
use App\Modules\TalentPools\Models\TalentPool;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TalentPool>
 */
final class TalentPoolFactory extends Factory
{
    protected $model = TalentPool::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agency_id' => AgencyFactory::new(),
            'brand_id' => null,
            'name' => fake()->unique()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'created_by_user_id' => null,
        ];
    }

    public function forAgency(int $agencyId): static
    {
        return $this->state(fn (array $attributes): array => [
            'agency_id' => $agencyId,
        ]);
    }

    public function forBrand(int $brandId): static
    {
        return $this->state(fn (array $attributes): array => [
            'brand_id' => $brandId,
        ]);
    }
}
