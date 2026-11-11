<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Agencies\Database\Factories\AgencyFactory;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignApplication;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Creators\Enums\RelationshipStatus;
use App\Modules\Creators\Features\CampaignBrandExclusivityEnabled;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use RuntimeException;

/**
 * AH-120 — local data for the brand-exclusivity walk (web, then the build 5 device walk).
 *
 * ONE agency ("AH-120 Walk Agency") with an admin and a manager; three brands (Yahoo, Zava, and
 * Old Co, which is ARCHIVED); four rostered creators; four campaigns that decide who is blocked:
 *
 *   Zava Summer     (brand Zava)  BLOCKS Yahoo, no end date.   Maya and Pia are contracted on it.
 *   Zava Archive    (brand Zava)  BLOCKS Yahoo, ended 2026-01-31 (past, so it no longer blocks).
 *                                 Eva is contracted on it.
 *   Yahoo Spring    (brand Yahoo) listed on the jobs board.    Pia has a PENDING application on it.
 *   Yahoo Autumn    (brand Yahoo) listed on the jobs board.
 *
 * What each creator sees with the flag ON (it is switched on for everyone):
 *   Maya    selected on Zava Summer  -> both Yahoo jobs say "Not available to you", no Apply.
 *   Pia     selected on Zava Summer  -> same on Yahoo Autumn; her pending application on Yahoo
 *                                       Spring is untouched ("Applied"); the agency sees a
 *                                       Conflict badge on it.
 *   Daniel  on nothing               -> both Yahoo jobs apply normally.
 *   Eva     on Zava Archive (ended)  -> both Yahoo jobs apply normally (the block has lifted).
 *
 * Everything is invented and every account lives under `@ah120-walk.local`, which is what
 * {@see Ah120WalkCleanupSeeder} removes it by. Additive: it never touches a row it did not make.
 *
 *     php artisan db:seed --class='Database\Seeders\Ah120WalkSeeder'
 *
 * GUARDED to local + testing only. Idempotent: a second run only re-asserts the flag.
 */
final class Ah120WalkSeeder extends Seeder
{
    public const string DOMAIN = '@ah120-walk.local';

    public const string AGENCY_SLUG = 'ah120-walk-agency';

    public const string PASSWORD = 'password-12chars';

    public const string ADMIN_EMAIL = 'admin'.self::DOMAIN;

    public const string MANAGER_EMAIL = 'manager'.self::DOMAIN;

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Ah120WalkSeeder is only allowed in local and testing environments.');
        }

        if (Agency::query()->where('slug', self::AGENCY_SLUG)->exists()) {
            Feature::activate(CampaignBrandExclusivityEnabled::NAME);

            return;
        }

        DB::transaction(function (): void {
            $agency = AgencyFactory::new()->createOne([
                'name' => 'AH-120 Walk Agency',
                'slug' => self::AGENCY_SLUG,
                'country_code' => 'PT',
            ]);

            $admin = User::factory()->agencyAdmin($agency)->create([
                'email' => self::ADMIN_EMAIL,
                'name' => 'Mara Admin',
                'password' => self::PASSWORD,
            ]);
            User::factory()->agencyManager($agency)->create([
                'email' => self::MANAGER_EMAIL,
                'name' => 'Marco Manager',
                'password' => self::PASSWORD,
            ]);

            $yahoo = Brand::factory()->forAgency($agency->id)->create([
                'name' => 'Yahoo', 'slug' => 'walk-yahoo', 'color' => 'violet',
            ]);
            $zava = Brand::factory()->forAgency($agency->id)->create([
                'name' => 'Zava', 'slug' => 'walk-zava', 'color' => 'teal',
            ]);
            // Archived: it is not offered in the multi-select, but if a campaign already lists
            // it, it stays listed and marked "(Archived)" on Overview and in the form.
            $oldCo = Brand::factory()->forAgency($agency->id)->archived()->create([
                'name' => 'Old Co', 'slug' => 'walk-old-co', 'color' => 'orange',
            ]);

            $base = ['agency_id' => $agency->id, 'created_by_user_id' => $admin->id];

            $zavaSummer = Campaign::factory()->active()->blocking([$yahoo, $oldCo])->create($base + [
                'brand_id' => $zava->id, 'name' => 'Zava Summer', 'listed_on_jobs_board' => false,
            ]);
            $zavaArchive = Campaign::factory()->active()->blocking([$yahoo], '2026-01-31')->create($base + [
                'brand_id' => $zava->id, 'name' => 'Zava Archive', 'listed_on_jobs_board' => false,
            ]);
            $yahooSpring = Campaign::factory()->listed()->create($base + [
                'brand_id' => $yahoo->id, 'name' => 'Yahoo Spring', 'ends_at' => null,
            ]);
            Campaign::factory()->listed()->create($base + [
                'brand_id' => $yahoo->id, 'name' => 'Yahoo Autumn', 'ends_at' => null,
            ]);

            $maya = $this->creator($agency, $admin, 'maya', 'Maya Thompson', 'PT');
            $pia = $this->creator($agency, $admin, 'pia', 'Pia Santos', 'PT');
            $this->creator($agency, $admin, 'daniel', 'Daniel Okafor', 'PT');
            $eva = $this->creator($agency, $admin, 'eva', 'Eva Laurent', 'PT');

            // Pia applied while nothing blocked her; she was selected on Zava Summer afterwards.
            CampaignApplication::factory()->withNote('Shot two campaigns for this brand.')->create([
                'campaign_id' => $yahooSpring->id,
                'creator_id' => $pia->id,
                'agency_id' => $agency->id,
            ]);

            $this->contract($zavaSummer, $maya, 0);
            $this->contract($zavaSummer, $pia, 1);
            $this->contract($zavaArchive, $eva, 2);
        });

        Feature::activate(CampaignBrandExclusivityEnabled::NAME);
    }

    private function creator(Agency $agency, User $owner, string $local, string $name, string $country): Creator
    {
        $user = User::factory()->creator()->create([
            'email' => $local.self::DOMAIN,
            'name' => $name,
            'password' => self::PASSWORD,
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

    private function contract(Campaign $campaign, Creator $creator, int $index): void
    {
        CampaignAssignment::factory()->create([
            'campaign_id' => $campaign->id,
            'agency_id' => $campaign->agency_id,
            'brand_id' => $campaign->brand_id,
            'creator_id' => $creator->id,
            'status' => AssignmentStatus::Contracted,
            'invited_at' => now()->subDays(3),
            'agreed_fee_minor_units' => 300_000 + $index * 50_000,
            'agreed_fee_currency' => 'EUR',
        ]);
    }
}
