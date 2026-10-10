<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Database\Factories;

use App\Modules\Identity\Database\Factories\UserFactory;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuthService;
use App\Modules\Notifications\Models\UserPushToken;
use App\Modules\Notifications\Push\PushPlatform;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * The default state is a LIVE row: a real creator, a real unexpired personal
 * access token owned by that same creator, not invalidated. Tests that need a
 * dead one use the named states or override a single attribute.
 *
 * @extends Factory<UserPushToken>
 */
final class UserPushTokenFactory extends Factory
{
    protected $model = UserPushToken::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => UserFactory::new()->creator(),
            'personal_access_token_id' => fn (array $attributes): int => $this->tokenFor((int) $attributes['user_id']),
            'platform' => PushPlatform::Android,
            'token' => 'fcm-'.fake()->unique()->regexify('[A-Za-z0-9_-]{140}'),
            'app_version' => '1.0.0',
            'last_seen_at' => now(),
            'invalidated_at' => null,
            'invalidation_reason' => null,
        ];
    }

    public function ios(): static
    {
        return $this->state(fn (array $attributes): array => [
            'platform' => PushPlatform::Ios,
        ]);
    }

    public function invalidated(string $reason = 'unregistered'): static
    {
        return $this->state(fn (array $attributes): array => [
            'invalidated_at' => now(),
            'invalidation_reason' => $reason,
        ]);
    }

    private function tokenFor(int $userId): int
    {
        $user = User::query()->findOrFail($userId);

        return $user->createToken(AuthService::TOKEN_NAME_PREFIX.'android', ['*'])->accessToken->getKey();
    }
}
