<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Database\Factories;

use App\Modules\Erasure\Enums\ErasureLedgerState;
use App\Modules\Erasure\Models\ErasureLedger;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ErasureLedger>
 */
final class ErasureLedgerFactory extends Factory
{
    protected $model = ErasureLedger::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_ulid' => (string) Str::ulid(),
            'state' => ErasureLedgerState::Started,
            'counts' => null,
            'digest' => null,
            'run_by_user_id' => null,
            'started_at' => now(),
            'completed_at' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'state' => ErasureLedgerState::Completed,
            'completed_at' => now(),
        ]);
    }
}
