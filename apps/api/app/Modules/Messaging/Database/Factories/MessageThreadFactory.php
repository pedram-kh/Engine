<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Database\Factories;

use App\Modules\Campaigns\Database\Factories\CampaignAssignmentFactory;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Messaging\Models\MessageThread;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MessageThread>
 */
final class MessageThreadFactory extends Factory
{
    protected $model = MessageThread::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ulid' => (string) Str::ulid(),
            'assignment_id' => CampaignAssignmentFactory::new(),
            // agency_id is denormalized from the parent assignment.
            'agency_id' => fn (array $attributes) => CampaignAssignment::withoutGlobalScopes()
                ->whereKey($attributes['assignment_id'])
                ->value('agency_id'),
            'last_message_at' => null,
        ];
    }

    public function forAssignment(CampaignAssignment $assignment): static
    {
        return $this->state(fn (array $attributes): array => [
            'assignment_id' => $assignment->id,
            'agency_id' => $assignment->agency_id,
        ]);
    }
}
