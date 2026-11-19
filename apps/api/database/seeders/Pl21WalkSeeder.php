<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Agencies\Database\Factories\AgencyFactory;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Brands\Models\Brand;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Enums\PersonaPronoun;
use App\Modules\Strategy\Models\WavePersona;
use App\Modules\Strategy\Models\WavePersonaAngle;
use App\Modules\Strategy\Models\WaveUsp;
use App\Modules\Waves\Models\Wave;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * PL-2.1 — local data for the strategy workspace walk (plan section 10).
 *
 * Agency A "PL-2.1 Walk Agency A": admin@, manager@, staff@ (all `@pl21-walk.local`); brand Bolt Food with
 *   - Wave 6  (SK, RO):     no strategy data at all (the empty states);
 *   - Wave 7  (SK, RO, CZ): six USPs (the maximum) with the audience title "Bolt Food Eaters — 6 core USPs"; SK has two
 *                            personas with angles, RO and CZ none ("personas in one market only");
 *   - Wave 8  (SK, RO):     empty (the walk builds it by hand: add USP, add persona, copy);
 *   - Wave 9  (SK, RO):     three USPs (under four: the warning), SK one persona (under two), RO eight personas (the
 *                            maximum), the first RO persona has an angle that uses USP 1 (the delete-in-use proof);
 *   - Wave 10 (SK, RO):     five USPs, SK three personas, RO six, one angle on USP 2.
 *
 * Agency B "PL-2.1 Walk Agency B": admin@pl21-walk-b.local, brand Glovo, "Glovo Wave 1" with two USPs (isolation proof).
 *
 * Every account lives under `@pl21-walk.local` or `@pl21-walk-b.local`, which is what {@see Pl21WalkCleanupSeeder}
 * removes it by. It does NOT touch any flag (`waves_enabled` and `strategy_enabled` stay at their default, OFF): turn
 * them on and off by hand in the admin app. Additive: it never touches a row it did not make.
 *
 *     php artisan db:seed --class='Database\Seeders\Pl21WalkSeeder'
 *
 * GUARDED to local + testing only. Idempotent: a second run changes nothing. It prints the ulids the walk needs.
 * The persona codes are the defaults the server gives (market plus slot: SK1, SK2, RO1 ...), as the frame shows.
 */
final class Pl21WalkSeeder extends Seeder
{
    public const string DOMAIN = '@pl21-walk.local';

    public const string DOMAIN_B = '@pl21-walk-b.local';

    public const string AGENCY_A_SLUG = 'pl21-walk-agency-a';

    public const string AGENCY_B_SLUG = 'pl21-walk-agency-b';

    public const string PASSWORD = 'password-12chars';

    public const string ADMIN_EMAIL = 'admin'.self::DOMAIN;

    public const string MANAGER_EMAIL = 'manager'.self::DOMAIN;

    public const string STAFF_EMAIL = 'staff'.self::DOMAIN;

    public const string B_ADMIN_EMAIL = 'admin'.self::DOMAIN_B;

    public const string AUDIENCE_TITLE = 'Bolt Food Eaters — 6 core USPs';

    /** @var list<array{title: string, explainer: string, angle: string, seed: string}> */
    private const array USPS = [
        ['title' => 'Delivered in 30 minutes', 'explainer' => 'Hot food at the door in half an hour, or the delivery is free.', 'angle' => 'Show the clock beating the sofa-to-door distance.', 'seed' => '"Dinner before the episode ends."'],
        ['title' => 'Restaurants you already love', 'explainer' => 'The local places people queue for, in one app.', 'angle' => 'A favourite order arriving without leaving home.', 'seed' => '"Your usual, brought to you."'],
        ['title' => 'No hidden fees', 'explainer' => 'The price you see is the price you pay.', 'angle' => 'A receipt with nothing added at the end.', 'seed' => '"What you see is what you pay."'],
        ['title' => 'Live order tracking', 'explainer' => 'Watch the courier on the map from kitchen to door.', 'angle' => 'A phone, a moving dot, a relaxed viewer.', 'seed' => '"Know exactly when it arrives."'],
        ['title' => 'Student and family deals', 'explainer' => 'Weekly offers that make a regular order cheaper.', 'angle' => 'A shared table, a short bill.', 'seed' => '"More plates, same price."'],
        ['title' => 'Late-night kitchens', 'explainer' => 'Open when other places have shut.', 'angle' => 'A lit window after midnight.', 'seed' => '"Hungry at 1am? Sorted."'],
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Pl21WalkSeeder is only allowed in local and testing environments.');
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
            'name' => 'PL-2.1 Walk Agency A',
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

        $bolt = Brand::factory()->forAgency($agency->id)->create(['name' => 'Bolt Food', 'slug' => 'pl21-bolt-food', 'color' => 'green']);

        $make = fn (string $name, string $goLive, array $markets): Wave => Wave::factory()->forBrand($agency->id, $bolt->id)->create([
            'name' => $name, 'go_live_on' => $goLive, 'markets' => $markets, 'created_by_user_id' => $admin->id,
        ]);

        // Wave 6: no strategy data.
        $make('Wave 6', '2026-12-15', ['SK', 'RO']);

        // Wave 7: six USPs, audience title, SK has two personas with angles.
        $wave7 = $make('Wave 7', '2027-01-09', ['SK', 'RO', 'CZ']);
        $wave7->forceFill(['usp_audience_title' => self::AUDIENCE_TITLE])->save();
        $usps7 = $this->usps($wave7, 6);
        $zuzana = $this->persona($wave7, 'SK', 'The Busy Parent', 'Zuzana', PersonaPronoun::She, [
            'summary' => 'Two children, a full-time job and no time to cook on weekdays.',
            'core_job' => 'Get a proper dinner on the table without a second shift in the kitchen.',
            'need' => 'A reliable dinner that arrives when she says it will.',
            'drivers' => ['Relief at the end of a long day', 'Pride in feeding the family well'],
            'goals' => ['Family dinner together', 'Fewer weekday arguments about food'],
            'self_talk' => ['I cannot cook again tonight', 'They will eat anything if it is warm'],
            'barriers' => ['Delivery fees add up', 'Cold food on arrival'],
        ]);
        $marek = $this->persona($wave7, 'SK', 'The Night-Shift Student', 'Marek', PersonaPronoun::He, [
            'summary' => 'Studies by day, works late, eats whenever the kitchen is open.',
            'core_job' => 'Find something good to eat after everything else has closed.',
            'need' => 'Cheap, filling food at odd hours.',
            'drivers' => ['Comfort after a long shift'],
            'goals' => ['Stay within a student budget'],
            'self_talk' => ['Nothing is open at this hour'],
            'barriers' => ['Late-night surcharges'],
        ]);
        $this->angle($zuzana, $usps7[0], 'Dinner arrives before the children start asking.');
        $this->angle($zuzana, $usps7[2], 'The price at checkout is the price on the doorstep.');
        $this->angle($marek, $usps7[5], 'The one lit kitchen on the street at 1am.');

        // Wave 8: empty on purpose.
        $make('Wave 8', '2027-02-01', ['SK', 'RO']);

        // Wave 9: three USPs (warning), SK one persona (warning), RO eight (maximum), the first RO persona uses USP 1.
        $wave9 = $make('Wave 9', '2027-02-20', ['SK', 'RO']);
        $usps9 = $this->usps($wave9, 3);
        $this->persona($wave9, 'SK', 'The Lunch-Break Office Worker', 'Lenka', PersonaPronoun::She, ['summary' => 'Thirty minutes to eat and get back to the desk.']);
        $names = ['Radu', 'Ioana', 'Mihai', 'Elena', 'Andrei', 'Maria', 'Vlad', 'Ana'];
        $pronouns = [PersonaPronoun::He, PersonaPronoun::She, PersonaPronoun::He, PersonaPronoun::She, PersonaPronoun::He, PersonaPronoun::She, PersonaPronoun::They, PersonaPronoun::She];
        $first = null;
        foreach ($names as $i => $name) {
            $persona = $this->persona($wave9, 'RO', 'Persona '.($i + 1).' of Romania', $name, $pronouns[$i], ['summary' => "{$name} orders delivery a few times a week."]);
            $first ??= $persona;
        }
        $this->angle($first, $usps9[0], 'The half-hour promise is the whole reason {name} stays with the app.');

        // Wave 10: five USPs, SK three personas, RO six, one angle on USP 2.
        $wave10 = $make('Wave 10', '2027-03-15', ['SK', 'RO']);
        $usps10 = $this->usps($wave10, 5);
        $sk = [];
        foreach (['Jana' => PersonaPronoun::She, 'Peter' => PersonaPronoun::He, 'Alex' => PersonaPronoun::They] as $name => $pronoun) {
            $sk[] = $this->persona($wave10, 'SK', 'Slovak persona '.(count($sk) + 1), $name, $pronoun, []);
        }
        foreach (['Bogdan', 'Carmen', 'Dan', 'Eva', 'Florin', 'Gabi'] as $i => $name) {
            $this->persona($wave10, 'RO', 'Romanian persona '.($i + 1), $name, PersonaPronoun::They, []);
        }
        $this->angle($sk[0], $usps10[1], 'Her favourite restaurant, without the trip.');
    }

    private function agencyB(): void
    {
        $agency = AgencyFactory::new()->createOne([
            'name' => 'PL-2.1 Walk Agency B',
            'slug' => self::AGENCY_B_SLUG,
            'country_code' => 'SK',
        ]);
        $admin = User::factory()->agencyAdmin($agency)->create([
            'email' => self::B_ADMIN_EMAIL, 'name' => 'Bea Admin', 'password' => self::PASSWORD,
        ]);
        $glovo = Brand::factory()->forAgency($agency->id)->create(['name' => 'Glovo', 'slug' => 'pl21-glovo', 'color' => 'amber']);
        $wave = Wave::factory()->forBrand($agency->id, $glovo->id)->create([
            'name' => 'Glovo Wave 1', 'go_live_on' => '2026-12-01', 'markets' => ['SK', 'RO'], 'created_by_user_id' => $admin->id,
        ]);
        $this->usps($wave, 2);
    }

    /**
     * @return list<WaveUsp>
     */
    private function usps(Wave $wave, int $count): array
    {
        $out = [];
        foreach (array_slice(self::USPS, 0, $count) as $row) {
            $out[] = WaveUsp::factory()->create([
                'wave_id' => $wave->id,
                'agency_id' => $wave->agency_id,
                'title' => $row['title'],
                'explainer' => $row['explainer'],
                'creative_angle' => $row['angle'],
                'copy_seed' => $row['seed'],
            ]);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function persona(Wave $wave, string $market, string $title, string $name, PersonaPronoun $pronoun, array $extra): WavePersona
    {
        return WavePersona::factory()->create([
            'wave_id' => $wave->id,
            'agency_id' => $wave->agency_id,
            'market' => $market,
            'title' => $title,
            'name' => $name,
            'pronoun' => $pronoun,
            ...$extra,
        ]);
    }

    private function angle(WavePersona $persona, WaveUsp $usp, string $explanation): void
    {
        WavePersonaAngle::factory()->create([
            'persona_id' => $persona->id,
            'wave_id' => $persona->wave_id,
            'agency_id' => $persona->agency_id,
            'usp_id' => $usp->id,
            'explanation' => str_replace('{name}', $persona->name, $explanation),
        ]);
    }

    private function printUlids(): void
    {
        $agency = Agency::query()->where('slug', self::AGENCY_A_SLUG)->first();
        if ($agency === null) {
            return;
        }
        $this->command->line("Agency A: {$agency->ulid}  (password for every account: ".self::PASSWORD.')');
        $bolt = Brand::query()->where('agency_id', $agency->id)->where('name', 'Bolt Food')->first();
        if ($bolt !== null) {
            $this->command->line("Bolt Food: brand {$bolt->ulid}");
            foreach (Wave::query()->where('brand_id', $bolt->id)->orderBy('id')->get() as $wave) {
                $this->command->line("  {$wave->name}: /brands/{$bolt->ulid}/waves/{$wave->ulid}/strategy/usps");
            }
        }
        $b = Agency::query()->where('slug', self::AGENCY_B_SLUG)->first();
        $glovo = $b === null ? null : Brand::query()->where('agency_id', $b->id)->first();
        $gw = $glovo === null ? null : Wave::query()->where('brand_id', $glovo->id)->first();
        if ($glovo !== null && $gw !== null) {
            $this->command->line("Glovo (Agency B): /brands/{$glovo->ulid}/waves/{$gw->ulid}/strategy/usps");
        }
    }
}
