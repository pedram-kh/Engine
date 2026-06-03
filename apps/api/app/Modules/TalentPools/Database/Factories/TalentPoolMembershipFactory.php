<?php

declare(strict_types=1);

namespace App\Modules\TalentPools\Database\Factories;

use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\TalentPools\Models\TalentPoolMembership;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TalentPoolMembership>
 */
final class TalentPoolMembershipFactory extends Factory
{
    protected $model = TalentPoolMembership::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'talent_pool_id' => TalentPoolFactory::new(),
            'creator_id' => CreatorFactory::new(),
            'added_by_user_id' => null,
        ];
    }
}
