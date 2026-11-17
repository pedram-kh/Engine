<?php

declare(strict_types=1);

use App\Modules\Agencies\Database\Factories\AgencyFactory;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Brands\Models\Brand;
use App\Modules\Brands\Models\BrandFile;
use App\Modules\Identity\Models\User;
use App\Modules\Waves\Features\WavesEnabled;
use App\Modules\Waves\Models\Wave;
use Database\Seeders\Pl11WalkCleanupSeeder;
use Database\Seeders\Pl11WalkSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('S1: seeds the two walk agencies, leaves the flag off, and is idempotent', function (): void {
    $this->seed(Pl11WalkSeeder::class);

    $a = Agency::query()->where('slug', Pl11WalkSeeder::AGENCY_A_SLUG)->firstOrFail();
    $bolt = Brand::query()->where('agency_id', $a->id)->where('name', 'Bolt Food')->firstOrFail();
    expect(Brand::query()->where('agency_id', $a->id)->count())->toBe(3)
        ->and(Wave::query()->where('agency_id', $a->id)->pluck('name')->sort()->values()->all())->toBe(['Wave 5', 'Wave 6'])
        ->and(Wave::query()->where('brand_id', $bolt->id)->whereNotNull('campaign_id')->count())->toBe(1)
        ->and(User::query()->where('email', 'like', '%'.Pl11WalkSeeder::DOMAIN)->count())->toBe(4)
        ->and(Feature::active(WavesEnabled::NAME))->toBeFalse();

    $this->seed(Pl11WalkSeeder::class);
    expect(Agency::query()->whereIn('slug', [Pl11WalkSeeder::AGENCY_A_SLUG, Pl11WalkSeeder::AGENCY_B_SLUG])->count())->toBe(2)
        ->and(Wave::query()->count())->toBe(3);
});

it('S2: cleanup removes everything the walk made, leaves a bystander alone, and does not touch the flag', function (): void {
    $bystander = AgencyFactory::new()->createOne();
    $bystanderBrand = Brand::factory()->forAgency($bystander->id)->create();
    Wave::factory()->forBrand($bystander->id, $bystanderBrand->id)->create();

    $this->seed(Pl11WalkSeeder::class);
    $a = Agency::query()->where('slug', Pl11WalkSeeder::AGENCY_A_SLUG)->firstOrFail();
    $bolt = Brand::query()->where('agency_id', $a->id)->where('name', 'Bolt Food')->firstOrFail();
    BrandFile::factory()->forBrand($a->id, $bolt->id)->create();

    Feature::activate(WavesEnabled::NAME);
    $this->seed(Pl11WalkCleanupSeeder::class);

    expect(Agency::query()->whereIn('slug', [Pl11WalkSeeder::AGENCY_A_SLUG, Pl11WalkSeeder::AGENCY_B_SLUG])->count())->toBe(0)
        ->and(User::query()->where('email', 'like', '%'.Pl11WalkSeeder::DOMAIN)->count())->toBe(0)
        ->and(Wave::query()->where('agency_id', $bystander->id)->count())->toBe(1)
        ->and(Wave::query()->count())->toBe(1)
        ->and(BrandFile::query()->count())->toBe(0)
        ->and(Brand::query()->where('agency_id', $bystander->id)->count())->toBe(1)
        // The stored flag row is still there (read it from the table: the in-memory cache would hide a delete).
        ->and(DB::table('features')->where('name', WavesEnabled::NAME)->where('value', 'true')->exists())->toBeTrue();

    // Safe twice, and safe when the seeder never ran.
    $this->seed(Pl11WalkCleanupSeeder::class);
    expect(Wave::query()->count())->toBe(1);
});

it('S3: both seeders refuse to run outside local and testing', function (): void {
    app()->instance('env', 'production');

    expect(fn () => app(Pl11WalkSeeder::class)->run())->toThrow(RuntimeException::class)
        ->and(fn () => app(Pl11WalkCleanupSeeder::class)->run())->toThrow(RuntimeException::class);
});
