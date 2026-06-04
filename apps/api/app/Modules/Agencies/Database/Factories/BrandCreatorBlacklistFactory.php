<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Database\Factories;

use App\Modules\Agencies\Enums\BlacklistType;
use App\Modules\Agencies\Models\BrandCreatorBlacklist;
use App\Modules\Brands\Database\Factories\BrandFactory;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BrandCreatorBlacklist>
 */
final class BrandCreatorBlacklistFactory extends Factory
{
    protected $model = BrandCreatorBlacklist::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'brand_id' => BrandFactory::new(),
            'creator_id' => CreatorFactory::new(),
            'blacklist_type' => BlacklistType::Hard,
            'reason' => 'Test brand-scoped blacklist reason',
            'blacklisted_at' => now(),
        ];
    }

    public function soft(): static
    {
        return $this->state(fn (array $attributes): array => [
            'blacklist_type' => BlacklistType::Soft,
        ]);
    }
}
