<?php

declare(strict_types=1);

namespace App\Modules\Brands\Database\Factories;

use App\Modules\Agencies\Database\Factories\AgencyFactory;
use App\Modules\Brands\Enums\BrandStatus;
use App\Modules\Brands\Models\Brand;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Brand>
 */
final class BrandFactory extends Factory
{
    protected $model = Brand::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'agency_id' => AgencyFactory::new(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->randomNumber(5),
            'description' => fake()->optional()->sentence(),
            'industry' => fake()->optional()->randomElement(['fashion', 'beauty', 'tech', 'food', 'travel']),
            'website_url' => fake()->optional()->url(),
            'logo_path' => null,
            'default_currency' => 'EUR',
            'default_language' => 'en',
            'status' => BrandStatus::Active,
            'brand_safety_rules' => null,
            'exclusivity_window_days' => null,
            'client_portal_enabled' => false,
        ];
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => BrandStatus::Archived,
        ]);
    }

    public function forAgency(int $agencyId): static
    {
        return $this->state(fn (array $attributes): array => [
            'agency_id' => $agencyId,
        ]);
    }
}
