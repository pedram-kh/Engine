<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Agencies\Database\Factories\AgencyFactory;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Brands\Models\Brand;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Enums\PersonaPronoun;
use App\Modules\Strategy\Models\WaveConcept;
use App\Modules\Strategy\Models\WavePersona;
use App\Modules\Strategy\Models\WaveUsp;
use App\Modules\Strategy\Services\ConceptDecisionService;
use App\Modules\Strategy\Services\ConceptService;
use App\Modules\Strategy\Services\MatrixService;
use App\Modules\Waves\Database\Factories\WaveFactory;
use App\Modules\Waves\Models\Wave;
use Illuminate\Console\Command;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * PL-2.4 — local data for the markets walk. Self-contained.
 *
 * Agency A "PL-2.4 Walk Agency A": admin@, manager@, staff@ (all `@pl24-walk.local`); brand Bolt Food with
 *   - Markets Wave 1: three markets — "Slovakia" (SK, one country), "UK & Ireland" (UKI: GB, IE) and "Nordics" (NOR: SE, NO,
 *       DK, FI) — six USPs, personas in every market (UKI1, UKI2, NOR1, NOR2, SK1, SK2), matrix cells in UK & Ireland and
 *       Nordics, and concepts in UK & Ireland (two, one approved) and Nordics (one).
 *   - Markets Wave 2: the minimum, one single-country market (Slovakia) and nothing else.
 *   - Markets Wave 3: one market whose NAME is a hostile string (markup, quotes, an ampersand and a very long tail), with a
 *       persona, to see that names are only ever text and that long names truncate.
 *
 * Agency B "PL-2.4 Walk Agency B": admin@pl24-walk-b.local, brand Glovo, one wave with a persona (isolation).
 *
 * Everything goes through the services the screens call. It does NOT touch any flag: `waves_enabled` and `strategy_enabled`
 * stay as they are.
 *
 *     php artisan db:seed --class='Database\Seeders\Pl24WalkSeeder'
 *
 * GUARDED to local + testing. Idempotent. Removed by {@see Pl24WalkCleanupSeeder}.
 */
final class Pl24WalkSeeder extends Seeder
{
    public const string DOMAIN = '@pl24-walk.local';

    public const string DOMAIN_B = '@pl24-walk-b.local';

    public const string AGENCY_A_SLUG = 'pl24-walk-agency-a';

    public const string AGENCY_B_SLUG = 'pl24-walk-agency-b';

    public const string PASSWORD = 'password-12chars';

    public const string ADMIN_EMAIL = 'admin'.self::DOMAIN;

    public const string MANAGER_EMAIL = 'manager'.self::DOMAIN;

    public const string STAFF_EMAIL = 'staff'.self::DOMAIN;

    public const string B_ADMIN_EMAIL = 'admin'.self::DOMAIN_B;

    /** A market name that is not a name: markup, quotes, an ampersand, and a tail long enough to truncate. */
    public const string HOSTILE_NAME = '<img src=x onerror=alert(1)><b>Bold</b> & "quoted" market with a very long name that keeps going and going';

    private const array USPS = [
        'Category entry points', 'Affordability', 'Selection', 'Speed & reliability', 'Super app convenience', 'Local restaurant discovery',
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Pl24WalkSeeder is only allowed in local and testing environments.');
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
        $agency = AgencyFactory::new()->createOne(['name' => 'PL-2.4 Walk Agency A', 'slug' => self::AGENCY_A_SLUG, 'country_code' => 'SK']);
        $admin = User::factory()->agencyAdmin($agency)->create(['email' => self::ADMIN_EMAIL, 'name' => 'Ada Admin', 'password' => self::PASSWORD]);
        User::factory()->agencyManager($agency)->create(['email' => self::MANAGER_EMAIL, 'name' => 'Max Manager', 'password' => self::PASSWORD]);
        User::factory()->agencyStaff($agency)->create(['email' => self::STAFF_EMAIL, 'name' => 'Sam Staff', 'password' => self::PASSWORD]);

        $bolt = Brand::factory()->forAgency($agency->id)->create(['name' => 'Bolt Food', 'slug' => 'pl24-bolt-food', 'color' => 'green']);
        $make = fn (string $name, string $goLive, array $markets): Wave => Wave::factory()->forBrand($agency->id, $bolt->id)->withMarkets($markets)->create([
            'name' => $name, 'go_live_on' => $goLive, 'created_by_user_id' => $admin->id,
        ]);

        $w1 = $make('Markets Wave 1', '2027-01-09', [
            ['name' => 'Slovakia', 'code' => 'SK', 'countries' => ['SK']],
            ['name' => 'UK & Ireland', 'code' => 'UKI', 'countries' => ['GB', 'IE']],
            ['name' => 'Nordics', 'code' => 'NOR', 'countries' => ['SE', 'NO', 'DK', 'FI']],
        ]);
        $usps = $this->usps($w1, 6);
        $sk1 = $this->persona($w1, 'SK', 'The Office Lunch Organiser', 'Zuzana', PersonaPronoun::She, 'shared services, Bratislava');
        $this->persona($w1, 'SK', 'The Value Seeker', 'Peter', PersonaPronoun::He, 'good food, fair price, Košice');
        $uki1 = $this->persona($w1, 'UKI', 'The Commuter Who Orders Ahead', 'Chloe', PersonaPronoun::She, 'orders from the train home');
        $uki2 = $this->persona($w1, 'UKI', 'The Student Flat', 'Declan', PersonaPronoun::He, 'four housemates, one order');
        $nor1 = $this->persona($w1, 'NOR', 'The Dark-Winter Homebody', 'Astrid', PersonaPronoun::She, 'it is dark at four, the couch wins');
        $nor2 = $this->persona($w1, 'NOR', 'The Cabin Weekender', 'Lars', PersonaPronoun::He, 'orders before the drive north');

        $this->matrix($bolt, $w1, $admin, 'UKI', [
            [$uki1, $usps[0], 'priority', 'Dinner is ordered before the train pulls in.', 'Ordered before the platform.'],
            [$uki1, $usps[3], 'support', 'It arrives when you do.', 'There when you are.'],
            [$uki2, $usps[1], 'priority', 'Splitting the bill four ways still feels cheap.', 'Cheap, even split four ways.'],
        ]);
        $this->matrix($bolt, $w1, $admin, 'NOR', [
            [$nor1, $usps[4], 'priority', 'Everything for a night in, from one app.', 'A whole night in, one app.'],
            [$nor2, $usps[3], 'priority', 'Food waiting when the cabin door opens.', 'Waiting at the cabin door.'],
        ]);

        $approved = $this->concept($bolt, $w1, $admin, 'UKI', $uki1, 'Ordered before the platform', [
            'funnel_stage' => 'mofu', 'funnel_note' => 'Consideration', 'angle' => 'Dinner timed to the train',
            'primary_message' => 'Order from the carriage, eat when you get in.', 'hook' => 'Your dinner is already on its way.',
            'key_points' => ['Order from anywhere', 'Timed to arrive with you'], 'cta' => 'Order ahead',
        ]);
        $this->concept($bolt, $w1, $admin, 'UKI', $uki2, 'Four flatmates, one order', [
            'funnel_stage' => 'tofu', 'funnel_note' => 'Awareness', 'angle' => 'The group order that ends the argument', 'hook' => 'Whose turn is it to pick?',
        ]);
        $this->concept($bolt, $w1, $admin, 'NOR', $nor1, 'A night in, sorted', [
            'funnel_stage' => 'tofu', 'funnel_note' => 'Awareness', 'angle' => 'Hygge delivered', 'hook' => 'Four o\'clock and already dark.', 'cta' => 'Stay in',
        ]);
        app(ConceptDecisionService::class)->record($bolt, $w1, $approved->ulid, $admin, ['status' => 'approved', 'client_name' => 'Jana Nováková', 'note' => 'Good to go.']);
        unset($sk1);

        $make('Markets Wave 2', '2027-02-01', [['name' => 'Slovakia', 'code' => 'SK', 'countries' => ['SK']]]);

        $w3 = $make('Markets Wave 3', '2027-03-01', [['name' => self::HOSTILE_NAME, 'code' => 'HX', 'countries' => ['FR', 'DE']]]);
        $this->usps($w3, 2);
        $this->persona($w3, 'HX', 'The Hostile Market Persona', 'Hex', PersonaPronoun::They, 'a persona in a market with a hostile name');
    }

    private function agencyB(): void
    {
        $agency = AgencyFactory::new()->createOne(['name' => 'PL-2.4 Walk Agency B', 'slug' => self::AGENCY_B_SLUG, 'country_code' => 'SK']);
        $admin = User::factory()->agencyAdmin($agency)->create(['email' => self::B_ADMIN_EMAIL, 'name' => 'Bea Admin', 'password' => self::PASSWORD]);
        $glovo = Brand::factory()->forAgency($agency->id)->create(['name' => 'Glovo', 'slug' => 'pl24-glovo', 'color' => 'amber']);
        $wave = Wave::factory()->forBrand($agency->id, $glovo->id)->withMarkets([['name' => 'Iberia', 'code' => 'IBE', 'countries' => ['ES', 'PT']]])->create([
            'name' => 'Glovo Wave 1', 'go_live_on' => '2026-12-01', 'created_by_user_id' => $admin->id,
        ]);
        $this->usps($wave, 2);
        $this->persona($wave, 'IBE', 'The Late-Night Snacker', 'Marek', PersonaPronoun::He, 'orders after ten');
    }

    /**
     * @return list<WaveUsp>
     */
    private function usps(Wave $wave, int $count): array
    {
        $out = [];
        foreach (array_slice(self::USPS, 0, $count) as $title) {
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
     * @param  list<array{0: WavePersona, 1: WaveUsp, 2: string, 3: string, 4: string}>  $rows
     */
    private function matrix(Brand $brand, Wave $wave, User $actor, string $market, array $rows): void
    {
        $changes = array_map(static fn (array $r): array => [
            'persona' => $r[0]->ulid, 'usp' => $r[1]->ulid, 'level' => $r[2], 'rationale' => $r[3], 'message_line' => $r[4],
        ], $rows);
        app(MatrixService::class)->save($brand, $wave, $actor, WaveFactory::marketUlid($wave, $market), $changes);
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function concept(Brand $brand, Wave $wave, User $actor, string $market, WavePersona $persona, string $title, array $fields): WaveConcept
    {
        return app(ConceptService::class)->create($brand, $wave, $actor, [...$fields, 'market' => WaveFactory::marketUlid($wave, $market), 'persona' => $persona->ulid, 'title' => $title]);
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
            $this->say("Bolt Food: brand {$bolt->ulid}");
            foreach (Wave::query()->where('brand_id', $bolt->id)->orderBy('id')->get() as $wave) {
                $this->say("  {$wave->name}: /brands/{$bolt->ulid}/waves/{$wave->ulid}");
            }
        }
        $b = Agency::query()->where('slug', self::AGENCY_B_SLUG)->first();
        $glovo = $b === null ? null : Brand::query()->where('agency_id', $b->id)->first();
        $gw = $glovo === null ? null : Wave::query()->where('brand_id', $glovo->id)->first();
        if ($glovo !== null && $gw !== null) {
            $this->say("Glovo (Agency B): /brands/{$glovo->ulid}/waves/{$gw->ulid}");
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
