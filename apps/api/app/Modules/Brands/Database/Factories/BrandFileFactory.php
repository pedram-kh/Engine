<?php

declare(strict_types=1);

namespace App\Modules\Brands\Database\Factories;

use App\Modules\Agencies\Database\Factories\AgencyFactory;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Brands\Enums\BrandFileType;
use App\Modules\Brands\Models\Brand;
use App\Modules\Brands\Models\BrandFile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BrandFile>
 */
final class BrandFileFactory extends Factory
{
    protected $model = BrandFile::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agency_id' => AgencyFactory::new(),
            'brand_id' => fn (array $attributes) => BrandFactory::new()->forAgency((int) $attributes['agency_id']),
            'original_filename' => fake()->word().'.pdf',
            // Resolved in configure() from the real agency and brand ulids; an explicit override wins.
            'storage_key' => null,
            'mime_type' => 'application/pdf',
            'size_bytes' => fake()->numberBetween(1024, 5 * 1024 * 1024),
            'file_type' => BrandFileType::Other,
            'uploaded_by_user_id' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (BrandFile $file): void {
            if ($file->storage_key !== null) {
                return;
            }

            $agency = Agency::query()->find($file->agency_id);
            $brand = Brand::query()->withoutGlobalScopes()->withTrashed()->find($file->brand_id);
            $agencyUlid = $agency !== null ? $agency->ulid : (string) Str::ulid();
            $brandUlid = $brand !== null ? $brand->ulid : (string) Str::ulid();
            $file->storage_key = "agencies/{$agencyUlid}/brands/{$brandUlid}/files/".Str::ulid().'.pdf';
        });
    }

    public function forBrand(int $agencyId, int $brandId): static
    {
        return $this->state(fn (array $attributes): array => [
            'agency_id' => $agencyId,
            'brand_id' => $brandId,
        ]);
    }
}
