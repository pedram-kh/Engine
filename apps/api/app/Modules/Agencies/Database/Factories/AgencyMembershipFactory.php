<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Database\Factories;

use App\Modules\Agencies\Enums\AgencyRole;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyMembership;
use App\Modules\Identity\Database\Factories\UserFactory;
use App\Modules\Identity\Enums\UserType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgencyMembership>
 */
final class AgencyMembershipFactory extends Factory
{
    protected $model = AgencyMembership::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agency_id' => AgencyFactory::new(),
            'user_id' => UserFactory::new()->state(['type' => UserType::AgencyUser]),
            'role' => AgencyRole::AgencyStaff,
            'accepted_at' => now(),
        ];
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes): array => [
            'role' => AgencyRole::AgencyAdmin,
        ]);
    }

    public function manager(): static
    {
        return $this->state(fn (array $attributes): array => [
            'role' => AgencyRole::AgencyManager,
        ]);
    }

    public function pendingInvitation(): static
    {
        return $this->state(fn (array $attributes): array => [
            'invited_at' => now(),
            'accepted_at' => null,
        ]);
    }

    public function forAgency(Agency $agency): static
    {
        return $this->state(fn (array $attributes): array => [
            'agency_id' => $agency->id,
        ]);
    }
}
