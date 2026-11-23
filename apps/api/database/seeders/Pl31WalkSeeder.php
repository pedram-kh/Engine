<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Agencies\Database\Factories\AgencyFactory;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Agencies\Models\BrandCreatorBlacklist;
use App\Modules\Boards\Services\BoardService;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Enums\CampaignApplicationStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignApplication;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Creators\Enums\RelationshipStatus;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Enums\PersonaPronoun;
use App\Modules\Strategy\Models\WaveConcept;
use App\Modules\Strategy\Models\WaveCreator;
use App\Modules\Strategy\Models\WavePersona;
use App\Modules\Strategy\Models\WaveUsp;
use App\Modules\Strategy\Services\ConceptDecisionService;
use App\Modules\Strategy\Services\ConceptService;
use App\Modules\Strategy\Services\WaveCreatorDecisionService;
use App\Modules\Strategy\Services\WaveCreatorService;
use App\Modules\Waves\Database\Factories\WaveFactory;
use App\Modules\Waves\Models\Wave;
use Illuminate\Console\Command;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * PL-3.1 — local data for the Creators-step walk. Self-contained.
 *
 * Agency A "PL-3.1 Walk Agency A": admin@, manager@, staff@ (all `@pl31-walk.local`), and platform@ (a platform admin). Brands Bolt Food and Glovo.
 *   - Wave "Creators Wave 1": two markets (Slovakia, and Nordics with SE, NO, DK, FI), linked to the for-a-wave campaign
 *       "Bolt Creators Campaign" (active and listed). Personas and approved concepts in both markets. The campaign has
 *       creators who applied, were invited, accepted and declined (see below); three are already in the wave (Slovakia).
 *   - Wave "Creators Wave 2": NO campaign (the Link / Create path), and the unlinked for-a-wave campaign "Bolt Spare
 *       Campaign" for the Link select.
 *   - "Bolt Evergreen": a NORMAL active campaign with its own board and one invited creator (the unchanged proof).
 *   - "Glovo Exclusive Campaign": a campaign of the other brand that blocks Bolt Food (exclusivity), where `exo` is selected.
 *
 * Creators (every one has a login `<name>@pl31-walk.local`, approved and rostered):
 *   lucia (applied, accepted, contracted, client approved), martin (invited, accepted), katarina (applied, pending),
 *   tomas (invited, pending), jana (invited, declined), astrid (applied; Nordics), hidden (applied and NOT discoverable: appears),
 *   maya (can APPLY on the walk), pia (invited: can ACCEPT), daniel (invited: can DECLINE),
 *   blake (applied, hard-blacklisted by the agency) and brandon (applied, blacklisted by the brand): never appear,
 *   exo (applied, exclusive to Glovo's campaign that blocks Bolt): never appears (needs `campaign_brand_exclusivity_enabled` ON).
 *
 * Agency B "PL-3.1 Walk Agency B": admin@pl31-walk-b.local, brand Zeta Foods with a wave and a campaign (isolation).
 *
 * Everything goes through the services the screens call. It does NOT touch any flag.
 *
 *     php artisan db:seed --class='Database\Seeders\Pl31WalkSeeder'
 *
 * GUARDED to local + testing. Idempotent. Removed by {@see Pl31WalkCleanupSeeder}.
 */
final class Pl31WalkSeeder extends Seeder
{
    public const string DOMAIN = '@pl31-walk.local';

    public const string DOMAIN_B = '@pl31-walk-b.local';

    public const string AGENCY_A_SLUG = 'pl31-walk-agency-a';

    public const string AGENCY_B_SLUG = 'pl31-walk-agency-b';

    public const string PASSWORD = 'password-12chars';

    public const string ADMIN_EMAIL = 'admin'.self::DOMAIN;

    public const string MANAGER_EMAIL = 'manager'.self::DOMAIN;

    public const string STAFF_EMAIL = 'staff'.self::DOMAIN;

    public const string PLATFORM_EMAIL = 'platform'.self::DOMAIN;

    public const string B_ADMIN_EMAIL = 'admin'.self::DOMAIN_B;

    /** @var list<array{0: string, 1: string, 2: string, 3: list<string>}> local part, name, country, categories */
    private const array CREATORS = [
        ['lucia', 'Lucia Horváthová', 'SK', ['lifestyle', 'food']],
        ['martin', 'Martin Kováč', 'SK', ['food', 'fashion']],
        ['katarina', 'Katarína Nagy', 'SK', ['lifestyle', 'food']],
        ['tomas', 'Tomáš Baláž', 'SK', ['tech', 'lifestyle']],
        ['jana', 'Jana Novotná', 'SK', ['fashion']],
        ['astrid', 'Astrid Lindqvist', 'SE', ['lifestyle', 'travel']],
        ['hidden', 'Hana Skrytá', 'SK', ['fitness']],
        ['maya', 'Maya Marcinková', 'SK', ['lifestyle']],
        ['pia', 'Pia Kollárová', 'SK', ['food']],
        ['daniel', 'Daniel Rusnák', 'SK', ['tech']],
        ['blake', 'Blake Blocked', 'SK', ['fashion']],
        ['brandon', 'Brandon Barred', 'SK', ['fashion']],
        ['exo', 'Exo Exclusive', 'SK', ['food']],
    ];

    private const array USPS = ['Category entry points', 'Affordability', 'Selection', 'Speed & reliability'];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Pl31WalkSeeder is only allowed in local and testing environments.');
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
        $agency = AgencyFactory::new()->createOne(['name' => 'PL-3.1 Walk Agency A', 'slug' => self::AGENCY_A_SLUG, 'country_code' => 'SK']);
        $admin = User::factory()->agencyAdmin($agency)->create(['email' => self::ADMIN_EMAIL, 'name' => 'Ada Admin', 'password' => self::PASSWORD]);
        User::factory()->agencyManager($agency)->create(['email' => self::MANAGER_EMAIL, 'name' => 'Max Manager', 'password' => self::PASSWORD]);
        User::factory()->agencyStaff($agency)->create(['email' => self::STAFF_EMAIL, 'name' => 'Sam Staff', 'password' => self::PASSWORD]);

        // A platform admin for the admin app (MFA is mandatory for them: the first sign-in walks the enrolment).
        User::factory()->platformAdmin()->create(['email' => self::PLATFORM_EMAIL, 'name' => 'Pat Platform', 'password' => self::PASSWORD]);

        $bolt = Brand::factory()->forAgency($agency->id)->create(['name' => 'Bolt Food', 'slug' => 'pl31-bolt-food', 'color' => 'green']);
        $glovo = Brand::factory()->forAgency($agency->id)->create(['name' => 'Glovo', 'slug' => 'pl31-glovo', 'color' => 'amber']);
        $base = ['agency_id' => $agency->id, 'brand_id' => $bolt->id, 'created_by_user_id' => $admin->id];

        // The campaigns: an active, listed, for-a-wave campaign with every listing-floor field; a spare one (unlinked); a normal one.
        $wave1Campaign = Campaign::factory()->forWave()->active()->listed()->create($base + ['name' => 'Bolt Creators Campaign', 'budget_minor_units' => 1_500_000]);
        Campaign::factory()->forWave()->create($base + ['name' => 'Bolt Spare Campaign']);
        $evergreen = Campaign::factory()->active()->create($base + ['name' => 'Bolt Evergreen']);
        $blocker = Campaign::factory()->active()->create(['agency_id' => $agency->id, 'brand_id' => $glovo->id, 'created_by_user_id' => $admin->id, 'name' => 'Glovo Exclusive Campaign']);
        $blocker->exclusiveBrands()->sync([$bolt->id]);

        /** @var array<string, Creator> $creators */
        $creators = [];
        foreach (self::CREATORS as [$local, $name, $country, $categories]) {
            $creators[$local] = $this->creator($agency, $admin, $local, $name, $country, $categories);
        }

        // The wave with a campaign: two markets, one with several countries.
        $w1 = Wave::factory()->forBrand($agency->id, $bolt->id)->withMarkets([
            ['name' => 'Slovakia', 'code' => 'SK', 'countries' => ['SK']],
            ['name' => 'Nordics', 'code' => 'NOR', 'countries' => ['SE', 'NO', 'DK', 'FI']],
        ])->create(['name' => 'Creators Wave 1', 'go_live_on' => '2027-01-09', 'created_by_user_id' => $admin->id, 'campaign_id' => $wave1Campaign->id]);
        // The wave with no campaign.
        Wave::factory()->forBrand($agency->id, $bolt->id)->withMarkets([['name' => 'Slovakia', 'code' => 'SK', 'countries' => ['SK']]])
            ->create(['name' => 'Creators Wave 2', 'go_live_on' => '2027-02-06', 'created_by_user_id' => $admin->id]);

        // Applicants and invitees of the wave campaign.
        $this->applied($wave1Campaign, $creators['lucia'], CampaignApplicationStatus::Accepted);
        $this->assign($wave1Campaign, $creators['lucia'], AssignmentStatus::Contracted);
        $this->assign($wave1Campaign, $creators['martin'], AssignmentStatus::Contracted);
        $this->applied($wave1Campaign, $creators['katarina'], CampaignApplicationStatus::Pending);
        $this->assign($wave1Campaign, $creators['tomas'], AssignmentStatus::Invited);
        $this->assign($wave1Campaign, $creators['jana'], AssignmentStatus::Declined);
        $this->applied($wave1Campaign, $creators['astrid'], CampaignApplicationStatus::Pending);
        $this->applied($wave1Campaign, $creators['hidden'], CampaignApplicationStatus::Pending);
        $this->assign($wave1Campaign, $creators['pia'], AssignmentStatus::Invited);
        $this->assign($wave1Campaign, $creators['daniel'], AssignmentStatus::Invited);
        foreach (['blake', 'brandon', 'exo'] as $never) {
            $this->applied($wave1Campaign, $creators[$never], CampaignApplicationStatus::Pending);
        }
        // The three who must never appear: a hard blacklist by the agency and by the brand, and an exclusive creator.
        AgencyCreatorRelation::query()->where('agency_id', $agency->id)->where('creator_id', $creators['blake']->id)->update([
            'is_blacklisted' => true, 'blacklist_scope' => 'agency', 'blacklist_type' => 'hard', 'blacklist_reason' => 'Walk data', 'blacklisted_at' => now(),
        ]);
        BrandCreatorBlacklist::factory()->create(['brand_id' => $bolt->id, 'creator_id' => $creators['brandon']->id]);
        CampaignAssignment::factory()->create([
            'agency_id' => $agency->id, 'campaign_id' => $blocker->id, 'creator_id' => $creators['exo']->id, 'status' => AssignmentStatus::Accepted,
        ]);

        // The normal campaign with its own board (the unchanged proof).
        CampaignAssignment::factory()->create([
            'agency_id' => $agency->id, 'brand_id' => $bolt->id, 'campaign_id' => $evergreen->id, 'creator_id' => $creators['maya']->id,
            'status' => AssignmentStatus::Invited, 'invited_at' => now()->subDay(), 'agreed_fee_minor_units' => 200_000, 'agreed_fee_currency' => 'EUR',
        ]);
        app(BoardService::class)->forCampaign($evergreen);
        app(BoardService::class)->forCampaign($wave1Campaign);

        // Strategy of wave 1: USPs, personas, approved concepts.
        $usps = $this->usps($w1);
        $sk1 = $this->persona($w1, 'SK', 'The Office Lunch Organiser', 'Zuzana', PersonaPronoun::She, 'shared services, Bratislava');
        $sk2 = $this->persona($w1, 'SK', 'The Value Seeker', 'Peter', PersonaPronoun::He, 'good food, fair price, Košice');
        $nor = $this->persona($w1, 'NOR', 'The Dark-Winter Homebody', 'Astrid', PersonaPronoun::She, 'it is dark at four, the couch wins');
        foreach ([[$sk1, 'SK', 'Lunch for the floor'], [$sk2, 'SK', 'Fair price, good food'], [$nor, 'NOR', 'Cosy in, lit up']] as [$persona, $market, $title]) {
            $concept = $this->concept($bolt, $w1, $admin, $market, $persona, $title);
            app(ConceptDecisionService::class)->record($bolt, $w1, $concept->ulid, $admin, ['status' => 'approved', 'client_name' => 'Jana Nováková', 'note' => 'Good to go.']);
        }
        unset($usps);

        // Three creators are already in the wave (Slovakia), with personas; Lucia has the client's approval.
        $service = app(WaveCreatorService::class);
        $sk = WaveFactory::marketUlid($w1, 'SK');
        $service->add($bolt, $w1, $admin, $sk, [$creators['lucia']->ulid], $sk1->ulid);
        $service->add($bolt, $w1, $admin, $sk, [$creators['martin']->ulid], $sk2->ulid);
        $service->add($bolt, $w1, $admin, $sk, [$creators['jana']->ulid], null);
        $slot = WaveCreator::query()->where('wave_id', $w1->id)->where('creator_id', $creators['lucia']->id)->firstOrFail();
        app(WaveCreatorDecisionService::class)->record($bolt, $w1, $admin, $slot->ulid, ['status' => 'approved', 'client_name' => 'Jana Nováková', 'note' => 'Great fit.']);
    }

    /**
     * @param  list<string>  $categories
     */
    private function creator(Agency $agency, User $owner, string $local, string $name, string $country, array $categories): Creator
    {
        $user = User::factory()->creator()->create(['email' => $local.self::DOMAIN, 'name' => $name, 'password' => self::PASSWORD]);
        $creator = Creator::factory()->approved()->kycVerified()->withContact()->create([
            'user_id' => $user->id, 'display_name' => $name, 'country_code' => $country, 'primary_language' => $country === 'SE' ? 'sv' : 'sk',
            'categories' => $categories, 'is_discoverable' => $local !== 'hidden',
        ]);
        AgencyCreatorRelation::factory()->create([
            'agency_id' => $agency->id, 'creator_id' => $creator->id, 'relationship_status' => RelationshipStatus::Roster,
            'is_blacklisted' => false, 'invited_by_user_id' => $owner->id, 'owner_user_id' => $owner->id,
        ]);

        return $creator;
    }

    private function applied(Campaign $campaign, Creator $creator, CampaignApplicationStatus $status): void
    {
        CampaignApplication::factory()->status($status)->create(['campaign_id' => $campaign->id, 'agency_id' => $campaign->agency_id, 'creator_id' => $creator->id]);
    }

    private function assign(Campaign $campaign, Creator $creator, AssignmentStatus $status): void
    {
        CampaignAssignment::factory()->create([
            'agency_id' => $campaign->agency_id, 'brand_id' => $campaign->brand_id, 'campaign_id' => $campaign->id, 'creator_id' => $creator->id,
            'status' => $status, 'invited_at' => now()->subDays(2),
        ]);
    }

    /**
     * @return list<WaveUsp>
     */
    private function usps(Wave $wave): array
    {
        $out = [];
        foreach (self::USPS as $title) {
            $out[] = WaveUsp::factory()->create(['wave_id' => $wave->id, 'agency_id' => $wave->agency_id, 'title' => $title]);
        }

        return $out;
    }

    private function persona(Wave $wave, string $market, string $title, string $name, PersonaPronoun $pronoun, string $summary): WavePersona
    {
        return WavePersona::factory()->create([
            'wave_id' => $wave->id, 'agency_id' => $wave->agency_id, 'market_id' => WaveFactory::marketId($wave, $market),
            'title' => $title, 'name' => $name, 'pronoun' => $pronoun, 'summary' => $summary,
        ]);
    }

    private function concept(Brand $brand, Wave $wave, User $actor, string $market, WavePersona $persona, string $title): WaveConcept
    {
        return app(ConceptService::class)->create($brand, $wave, $actor, [
            'funnel_stage' => 'mofu', 'funnel_note' => 'Consideration', 'angle' => 'The angle', 'primary_message' => 'The message.', 'hook' => 'The hook.',
            'cta' => 'Order now', 'market' => WaveFactory::marketUlid($wave, $market), 'persona' => $persona->ulid, 'title' => $title,
        ]);
    }

    private function agencyB(): void
    {
        $agency = AgencyFactory::new()->createOne(['name' => 'PL-3.1 Walk Agency B', 'slug' => self::AGENCY_B_SLUG, 'country_code' => 'SK']);
        $admin = User::factory()->agencyAdmin($agency)->create(['email' => self::B_ADMIN_EMAIL, 'name' => 'Bea Admin', 'password' => self::PASSWORD]);
        $zeta = Brand::factory()->forAgency($agency->id)->create(['name' => 'Zeta Foods', 'slug' => 'pl31-zeta-foods', 'color' => 'blue']);
        $campaign = Campaign::factory()->forWave()->active()->listed()->create(['agency_id' => $agency->id, 'brand_id' => $zeta->id, 'created_by_user_id' => $admin->id, 'name' => 'Zeta Wave Campaign']);
        Wave::factory()->forBrand($agency->id, $zeta->id)->withMarkets([['name' => 'Slovakia', 'code' => 'SK', 'countries' => ['SK']]])
            ->create(['name' => 'Zeta Wave 1', 'go_live_on' => '2026-12-01', 'created_by_user_id' => $admin->id, 'campaign_id' => $campaign->id]);
    }

    private function printUlids(): void
    {
        $agency = Agency::query()->where('slug', self::AGENCY_A_SLUG)->first();
        if ($agency === null) {
            return;
        }
        $this->say("Agency A: {$agency->ulid}  (password for every account: ".self::PASSWORD.')');
        $bolt = Brand::query()->where('agency_id', $agency->id)->where('name', 'Bolt Food')->first();
        if ($bolt !== null) {
            $this->say("Bolt Food: /brands/{$bolt->ulid}");
            foreach (Wave::query()->where('brand_id', $bolt->id)->orderBy('id')->get() as $wave) {
                $this->say("  {$wave->name}: /brands/{$bolt->ulid}/waves/{$wave->ulid}");
            }
        }
        foreach (Campaign::query()->where('agency_id', $agency->id)->orderBy('id')->get() as $campaign) {
            $this->say("  campaign {$campaign->name}: /campaigns/{$campaign->ulid}");
        }
    }

    /** Writes to the console when run from `db:seed`; silent when a test calls the seeder directly (no command is bound). */
    private function say(string $message): void
    {
        if ($this->command instanceof Command) {
            $this->command->line($message);
        }
    }
}
