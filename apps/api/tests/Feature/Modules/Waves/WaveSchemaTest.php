<?php

declare(strict_types=1);

use App\Modules\Brands\Models\BrandFile;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Waves\Database\Factories\WaveFactory;
use App\Modules\Waves\Models\Wave;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * PL-1.1 — B11 (schema) and the database half of B5 (one wave per campaign, held by the partial unique index,
 * bypassing every service).
 */

it('B11: creates both tables with the planned columns', function (): void {
    expect(Schema::hasColumns('waves', [
        'id', 'ulid', 'agency_id', 'brand_id', 'campaign_id', 'name', 'go_live_on', 'status',
        'created_by_user_id', 'created_at', 'updated_at', 'deleted_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('brand_files', [
            'id', 'ulid', 'agency_id', 'brand_id', 'original_filename', 'storage_key', 'mime_type', 'size_bytes',
            'file_type', 'uploaded_by_user_id', 'created_at', 'updated_at', 'deleted_at',
        ]))->toBeTrue();
});

it('B11 (PL-2.4): the waves table has no markets column any more; a market is a row (wave_markets)', function (): void {
    expect(Schema::hasColumn('waves', 'markets'))->toBeFalse()
        ->and(Schema::hasColumns('wave_markets', ['id', 'ulid', 'agency_id', 'wave_id', 'name', 'code', 'position', 'created_at', 'updated_at']))->toBeTrue()
        ->and(Schema::hasColumns('wave_market_countries', ['id', 'agency_id', 'wave_id', 'market_id', 'country', 'created_at']))->toBeTrue();
});

it('B11: a new wave defaults to draft and carries its markets as rows and the date as Y-m-d', function (): void {
    $wave = Wave::factory()->withMarkets(WaveFactory::singleCountryMarkets(['SK', 'RO']))->create(['go_live_on' => '2026-03-09']);
    $fresh = Wave::query()->findOrFail($wave->id);

    expect($fresh->status->value)->toBe('draft')
        ->and($fresh->markets->pluck('code')->all())->toBe(['SK', 'RO'])
        ->and($fresh->go_live_on->format('Y-m-d'))->toBe('2026-03-09')
        ->and($fresh->ulid)->toHaveLength(26);
});

it('B11: brand_files.storage_key is unique', function (): void {
    $file = BrandFile::factory()->create();

    expect(fn () => BrandFile::factory()->forBrand($file->agency_id, $file->brand_id)->create(['storage_key' => $file->storage_key]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('B11: the foreign keys are restrictive: a brand with a wave cannot be hard-deleted', function (): void {
    $wave = Wave::factory()->create();

    expect(fn () => DB::table('brands')->where('id', $wave->brand_id)->delete())->toThrow(QueryException::class);
});

it('B5: two direct inserts with the same campaign violate the partial unique index', function (): void {
    $campaign = Campaign::factory()->create();
    Wave::factory()->forBrand($campaign->agency_id, $campaign->brand_id)->linkedTo($campaign->id)->create();

    expect(fn () => Wave::factory()->forBrand($campaign->agency_id, $campaign->brand_id)->linkedTo($campaign->id)->create())
        ->toThrow(UniqueConstraintViolationException::class);
});

it('B5: any number of waves with no campaign are fine', function (): void {
    $first = Wave::factory()->create();
    Wave::factory()->forBrand($first->agency_id, $first->brand_id)->create();
    Wave::factory()->forBrand($first->agency_id, $first->brand_id)->create();

    expect(Wave::query()->whereNull('campaign_id')->count())->toBe(3);
});

it('B5: a soft-deleted wave frees its campaign', function (): void {
    $campaign = Campaign::factory()->create();
    $first = Wave::factory()->forBrand($campaign->agency_id, $campaign->brand_id)->linkedTo($campaign->id)->create();
    $first->delete();

    $second = Wave::factory()->forBrand($campaign->agency_id, $campaign->brand_id)->linkedTo($campaign->id)->create();

    expect($second->campaign_id)->toBe($campaign->id);
});
