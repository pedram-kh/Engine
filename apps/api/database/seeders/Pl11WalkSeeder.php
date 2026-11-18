<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Agencies\Database\Factories\AgencyFactory;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Enums\CampaignStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Identity\Models\User;
use App\Modules\Waves\Models\Wave;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * PL-1.1 — local data for the waves and client-files walk (plan §10).
 *
 * Agency A "PL-1.1 Walk Agency A": an admin, a manager and a staff user; brands Bolt Food and Glovo
 * (active) and Old Co (archived); campaigns Bolt Autumn Reels (draft), Bolt Winter Launch (active),
 * Bolt Old Promo (cancelled), Bolt Summer (completed, linked to Wave 5) and Glovo Spring (PL-1.2, P2: the
 * Autumn Reels, Winter Launch and Summer are FOR-A-WAVE campaigns, the only kind a wave can link to; the others are normal); waves
 * Wave 5 (SK, linked to Bolt Summer) and Wave 6 (SK RO CZ, no campaign).
 *
 * Agency B "PL-1.1 Walk Agency B": an admin, brand Zeta Foods, campaign Zeta Q4, wave Zeta Wave 1.
 *
 * Every account lives under `@pl11-walk.local`, which is what {@see Pl11WalkCleanupSeeder} removes
 * it by. It does NOT touch the `waves_enabled` flag (it stays at its default, OFF): turn it on and
 * off by hand in the admin app, as the walk says. Additive: it never touches a row it did not make.
 *
 *     php artisan db:seed --class='Database\Seeders\Pl11WalkSeeder'
 *
 * GUARDED to local + testing only. Idempotent: a second run changes nothing. It prints the brand
 * ulids the walk needs.
 */
final class Pl11WalkSeeder extends Seeder
{
    public const string DOMAIN = '@pl11-walk.local';

    public const string AGENCY_A_SLUG = 'pl11-walk-agency-a';

    public const string AGENCY_B_SLUG = 'pl11-walk-agency-b';

    public const string PASSWORD = 'password-12chars';

    public const string ADMIN_EMAIL = 'admin'.self::DOMAIN;

    public const string MANAGER_EMAIL = 'manager'.self::DOMAIN;

    public const string STAFF_EMAIL = 'staff'.self::DOMAIN;

    public const string B_ADMIN_EMAIL = 'admin-b'.self::DOMAIN;

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Pl11WalkSeeder is only allowed in local and testing environments.');
        }

        if (! Agency::query()->where('slug', self::AGENCY_A_SLUG)->exists()) {
            DB::transaction(function (): void {
                $this->agencyA();
                $this->agencyB();
            });
        }

        $this->printUlids();
    }

    private function agencyA(): void
    {
        $agency = AgencyFactory::new()->createOne([
            'name' => 'PL-1.1 Walk Agency A',
            'slug' => self::AGENCY_A_SLUG,
            'country_code' => 'SK',
        ]);

        $admin = User::factory()->agencyAdmin($agency)->create([
            'email' => self::ADMIN_EMAIL, 'name' => 'Ada Admin', 'password' => self::PASSWORD,
        ]);
        User::factory()->agencyManager($agency)->create([
            'email' => self::MANAGER_EMAIL, 'name' => 'Max Manager', 'password' => self::PASSWORD,
        ]);
        User::factory()->agencyStaff($agency)->create([
            'email' => self::STAFF_EMAIL, 'name' => 'Sam Staff', 'password' => self::PASSWORD,
        ]);

        $bolt = Brand::factory()->forAgency($agency->id)->create(['name' => 'Bolt Food', 'slug' => 'walk-bolt-food', 'color' => 'green']);
        $glovo = Brand::factory()->forAgency($agency->id)->create(['name' => 'Glovo', 'slug' => 'walk-glovo', 'color' => 'amber']);
        Brand::factory()->forAgency($agency->id)->archived()->create(['name' => 'Old Co', 'slug' => 'walk-old-co', 'color' => 'slate']);

        $base = ['agency_id' => $agency->id, 'created_by_user_id' => $admin->id];

        Campaign::factory()->forWave()->create($base + ['brand_id' => $bolt->id, 'name' => 'Bolt Autumn Reels']);
        Campaign::factory()->forWave()->active()->create($base + ['brand_id' => $bolt->id, 'name' => 'Bolt Winter Launch']);
        Campaign::factory()->create($base + ['brand_id' => $bolt->id, 'name' => 'Bolt Old Promo', 'status' => CampaignStatus::Cancelled]);
        $summer = Campaign::factory()->forWave()->create($base + ['brand_id' => $bolt->id, 'name' => 'Bolt Summer', 'status' => CampaignStatus::Completed]);
        Campaign::factory()->create($base + ['brand_id' => $glovo->id, 'name' => 'Glovo Spring']);

        Wave::factory()->forBrand($agency->id, $bolt->id)->linkedTo($summer->id)->create([
            'name' => 'Wave 5', 'go_live_on' => '2026-08-08', 'markets' => ['SK'], 'created_by_user_id' => $admin->id,
        ]);
        Wave::factory()->forBrand($agency->id, $bolt->id)->create([
            'name' => 'Wave 6', 'go_live_on' => '2026-09-05', 'markets' => ['SK', 'RO', 'CZ'], 'created_by_user_id' => $admin->id,
        ]);
    }

    private function agencyB(): void
    {
        $agency = AgencyFactory::new()->createOne([
            'name' => 'PL-1.1 Walk Agency B',
            'slug' => self::AGENCY_B_SLUG,
            'country_code' => 'SK',
        ]);

        $admin = User::factory()->agencyAdmin($agency)->create([
            'email' => self::B_ADMIN_EMAIL, 'name' => 'Bea Admin', 'password' => self::PASSWORD,
        ]);

        $zeta = Brand::factory()->forAgency($agency->id)->create(['name' => 'Zeta Foods', 'slug' => 'walk-zeta-foods', 'color' => 'blue']);
        $q4 = Campaign::factory()->create([
            'agency_id' => $agency->id, 'created_by_user_id' => $admin->id, 'brand_id' => $zeta->id, 'name' => 'Zeta Q4',
        ]);
        unset($q4);

        Wave::factory()->forBrand($agency->id, $zeta->id)->create([
            'name' => 'Zeta Wave 1', 'go_live_on' => '2026-10-01', 'markets' => ['SK'], 'created_by_user_id' => $admin->id,
        ]);
    }

    private function printUlids(): void
    {
        foreach ([['Bolt Food', self::AGENCY_A_SLUG], ['Glovo', self::AGENCY_A_SLUG], ['Zeta Foods', self::AGENCY_B_SLUG]] as [$name, $slug]) {
            $agency = Agency::query()->where('slug', $slug)->first();
            $brand = $agency === null ? null : Brand::query()->where('agency_id', $agency->id)->where('name', $name)->first();
            if ($agency !== null && $brand !== null) {
                $this->command->line("{$name}: brand {$brand->ulid} (agency {$agency->ulid})");
            }
        }
    }
}
