<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Brands\Models\Brand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * AH-053 D9 — `brands:audit-floor`. The command is the deliverable; the number
 * comes from production before deploy. These pins keep the OUTPUT SHAPE stable
 * so the recorded figure stays comparable, and keep the command honest about
 * being read-only.
 */
it('reports zero when every brand satisfies the floor', function (): void {
    $agency = Agency::factory()->createOne();
    Brand::factory()->forAgency($agency->id)->count(3)->create();

    $this->artisan('brands:audit-floor')
        ->expectsOutputToContain('AH-053 D6 brand-floor audit (READ-ONLY, no writes).')
        ->expectsOutputToContain('0 of 3 brand(s) across 0 agencies fail the floor.')
        ->assertSuccessful();
});

it('counts each floor field separately and totals the blocked brands', function (): void {
    $agency = Agency::factory()->createOne();

    // One brand missing everything but name+slug, one missing only the logo,
    // one complete. The per-field rows are a breakdown, not a partition: the
    // incomplete brand appears in four rows.
    Brand::factory()->forAgency($agency->id)->incomplete()->createOne();
    Brand::factory()->forAgency($agency->id)->missingFloorField('logo_path')->createOne();
    Brand::factory()->forAgency($agency->id)->createOne();

    $this->artisan('brands:audit-floor')
        ->expectsOutputToContain('name             0')
        ->expectsOutputToContain('slug             0')
        ->expectsOutputToContain('description      1')
        ->expectsOutputToContain('industry         1')
        ->expectsOutputToContain('website_url      1')
        ->expectsOutputToContain('logo_path        2')
        ->expectsOutputToContain('2 of 3 brand(s) across 1 agency fail the floor.')
        ->assertSuccessful();
});

it('counts platform-wide across agencies, ignoring tenancy scope', function (): void {
    $first = Agency::factory()->createOne();
    $second = Agency::factory()->createOne();

    Brand::factory()->forAgency($first->id)->incomplete()->createOne();
    Brand::factory()->forAgency($second->id)->incomplete()->createOne();

    $this->artisan('brands:audit-floor')
        ->expectsOutputToContain('2 of 2 brand(s) across 2 agencies fail the floor.')
        ->assertSuccessful();
});

it('splits blocked brands by lifecycle, including archived and soft-deleted', function (): void {
    $agency = Agency::factory()->createOne();

    Brand::factory()->forAgency($agency->id)->incomplete()->createOne();
    Brand::factory()->forAgency($agency->id)->incomplete()->archived()->createOne();
    Brand::factory()->forAgency($agency->id)->incomplete()->createOne()->delete();

    $this->artisan('brands:audit-floor')
        ->expectsOutputToContain('active           1')
        ->expectsOutputToContain('archived         1')
        ->expectsOutputToContain('soft-deleted     1')
        ->expectsOutputToContain('3 of 3 brand(s) across 1 agency fail the floor.')
        ->assertSuccessful();
});

it('treats a whitespace-only value as empty, exactly like the gate', function (): void {
    $agency = Agency::factory()->createOne();
    Brand::factory()->forAgency($agency->id)->createOne(['industry' => '   ']);

    $this->artisan('brands:audit-floor')
        ->expectsOutputToContain('industry         1')
        ->expectsOutputToContain('1 of 1 brand(s) across 1 agency fail the floor.')
        ->assertSuccessful();
});

it('writes nothing — the audit is a pure read', function (): void {
    $agency = Agency::factory()->createOne();
    $brand = Brand::factory()->forAgency($agency->id)->incomplete()->createOne();
    $before = $brand->only(['name', 'slug', 'description', 'industry', 'website_url', 'logo_path', 'updated_at']);

    $this->artisan('brands:audit-floor')->assertSuccessful();

    expect($brand->fresh()?->only(array_keys($before)))->toEqual($before);
});
