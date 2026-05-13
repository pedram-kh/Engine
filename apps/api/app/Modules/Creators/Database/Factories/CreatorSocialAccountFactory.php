<?php

declare(strict_types=1);

namespace App\Modules\Creators\Database\Factories;

use App\Modules\Creators\Enums\SocialPlatform;
use App\Modules\Creators\Models\CreatorSocialAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreatorSocialAccount>
 */
final class CreatorSocialAccountFactory extends Factory
{
    protected $model = CreatorSocialAccount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'creator_id' => CreatorFactory::new(),
            'platform' => SocialPlatform::Instagram,
            'platform_user_id' => fake()->unique()->numerify('1#######'),
            'handle' => fake()->unique()->userName(),
            'profile_url' => 'https://instagram.com/'.fake()->userName(),
            'oauth_access_token' => null,
            'oauth_refresh_token' => null,
            'oauth_expires_at' => null,
            'last_synced_at' => null,
            'sync_status' => 'pending',
            'metrics' => null,
            'audience_demographics' => null,
            'is_primary' => false,
        ];
    }

    public function primary(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_primary' => true,
        ]);
    }

    public function platform(SocialPlatform $platform): static
    {
        return $this->state(fn (array $attributes): array => [
            'platform' => $platform,
            'profile_url' => match ($platform) {
                SocialPlatform::Instagram => 'https://instagram.com/'.fake()->userName(),
                SocialPlatform::TikTok => 'https://tiktok.com/@'.fake()->userName(),
                SocialPlatform::YouTube => 'https://youtube.com/@'.fake()->userName(),
            },
        ]);
    }
}
