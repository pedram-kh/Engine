<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Agencies\Models\Agency;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Identity\Models\User;
use App\Modules\Waves\Features\WavesEnabled;
use Laravel\Pennant\Feature;

/**
 * PL-1.1 — shared fixtures for the wave and brand-file specs: one agency with an admin, a manager and a staff
 * user and a brand, plus a second, unrelated agency. The flag is armed by `armed()`.
 */
final class WaveFixtures
{
    /**
     * @return array{agency: Agency, brand: Brand, admin: User, manager: User, staff: User, otherAgency: Agency, otherBrand: Brand, otherAdmin: User}
     */
    public static function world(): array
    {
        $agency = Agency::factory()->createOne();
        $brand = Brand::factory()->forAgency($agency->id)->createOne();
        $other = Agency::factory()->createOne();

        return [
            'agency' => $agency,
            'brand' => $brand,
            'admin' => User::factory()->agencyAdmin($agency)->createOne(),
            'manager' => User::factory()->agencyManager($agency)->createOne(),
            'staff' => User::factory()->agencyStaff($agency)->createOne(),
            'otherAgency' => $other,
            'otherBrand' => Brand::factory()->forAgency($other->id)->createOne(),
            'otherAdmin' => User::factory()->agencyAdmin($other)->createOne(),
        ];
    }

    public static function armed(): void
    {
        Feature::activate(WavesEnabled::NAME);
    }

    public static function campaign(Agency $agency, Brand $brand, string $status = 'draft', string $name = ''): Campaign
    {
        return Campaign::factory()->createOne(array_filter([
            'agency_id' => $agency->id,
            'brand_id' => $brand->id,
            'status' => $status,
            'name' => $name !== '' ? $name : null,
        ]));
    }

    public static function wavesUrl(Agency $agency, Brand $brand, string $suffix = ''): string
    {
        return "/api/v1/agencies/{$agency->ulid}/brands/{$brand->ulid}/waves".($suffix !== '' ? '/'.$suffix : '');
    }

    /** @return array<string, mixed> */
    public static function body(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Wave 7',
            'go_live_on' => '2026-12-01',
            'markets' => ['SK', 'RO'],
        ], $overrides);
    }
}
