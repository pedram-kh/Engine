<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Agencies\Database\Factories\AgencyFactory;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
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
use App\Modules\Strategy\Models\WaveCreator;
use App\Modules\Strategy\Models\WavePersona;
use App\Modules\Strategy\Models\WaveScript;
use App\Modules\Strategy\Models\WaveScriptHook;
use App\Modules\Strategy\Models\WaveUsp;
use App\Modules\Strategy\Services\MatrixService;
use App\Modules\Strategy\Services\ScriptHookService;
use App\Modules\Strategy\Services\ScriptService;
use App\Modules\Strategy\Services\WaveCreatorService;
use App\Modules\Waves\Database\Factories\WaveFactory;
use App\Modules\Waves\Models\Wave;
use Illuminate\Console\Command;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * PL-3.2a — local data for the Scripts-step walk. Self-contained.
 *
 * Agency A "PL-3.2 Walk Agency A": admin@, manager@, staff@ (all `@pl32-walk.local`) and platform@ (a platform admin). Brand Bolt Food.
 *   - Wave "Scripts Wave 1": two markets (Slovakia, and Nordics with SE, NO, DK, FI), linked to the for-a-wave campaign
 *       "Bolt Scripts Campaign". Four USPs, three personas, a filled messaging matrix (Priority and Support cells with their synced
 *       angles). NO concepts, so the concepts warning shows on steps 5 and 6.
 *   - Slovakia: Lucia (persona SK1, TWO scripts), Martin (persona SK2, THREE scripts: Add script is off), Katarina (persona SK1, NONE),
 *       Tomáš (NO persona, one script with a hostile string in it: shows the prompt and the plain-text proof), Jana (persona SK2: removed
 *       from the market with "archive", so her script is an ARCHIVED one; she is not in the market now).
 *   - Nordics: Astrid (persona NOR, one script).
 *
 * Agency B "PL-3.2 Walk Agency B": admin@pl32-walk-b.local, brand Zeta Foods with a wave (isolation).
 *
 * Everything goes through the services the screens call. It does NOT touch any flag.
 *
 *     php artisan db:seed --class='Database\Seeders\Pl32WalkSeeder'
 *
 * GUARDED to local + testing. Idempotent. Removed by {@see Pl32WalkCleanupSeeder}.
 */
final class Pl32WalkSeeder extends Seeder
{
    public const string DOMAIN = '@pl32-walk.local';

    public const string DOMAIN_B = '@pl32-walk-b.local';

    public const string AGENCY_A_SLUG = 'pl32-walk-agency-a';

    public const string AGENCY_B_SLUG = 'pl32-walk-agency-b';

    public const string PASSWORD = 'password-12chars';

    public const string ADMIN_EMAIL = 'admin'.self::DOMAIN;

    public const string MANAGER_EMAIL = 'manager'.self::DOMAIN;

    public const string STAFF_EMAIL = 'staff'.self::DOMAIN;

    public const string PLATFORM_EMAIL = 'platform'.self::DOMAIN;

    public const string B_ADMIN_EMAIL = 'admin'.self::DOMAIN_B;

    /** The text a hostile author could type: markup, a template expression and quotes. Rendered as plain text, never as markup. */
    public const string HOSTILE = '<img src=x onerror="alert(1)"> {{ 7*7 }} <script>alert("x")</script> "quoted" & done';

    /** @var list<array{0: string, 1: string, 2: string, 3: list<string>}> local part, name, country, categories */
    private const array CREATORS = [
        ['lucia', 'Lucia Horváthová', 'SK', ['lifestyle', 'food']],
        ['martin', 'Martin Kováč', 'SK', ['food', 'fashion']],
        ['katarina', 'Katarína Nagy', 'SK', ['lifestyle', 'food']],
        ['tomas', 'Tomáš Baláž', 'SK', ['tech', 'lifestyle']],
        ['jana', 'Jana Novotná', 'SK', ['fashion']],
        ['astrid', 'Astrid Lindqvist', 'SE', ['lifestyle', 'travel']],
    ];

    private const array USPS = ['Category entry points', 'Affordability', 'Selection', 'Speed & reliability'];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Pl32WalkSeeder is only allowed in local and testing environments.');
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
        $agency = AgencyFactory::new()->createOne(['name' => 'PL-3.2 Walk Agency A', 'slug' => self::AGENCY_A_SLUG, 'country_code' => 'SK']);
        $admin = User::factory()->agencyAdmin($agency)->create(['email' => self::ADMIN_EMAIL, 'name' => 'Ada Admin', 'password' => self::PASSWORD]);
        User::factory()->agencyManager($agency)->create(['email' => self::MANAGER_EMAIL, 'name' => 'Max Manager', 'password' => self::PASSWORD]);
        User::factory()->agencyStaff($agency)->create(['email' => self::STAFF_EMAIL, 'name' => 'Sam Staff', 'password' => self::PASSWORD]);
        User::factory()->platformAdmin()->create(['email' => self::PLATFORM_EMAIL, 'name' => 'Pat Platform', 'password' => self::PASSWORD]);

        $bolt = Brand::factory()->forAgency($agency->id)->create(['name' => 'Bolt Food', 'slug' => 'pl32-bolt-food', 'color' => 'green']);
        $campaign = Campaign::factory()->forWave()->active()->listed()->create([
            'agency_id' => $agency->id, 'brand_id' => $bolt->id, 'created_by_user_id' => $admin->id, 'name' => 'Bolt Scripts Campaign', 'budget_minor_units' => 1_500_000,
        ]);

        /** @var array<string, Creator> $creators */
        $creators = [];
        foreach (self::CREATORS as [$local, $name, $country, $categories]) {
            $creators[$local] = $this->creator($agency, $admin, $local, $name, $country, $categories);
        }

        $wave = Wave::factory()->forBrand($agency->id, $bolt->id)->withMarkets([
            ['name' => 'Slovakia', 'code' => 'SK', 'countries' => ['SK']],
            ['name' => 'Nordics', 'code' => 'NOR', 'countries' => ['SE', 'NO', 'DK', 'FI']],
        ])->create(['name' => 'Scripts Wave 1', 'go_live_on' => '2027-01-09', 'created_by_user_id' => $admin->id, 'campaign_id' => $campaign->id]);

        // Every creator applied or was invited, so the picker (and Add) accept them.
        $this->applied($campaign, $creators['lucia'], CampaignApplicationStatus::Accepted);
        $this->assign($campaign, $creators['lucia'], AssignmentStatus::Contracted);
        $this->assign($campaign, $creators['martin'], AssignmentStatus::Contracted);
        $this->applied($campaign, $creators['katarina'], CampaignApplicationStatus::Pending);
        $this->assign($campaign, $creators['tomas'], AssignmentStatus::Invited);
        $this->assign($campaign, $creators['jana'], AssignmentStatus::Declined);
        $this->applied($campaign, $creators['astrid'], CampaignApplicationStatus::Pending);
        app(BoardService::class)->forCampaign($campaign);

        // Strategy: USPs, personas and a filled matrix. No concepts on purpose (the concepts warning).
        $usps = $this->usps($wave);
        $sk1 = $this->persona($wave, 'SK', 'The Office Lunch Organiser', 'Zuzana', PersonaPronoun::She, 'shared services, Bratislava');
        $sk2 = $this->persona($wave, 'SK', 'The Value Seeker', 'Peter', PersonaPronoun::He, 'good food, fair price, Košice');
        $nor = $this->persona($wave, 'NOR', 'The Dark-Winter Homebody', 'Astrid', PersonaPronoun::She, 'it is dark at four, the couch wins');
        $this->cells($bolt, $wave, $admin, 'SK', [
            [$sk1, $usps[0], 'priority', 'Lunch for the whole floor is one order away.', 'Everyone eats well, you only order once.'],
            [$sk1, $usps[1], 'support', 'Fair price for a team lunch.', 'Team lunch without the team-lunch bill.'],
            [$sk1, $usps[2], 'support', 'Something for every diet.', 'Vegetarian, vegan, hungry: all covered.'],
            [$sk2, $usps[1], 'priority', 'Good food, a fair price.', 'More food for less than you think.'],
            [$sk2, $usps[3], 'support', 'Hot when it arrives.', 'Hot, and quicker than the queue.'],
        ]);
        $this->cells($bolt, $wave, $admin, 'NOR', [
            [$nor, $usps[0], 'priority', 'Dark at four: the couch wins.', 'When it is dark at four, dinner comes to you.'],
            [$nor, $usps[2], 'support', 'Comfort food from every corner.', 'Whatever the mood, it is on the list.'],
        ]);

        // Creators in the wave, with personas (Tomáš has none).
        $service = app(WaveCreatorService::class);
        $sk = WaveFactory::marketUlid($wave, 'SK');
        $nordics = WaveFactory::marketUlid($wave, 'NOR');
        $service->add($bolt, $wave, $admin, $sk, [$creators['lucia']->ulid], $sk1->ulid);
        $service->add($bolt, $wave, $admin, $sk, [$creators['martin']->ulid], $sk2->ulid);
        $service->add($bolt, $wave, $admin, $sk, [$creators['katarina']->ulid], $sk1->ulid);
        $service->add($bolt, $wave, $admin, $sk, [$creators['tomas']->ulid], null);
        $service->add($bolt, $wave, $admin, $sk, [$creators['jana']->ulid], $sk2->ulid);
        $service->add($bolt, $wave, $admin, $nordics, [$creators['astrid']->ulid], $nor->ulid);

        // Scripts: Lucia 2, Martin 3, Katarina 0, Tomáš 1 (hostile text), Jana 1 (then archived), Astrid 1.
        $this->script($bolt, $wave, $admin, $creators['lucia'], 'close_up', 'Zuzana holds up three bags in the office kitchen.', 'Lunch for the whole floor? One order.', 'Team lunch in one tap');
        $this->script($bolt, $wave, $admin, $creators['lucia'], 'wide', 'The whole team around one table.', 'Nobody says "I can only eat salad" anymore.', null);
        foreach ([1, 2, 3] as $n) {
            $this->script($bolt, $wave, $admin, $creators['martin'], 'medium', "Martin opens the box, take {$n}.", "Good food, fair price. Take {$n}.", null);
        }
        $this->script($bolt, $wave, $admin, $creators['tomas'], 'detail', self::HOSTILE, self::HOSTILE, self::HOSTILE);
        $this->script($bolt, $wave, $admin, $creators['jana'], 'reaction', 'Jana reacts to the first bite.', 'This one is archived.', null);
        $this->script($bolt, $wave, $admin, $creators['astrid'], 'pov', 'The window shows it is already dark.', 'Dark at four? Dinner comes to you.', 'Cosy in');

        // Jana leaves Slovakia with "archive": her script is kept as an archived one.
        $janaSlot = WaveCreator::query()->where('wave_id', $wave->id)->where('creator_id', $creators['jana']->id)->firstOrFail();
        $service->remove($bolt, $wave, $admin, $janaSlot->ulid, 'archive');
    }

    private function script(Brand $brand, Wave $wave, User $actor, Creator $creator, string $shot, string $visual, string $audio, ?string $overlay): void
    {
        $slot = WaveCreator::query()->where('wave_id', $wave->id)->where('creator_id', $creator->id)->firstOrFail();
        $script = app(ScriptService::class)->create($brand, $wave, $actor, $slot->ulid);
        /** @var WaveScriptHook $first */
        $first = WaveScriptHook::query()->where('script_id', $script->id)->orderBy('position')->firstOrFail();
        $changes = ['shot_type' => $shot, 'visual_description' => $visual, 'texts' => ['audio' => ['en' => $audio]]];
        if ($overlay !== null) {
            $changes['texts']['overlay'] = ['en' => $overlay];
        }
        app(ScriptHookService::class)->update($brand, $wave, $actor, $script->ulid, $first->ulid, $changes, $first->revision);
    }

    /**
     * @param  list<string>  $categories
     */
    private function creator(Agency $agency, User $owner, string $local, string $name, string $country, array $categories): Creator
    {
        $user = User::factory()->creator()->create(['email' => $local.self::DOMAIN, 'name' => $name, 'password' => self::PASSWORD]);
        $creator = Creator::factory()->approved()->kycVerified()->withContact()->create([
            'user_id' => $user->id, 'display_name' => $name, 'country_code' => $country, 'primary_language' => $country === 'SE' ? 'sv' : 'sk',
            'categories' => $categories, 'is_discoverable' => true,
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

    /**
     * Written through the matrix service, so every Priority and Support cell gets its synced angle.
     *
     * @param  list<array{0: WavePersona, 1: WaveUsp, 2: string, 3: string, 4: string}>  $rows
     */
    private function cells(Brand $brand, Wave $wave, User $actor, string $market, array $rows): void
    {
        $changes = array_map(static fn (array $r): array => [
            'persona' => $r[0]->ulid, 'usp' => $r[1]->ulid, 'level' => $r[2], 'rationale' => $r[3], 'message_line' => $r[4],
        ], $rows);
        app(MatrixService::class)->save($brand, $wave, $actor, WaveFactory::marketUlid($wave, $market), $changes);
    }

    private function agencyB(): void
    {
        $agency = AgencyFactory::new()->createOne(['name' => 'PL-3.2 Walk Agency B', 'slug' => self::AGENCY_B_SLUG, 'country_code' => 'SK']);
        $admin = User::factory()->agencyAdmin($agency)->create(['email' => self::B_ADMIN_EMAIL, 'name' => 'Bea Admin', 'password' => self::PASSWORD]);
        $zeta = Brand::factory()->forAgency($agency->id)->create(['name' => 'Zeta Foods', 'slug' => 'pl32-zeta-foods', 'color' => 'blue']);
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
                $this->say("  {$wave->name}: /brands/{$bolt->ulid}/waves/{$wave->ulid}/strategy/scripts");
            }
        }
        $this->say('  scripts seeded: '.WaveScript::query()->where('agency_id', $agency->id)->count());
    }

    /** Writes to the console when run from `db:seed`; silent when a test calls the seeder directly (no command is bound). */
    private function say(string $message): void
    {
        if ($this->command instanceof Command) {
            $this->command->line($message);
        }
    }
}
