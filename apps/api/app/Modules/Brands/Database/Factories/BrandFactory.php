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
     * FLOOR-COMPLETE and DETERMINISTIC by default (AH-053, F1).
     *
     * Before D6 this factory used `fake()->optional()` for description /
     * industry / website_url and left `logo_path` null — harmless while every
     * field was optional, actively dangerous once the floor gate exists: a
     * PATCH test unrelated to brands would pass or fail depending on what the
     * faker rolled that run. A randomly-red suite gets "fixed" by weakening
     * the gate, which is precisely the outcome D6 exists to prevent. So the
     * default brand now satisfies the floor, every time.
     *
     * Use {@see incomplete()} to build the pre-D6 shape a hard-block test
     * needs, and {@see missingFloorField()} to isolate one field.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();
        $slug = Str::slug($name).'-'.fake()->unique()->randomNumber(5);

        return [
            'agency_id' => AgencyFactory::new(),
            'name' => $name,
            'slug' => $slug,
            'description' => 'Monthly deliverables: 2 Reels and 3 Stories.',
            'industry' => 'fashion',
            'website_url' => 'https://'.$slug.'.example.com',
            'logo_path' => 'agencies/seed/brands/'.$slug.'/logo/seed.webp',
            'default_currency' => 'EUR',
            'default_language' => 'en',
            'status' => BrandStatus::Active,
            'brand_safety_rules' => null,
            'exclusivity_window_days' => null,
            'client_portal_enabled' => false,
        ];
    }

    /**
     * A brand as production has them TODAY, before the floor existed: name and
     * slug only. This is the row the D6 gate hard-blocks on next edit.
     */
    public function incomplete(): static
    {
        return $this->state(fn (array $attributes): array => [
            'description' => null,
            'industry' => null,
            'website_url' => null,
            'logo_path' => null,
        ]);
    }

    /** Floor-complete except for ONE named field — for per-field gate cases. */
    public function missingFloorField(string $field): static
    {
        return $this->state(fn (array $attributes): array => [$field => null]);
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
