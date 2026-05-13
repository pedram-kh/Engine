<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Database\Factories;

use App\Modules\Agencies\Enums\AgencyRole;
use App\Modules\Agencies\Models\AgencyUserInvitation;
use App\Modules\Identity\Database\Factories\UserFactory;
use App\Modules\Identity\Enums\UserType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AgencyUserInvitation>
 */
final class AgencyUserInvitationFactory extends Factory
{
    protected $model = AgencyUserInvitation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $token = Str::random(64);

        return [
            'agency_id' => AgencyFactory::new(),
            'email' => fake()->unique()->safeEmail(),
            'role' => AgencyRole::AgencyStaff,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(7),
            'accepted_at' => null,
            'accepted_by_user_id' => null,
            'invited_by_user_id' => UserFactory::new()->state(['type' => UserType::AgencyUser]),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'expires_at' => now()->subDay(),
        ]);
    }

    public function accepted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'accepted_at' => now(),
        ]);
    }

    public function forRole(AgencyRole $role): static
    {
        return $this->state(fn (array $attributes): array => [
            'role' => $role,
        ]);
    }
}
