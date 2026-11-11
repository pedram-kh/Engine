<?php

declare(strict_types=1);

use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Models\Campaign;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * AH-120 A1 — the additive schema, pinned before anything reads it. Neither
 * object may change what an existing campaign means.
 */
it('adds campaigns.exclusivity_ends_on as a nullable date that an existing row leaves NULL', function (): void {
    expect(Schema::hasColumn('campaigns', 'exclusivity_ends_on'))->toBeTrue();

    $campaign = Campaign::factory()->createOne();
    expect($campaign->fresh()?->exclusivity_ends_on)->toBeNull();

    $dated = Campaign::factory()->blocking([], '2027-03-31')->createOne();
    expect($dated->fresh()?->exclusivity_ends_on?->toDateString())->toBe('2027-03-31');
});

it('creates campaign_exclusive_brands with an empty list for every existing campaign', function (): void {
    expect(Schema::hasTable('campaign_exclusive_brands'))->toBeTrue()
        ->and(Schema::hasColumns('campaign_exclusive_brands', ['id', 'campaign_id', 'brand_id', 'created_at', 'updated_at']))->toBeTrue();

    $campaign = Campaign::factory()->createOne();
    expect($campaign->exclusiveBrands()->count())->toBe(0);
});

it('lists a brand once per campaign and reads it back, archived brands included', function (): void {
    $campaign = Campaign::factory()->createOne();
    $brand = Brand::factory()->createOne(['agency_id' => $campaign->agency_id]);

    $campaign->exclusiveBrands()->attach($brand->id);
    expect($campaign->exclusiveBrands()->pluck('brands.id')->all())->toBe([$brand->id]);

    // The unique pair is the guard, not the code that writes it.
    expect(fn () => DB::table('campaign_exclusive_brands')->insert([
        'campaign_id' => $campaign->id,
        'brand_id' => $brand->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    // An archived brand stays on the list: the block does not lift on archive.
    $brand->delete();
    expect($campaign->fresh()?->exclusiveBrands()->pluck('brands.id')->all())->toBe([$brand->id]);
});

it('removes the list with its campaign and refuses to hard-delete a listed brand', function (): void {
    $campaign = Campaign::factory()->createOne();
    $brand = Brand::factory()->createOne(['agency_id' => $campaign->agency_id]);
    $campaign->exclusiveBrands()->attach($brand->id);

    // Cascade from the campaign side (forceDelete: soft-delete keeps the row).
    expect(fn () => $brand->forceDelete())->toThrow(QueryException::class);

    $campaign->forceDelete();
    expect(DB::table('campaign_exclusive_brands')->count())->toBe(0);
});
