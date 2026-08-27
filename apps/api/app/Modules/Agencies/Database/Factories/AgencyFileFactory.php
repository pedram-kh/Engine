<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Database\Factories;

use App\Modules\Agencies\Enums\AgencyFileCategory;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyFile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AgencyFile>
 */
final class AgencyFileFactory extends Factory
{
    protected $model = AgencyFile::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agency_id' => AgencyFactory::new(),
            'original_filename' => fake()->word().'.pdf',
            // Resolved in configure()'s afterMaking(), using the REAL agency's
            // ulid — null here is never persisted (afterMaking runs first),
            // but marks "not yet computed" so an explicit ->create([
            // 'storage_key' => ...]) override always wins.
            'storage_key' => null,
            'mime_type' => 'application/pdf',
            'size_bytes' => fake()->numberBetween(1024, 25 * 1024 * 1024),
            'category' => AgencyFileCategory::CampaignAttachment,
            'uploaded_by_user_id' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (AgencyFile $file): void {
            if ($file->storage_key !== null) {
                return;
            }

            $agency = Agency::query()->find($file->agency_id);
            $agencyUlid = $agency !== null ? $agency->ulid : (string) Str::ulid();
            $file->storage_key = "agencies/{$agencyUlid}/files/".Str::ulid().'.pdf';
        });
    }

    public function forAgency(int $agencyId): static
    {
        return $this->state(fn (array $attributes): array => [
            'agency_id' => $agencyId,
        ]);
    }

    public function uploadedBy(int $userId): static
    {
        return $this->state(fn (array $attributes): array => [
            'uploaded_by_user_id' => $userId,
        ]);
    }
}
