<?php

declare(strict_types=1);

namespace App\Modules\TrackedJobs\Database\Factories;

use App\Modules\TrackedJobs\Enums\TrackedJobStatus;
use App\Modules\TrackedJobs\Models\TrackedJob;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrackedJob>
 */
final class TrackedJobFactory extends Factory
{
    protected $model = TrackedJob::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kind' => 'test_job',
            'status' => TrackedJobStatus::Queued,
            'progress' => 0.0,
        ];
    }

    public function processing(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => TrackedJobStatus::Processing,
            'progress' => 0.5,
            'started_at' => now()->subMinute(),
        ]);
    }

    public function complete(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => TrackedJobStatus::Complete,
            'progress' => 1.0,
            'started_at' => now()->subMinutes(2),
            'completed_at' => now(),
        ]);
    }
}
