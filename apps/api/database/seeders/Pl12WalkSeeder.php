<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Agencies\Database\Factories\AgencyFactory;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Boards\Services\BoardService;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Creators\Enums\RelationshipStatus;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use App\Modules\Waves\Database\Factories\WaveFactory;
use App\Modules\Waves\Models\Wave;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * PL-1.2 — local data for the wave-campaign walk (plan §10).
 *
 * Agency A "PL-1.2 Walk Agency A": an admin, a manager and a staff user; brands Bolt Food and Glovo (both
 * active); campaigns
 *   - Bolt Evergreen: a NORMAL active campaign with a real board and one invited assignment (the "old board" proof);
 *   - Bolt Summer Wave Campaign: FOR-A-WAVE, active and LISTED on the jobs board with a budget above zero and every
 *     listing-floor field, so creators can apply; no wave yet;
 *   - Bolt Normal Promo: a normal campaign with no assignments (a wave cannot be linked to it);
 * waves Wave 7 and Wave 8 (Bolt Food, no campaign).
 *
 * Creators (approved, KYC verified, rostered): maya (applies), pia (accepts an invitation), daniel (declines one),
 * eva (accepts and is selected), and omar (the invited creator already on Bolt Evergreen). Applications and
 * invitations to the wave campaign are made BY THE WALK, not here.
 *
 * Agency B "PL-1.2 Walk Agency B": an admin, brand Zeta Foods, a for-a-wave campaign and a wave (isolation proof).
 *
 * Every account lives under `@pl12-walk.local`, which is what {@see Pl12WalkCleanupSeeder} removes it by. It does NOT
 * touch the `waves_enabled` flag (it stays at its default, OFF): turn it on and off by hand in the admin app.
 * Additive: it never touches a row it did not make.
 *
 *     php artisan db:seed --class='Database\Seeders\Pl12WalkSeeder'
 *
 * GUARDED to local + testing only. Idempotent: a second run changes nothing. It prints the ulids the walk needs.
 */
final class Pl12WalkSeeder extends Seeder
{
    public const string DOMAIN = '@pl12-walk.local';

    public const string AGENCY_A_SLUG = 'pl12-walk-agency-a';

    public const string AGENCY_B_SLUG = 'pl12-walk-agency-b';

    public const string PASSWORD = 'password-12chars';

    public const string ADMIN_EMAIL = 'admin'.self::DOMAIN;

    public const string MANAGER_EMAIL = 'manager'.self::DOMAIN;

    public const string STAFF_EMAIL = 'staff'.self::DOMAIN;

    public const string B_ADMIN_EMAIL = 'admin-b'.self::DOMAIN;

    /** @var list<array{string, string, string}> email local part, display name, country */
    public const array CREATORS = [
        ['maya', 'Maya Novak', 'SK'],
        ['pia', 'Pia Horvat', 'SI'],
        ['daniel', 'Daniel Kovac', 'CZ'],
        ['eva', 'Eva Marin', 'RO'],
        ['omar', 'Omar Haddad', 'PT'],
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Pl12WalkSeeder is only allowed in local and testing environments.');
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
            'name' => 'PL-1.2 Walk Agency A',
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

        $bolt = Brand::factory()->forAgency($agency->id)->create(['name' => 'Bolt Food', 'slug' => 'pl12-bolt-food', 'color' => 'green']);
        Brand::factory()->forAgency($agency->id)->create(['name' => 'Glovo', 'slug' => 'pl12-glovo', 'color' => 'amber']);

        $base = ['agency_id' => $agency->id, 'brand_id' => $bolt->id, 'created_by_user_id' => $admin->id];

        $evergreen = Campaign::factory()->active()->create($base + ['name' => 'Bolt Evergreen']);
        // Active, listed on the jobs board, budget above zero, every listing-floor field (`listed()` carries them):
        // creators can apply. For a wave, so its board is the wave board.
        Campaign::factory()->forWave()->active()->listed()->create($base + [
            'name' => 'Bolt Summer Wave Campaign',
            'budget_minor_units' => 1_500_000,
        ]);
        Campaign::factory()->create($base + ['name' => 'Bolt Normal Promo']);

        Wave::factory()->forBrand($agency->id, $bolt->id)->withMarkets(WaveFactory::singleCountryMarkets(['SK', 'CZ']))->create([
            'name' => 'Wave 7', 'go_live_on' => '2026-12-05', 'created_by_user_id' => $admin->id,
        ]);
        Wave::factory()->forBrand($agency->id, $bolt->id)->withMarkets(WaveFactory::singleCountryMarkets(['SK', 'RO']))->create([
            'name' => 'Wave 8', 'go_live_on' => '2027-01-09', 'created_by_user_id' => $admin->id,
        ]);

        $creators = [];
        foreach (self::CREATORS as [$local, $name, $country]) {
            $creators[$local] = $this->creator($agency, $admin, $local, $name, $country);
        }

        $omar = $creators['omar'] ?? throw new RuntimeException('The walk creator omar was not seeded.');

        // The old-board proof: a normal campaign whose board holds one invited creator.
        CampaignAssignment::factory()->create([
            'campaign_id' => $evergreen->id,
            'agency_id' => $agency->id,
            'brand_id' => $bolt->id,
            'creator_id' => $omar->id,
            'status' => AssignmentStatus::Invited,
            'invited_at' => now()->subDay(),
            'agreed_fee_minor_units' => 200_000,
            'agreed_fee_currency' => 'EUR',
        ]);
        app(BoardService::class)->forCampaign($evergreen);
    }

    private function creator(Agency $agency, User $owner, string $local, string $name, string $country): Creator
    {
        $user = User::factory()->creator()->create([
            'email' => $local.self::DOMAIN, 'name' => $name, 'password' => self::PASSWORD,
        ]);
        $creator = Creator::factory()->approved()->kycVerified()->withContact()->create([
            'user_id' => $user->id,
            'display_name' => $name,
            'country_code' => $country,
            'primary_language' => 'en',
        ]);

        AgencyCreatorRelation::factory()->create([
            'agency_id' => $agency->id,
            'creator_id' => $creator->id,
            'relationship_status' => RelationshipStatus::Roster,
            'is_blacklisted' => false,
            'invited_by_user_id' => $owner->id,
            'owner_user_id' => $owner->id,
        ]);

        return $creator;
    }

    private function agencyB(): void
    {
        $agency = AgencyFactory::new()->createOne([
            'name' => 'PL-1.2 Walk Agency B',
            'slug' => self::AGENCY_B_SLUG,
            'country_code' => 'SK',
        ]);

        $admin = User::factory()->agencyAdmin($agency)->create([
            'email' => self::B_ADMIN_EMAIL, 'name' => 'Bea Admin', 'password' => self::PASSWORD,
        ]);

        $zeta = Brand::factory()->forAgency($agency->id)->create(['name' => 'Zeta Foods', 'slug' => 'pl12-zeta-foods', 'color' => 'blue']);
        Campaign::factory()->forWave()->create([
            'agency_id' => $agency->id, 'created_by_user_id' => $admin->id, 'brand_id' => $zeta->id, 'name' => 'Zeta Wave Campaign',
        ]);
        Wave::factory()->forBrand($agency->id, $zeta->id)->withMarkets(WaveFactory::singleCountryMarkets(['SK']))->create([
            'name' => 'Zeta Wave 1', 'go_live_on' => '2026-12-01', 'created_by_user_id' => $admin->id,
        ]);
    }

    private function printUlids(): void
    {
        $agency = Agency::query()->where('slug', self::AGENCY_A_SLUG)->first();
        if ($agency === null) {
            return;
        }

        $this->command->line("Agency A: {$agency->ulid}");
        $bolt = Brand::query()->where('agency_id', $agency->id)->where('name', 'Bolt Food')->first();
        if ($bolt !== null) {
            $this->command->line("Bolt Food: brand {$bolt->ulid}");
        }
        foreach (['Bolt Evergreen', 'Bolt Summer Wave Campaign', 'Bolt Normal Promo'] as $name) {
            $campaign = Campaign::query()->where('agency_id', $agency->id)->where('name', $name)->first();
            if ($campaign !== null) {
                $this->command->line("{$name}: campaign {$campaign->ulid}");
            }
        }
    }
}
