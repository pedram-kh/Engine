<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Database\Factories;

use App\Modules\Identity\Database\Factories\UserFactory;
use App\Modules\Messaging\Models\MessageEmailDebounce;
use App\Modules\Messaging\Models\MessageThread;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MessageEmailDebounce>
 */
final class MessageEmailDebounceFactory extends Factory
{
    protected $model = MessageEmailDebounce::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'thread_type' => MessageThread::class,
            'thread_id' => MessageThreadFactory::new(),
            'recipient_user_id' => UserFactory::new(),
            'last_emailed_at' => now(),
        ];
    }

    /**
     * @param  class-string  $threadType
     */
    public function forThread(string $threadType, int $threadId): static
    {
        return $this->state(fn (array $attributes): array => [
            'thread_type' => $threadType,
            'thread_id' => $threadId,
        ]);
    }

    public function lastEmailedAt(\DateTimeInterface $when): static
    {
        return $this->state(fn (array $attributes): array => [
            'last_emailed_at' => $when,
        ]);
    }
}
