<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Database\Factories;

use App\Modules\Identity\Database\Factories\UserFactory;
use App\Modules\Notifications\Models\PushDelivery;
use App\Modules\Notifications\Push\PushOutcome;
use App\Modules\Notifications\Push\PushPlatform;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * The default state is a `delivered` first attempt for a real creator.
 * `user_push_token_id` is left null (the column has no FK; the device row may
 * be long gone) unless a test sets it.
 *
 * @extends Factory<PushDelivery>
 */
final class PushDeliveryFactory extends Factory
{
    protected $model = PushDelivery::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => UserFactory::new()->creator(),
            'user_push_token_id' => null,
            'platform' => PushPlatform::Android,
            'notification_type' => 'assignment.invited',
            'notification_ulid' => null,
            'outcome' => PushOutcome::Delivered,
            'attempt' => 1,
            'final' => false,
            'code' => null,
            'http_status' => null,
            'detail' => null,
            'created_at' => now(),
        ];
    }

    public function outcome(PushOutcome $outcome, ?string $code = null, ?int $httpStatus = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'outcome' => $outcome,
            'code' => $code,
            'http_status' => $httpStatus,
            'detail' => $outcome === PushOutcome::Delivered ? null : 'provider said no',
        ]);
    }

    public function final(): static
    {
        return $this->state(fn (array $attributes): array => ['final' => true]);
    }
}
