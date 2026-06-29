<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Database\Factories;

use App\Modules\Agencies\Database\Factories\AgencyFactory;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Models\Creator;
use App\Modules\Messaging\Models\RelationshipThread;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RelationshipThread>
 */
final class RelationshipThreadFactory extends Factory
{
    protected $model = RelationshipThread::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ulid' => (string) Str::ulid(),
            'agency_id' => AgencyFactory::new(),
            'creator_id' => CreatorFactory::new(),
            'last_message_at' => null,
        ];
    }

    public function forPair(Agency $agency, Creator $creator): static
    {
        return $this->state(fn (array $attributes): array => [
            'agency_id' => $agency->id,
            'creator_id' => $creator->id,
        ]);
    }
}
