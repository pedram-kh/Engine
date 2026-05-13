<?php

declare(strict_types=1);

namespace App\Modules\Creators\Database\Factories;

use App\Modules\Creators\Enums\PortfolioItemKind;
use App\Modules\Creators\Models\CreatorPortfolioItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreatorPortfolioItem>
 */
final class CreatorPortfolioItemFactory extends Factory
{
    protected $model = CreatorPortfolioItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'creator_id' => CreatorFactory::new(),
            'kind' => PortfolioItemKind::Image,
            'title' => fake()->optional()->sentence(),
            'description' => fake()->optional()->paragraph(),
            's3_path' => 'creators/test/portfolio/'.fake()->uuid().'.jpg',
            'external_url' => null,
            'thumbnail_path' => null,
            'mime_type' => 'image/jpeg',
            'size_bytes' => fake()->numberBetween(10_000, 5_000_000),
            'duration_seconds' => null,
            'position' => fake()->numberBetween(1, 10),
        ];
    }

    public function video(): static
    {
        return $this->state(fn (array $attributes): array => [
            'kind' => PortfolioItemKind::Video,
            's3_path' => 'creators/test/portfolio/'.fake()->uuid().'.mp4',
            'mime_type' => 'video/mp4',
            'thumbnail_path' => 'creators/test/portfolio/thumbs/'.fake()->uuid().'.jpg',
            'duration_seconds' => fake()->numberBetween(10, 300),
        ]);
    }

    public function link(): static
    {
        return $this->state(fn (array $attributes): array => [
            'kind' => PortfolioItemKind::Link,
            's3_path' => null,
            'external_url' => fake()->url(),
            'mime_type' => null,
            'size_bytes' => null,
        ]);
    }

    public function atPosition(int $position): static
    {
        return $this->state(fn (array $attributes): array => [
            'position' => $position,
        ]);
    }
}
