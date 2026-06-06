<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Database\Factories;

use App\Modules\Identity\Database\Factories\UserFactory;
use App\Modules\Messaging\Enums\MessageKind;
use App\Modules\Messaging\Enums\MessageSenderRole;
use App\Modules\Messaging\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Message>
 */
final class MessageFactory extends Factory
{
    protected $model = Message::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ulid' => (string) Str::ulid(),
            'thread_id' => MessageThreadFactory::new(),
            'sender_user_id' => UserFactory::new(),
            'sender_role' => MessageSenderRole::AgencyUser,
            'kind' => MessageKind::Text,
            'body' => fake()->sentence(),
            'attachments' => null,
            'system_event_key' => null,
        ];
    }

    /**
     * A system message — no human sender (D-2), keyed by the AuditAction verb.
     */
    public function system(string $eventKey): static
    {
        return $this->state(fn (array $attributes): array => [
            'sender_user_id' => null,
            'sender_role' => MessageSenderRole::System,
            'kind' => MessageKind::System,
            'body' => null,
            'system_event_key' => $eventKey,
        ]);
    }

    public function fromCreator(): static
    {
        return $this->state(fn (array $attributes): array => [
            'sender_role' => MessageSenderRole::Creator,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $attachments
     */
    public function attachmentOnly(array $attachments): static
    {
        return $this->state(fn (array $attributes): array => [
            'kind' => MessageKind::AttachmentOnly,
            'body' => null,
            'attachments' => $attachments,
        ]);
    }
}
