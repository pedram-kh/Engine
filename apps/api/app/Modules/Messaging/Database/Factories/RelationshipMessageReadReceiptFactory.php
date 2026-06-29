<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Database\Factories;

use App\Modules\Identity\Database\Factories\UserFactory;
use App\Modules\Messaging\Models\RelationshipMessageReadReceipt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RelationshipMessageReadReceipt>
 */
final class RelationshipMessageReadReceiptFactory extends Factory
{
    protected $model = RelationshipMessageReadReceipt::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'message_id' => RelationshipMessageFactory::new(),
            'user_id' => UserFactory::new(),
            'read_at' => now(),
        ];
    }
}
