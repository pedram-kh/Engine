<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Database\Factories;

use App\Modules\Identity\Database\Factories\UserFactory;
use App\Modules\Messaging\Models\MessageReadReceipt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MessageReadReceipt>
 */
final class MessageReadReceiptFactory extends Factory
{
    protected $model = MessageReadReceipt::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'message_id' => MessageFactory::new(),
            'user_id' => UserFactory::new(),
            'read_at' => now(),
        ];
    }
}
