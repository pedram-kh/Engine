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
use App\Modules\Strategy\Services\MatrixService;
use App\Modules\Waves\Models\Wave;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * PL-2.2 — local data for the messaging matrix walk (plan section 10). Self-contained: it does not need the PL-2.1 walk.
 *
 * Agency A "PL-2.2 Walk Agency A": admin@, manager@, staff@ (all `@pl22-walk.local`); brand Bolt Food with
 *   - Matrix Wave 1 (SK, RO, CZ): six USPs (the frame's order and colours). SK has the frame's two personas SK1 and SK2 with
 *       7 of 12 cells set (Low, Support and Priority all present) and 5 left "Not set"; SK2 has a HAND-WRITTEN angle on
 *       Selection whose cell is Priority (the conflict case). RO has two personas: RO1 with a Priority cell and RO2 with
 *       only Support and Low cells (a persona with no Priority cell: "Lead with: —"). CZ has no persona (an empty market).
 *   - Matrix Wave 2 (SK, RO):     empty: the walk builds it by hand.
 *   - Matrix Wave 3 (SK, RO):     three USPs, SK one persona, RO EIGHT personas (the sideways scroll), two cells set.
 *
 * Agency B "PL-2.2 Walk Agency B": admin@pl22-walk-b.local, brand Glovo, one wave with two USPs and no persona (isolation).
 *
 * The cells are written through {@see MatrixService}, the same code the screen calls, so every Priority and Support cell
 * has its synced angle. Persona codes are the defaults the server gives (market plus slot: SK1, SK2, RO1 ...). It does NOT
 * touch any flag: `waves_enabled` and `strategy_enabled` stay as they are; turn them on and off by hand in the admin app.
 *
 *     php artisan db:seed --class='Database\Seeders\Pl22WalkSeeder'
 *
 * GUARDED to local + testing. Idempotent: a second run changes nothing. Removed by {@see Pl22WalkCleanupSeeder}.
 */
final class Pl22WalkSeeder extends Seeder
{
    public const string DOMAIN = '@pl22-walk.local';

    public const string DOMAIN_B = '@pl22-walk-b.local';

    public const string AGENCY_A_SLUG = 'pl22-walk-agency-a';

    public const string AGENCY_B_SLUG = 'pl22-walk-agency-b';

    public const string PASSWORD = 'password-12chars';

    public const string ADMIN_EMAIL = 'admin'.self::DOMAIN;

    public const string MANAGER_EMAIL = 'manager'.self::DOMAIN;

    public const string STAFF_EMAIL = 'staff'.self::DOMAIN;

    public const string B_ADMIN_EMAIL = 'admin'.self::DOMAIN_B;

    /** The frame's six USPs, in order (their colours follow the position: violet, green, blue, amber, teal, pink). */
    private const array USPS = [
        'Category entry points', 'Affordability', 'Selection', 'Speed & reliability', 'Super app convenience', 'Local restaurant discovery',
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Pl22WalkSeeder is only allowed in local and testing environments.');
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
        $agency = AgencyFactory::new()->createOne(['name' => 'PL-2.2 Walk Agency A', 'slug' => self::AGENCY_A_SLUG, 'country_code' => 'SK']);
        $admin = User::factory()->agencyAdmin($agency)->create(['email' => self::ADMIN_EMAIL, 'name' => 'Ada Admin', 'password' => self::PASSWORD]);
        User::factory()->agencyManager($agency)->create(['email' => self::MANAGER_EMAIL, 'name' => 'Max Manager', 'password' => self::PASSWORD]);
        User::factory()->agencyStaff($agency)->create(['email' => self::STAFF_EMAIL, 'name' => 'Sam Staff', 'password' => self::PASSWORD]);

        $bolt = Brand::factory()->forAgency($agency->id)->create(['name' => 'Bolt Food', 'slug' => 'pl22-bolt-food', 'color' => 'green']);
        $make = fn (string $name, string $goLive, array $markets): Wave => Wave::factory()->forBrand($agency->id, $bolt->id)->create([
            'name' => $name, 'go_live_on' => $goLive, 'markets' => $markets, 'created_by_user_id' => $admin->id,
        ]);

        // Matrix Wave 1: the frame's data.
        $w1 = $make('Matrix Wave 1', '2027-01-09', ['SK', 'RO', 'CZ']);
        $usps = $this->usps($w1, 6);
        $sk1 = $this->persona($w1, 'SK', 'The Office Lunch Organiser', 'Zuzana', PersonaPronoun::She, 'shared services, Bratislava');
        $sk2 = $this->persona($w1, 'SK', 'The Value Seeker', 'Peter', PersonaPronoun::He, 'good food, fair price, Košice');
        $ro1 = $this->persona($w1, 'RO', 'The Busy Parent', 'Ioana', PersonaPronoun::She, 'two children, no time to cook');
        $ro2 = $this->persona($w1, 'RO', 'The Night-Shift Student', 'Radu', PersonaPronoun::He, 'studies by day, eats late');

        // The conflict case: a hand-written angle on a pair whose cell is Priority (SK2 on Selection).
        WavePersonaAngle::factory()->create([
            'persona_id' => $sk2->id, 'wave_id' => $w1->id, 'agency_id' => $agency->id, 'usp_id' => $usps[2]->id,
            'explanation' => 'Written by hand: the range is why he stays.',
        ]);

        $this->cells($bolt, $w1, $admin, 'SK', [
            [$sk1, $usps[0], 'low', 'Team ordering is the job, not a tired default.', 'Sort the team lunch.'],
            [$sk2, $usps[0], 'low', 'Not a moment play, it is about value.', 'The easy good value dinner.'],
            [$sk1, $usps[1], 'support', 'A team lunch that respects the office budget.', 'Kind to the office budget.'],
            [$sk2, $usps[1], 'priority', 'Great food that does not make you pay over the odds.', 'Great food, still fair.'],
            [$sk1, $usps[2], 'priority', 'Something for the whole team.', 'Always something worth it.'],
            [$sk2, $usps[2], 'priority', 'The range to always find something worth it.', 'Always something worth it.'],
            [$sk1, $usps[3], 'priority', 'On time for the meeting, every time.', 'On time for the meeting.'],
        ]);
        $this->cells($bolt, $w1, $admin, 'RO', [
            [$ro1, $usps[0], 'priority', 'Dinner is sorted before the children ask.', 'Dinner, sorted.'],
            [$ro1, $usps[1], 'support', 'The price at checkout is the price at the door.', 'No surprises at the door.'],
            [$ro2, $usps[3], 'support', 'Food that arrives while he is still awake.', 'Fast when it counts.'],
            [$ro2, $usps[5], 'low', 'Not what he is looking for.', 'A local spot.'],
        ]);

        // Matrix Wave 2: empty on purpose.
        $make('Matrix Wave 2', '2027-02-01', ['SK', 'RO']);

        // Matrix Wave 3: the sideways scroll (RO has eight personas).
        $w3 = $make('Matrix Wave 3', '2027-02-20', ['SK', 'RO']);
        $usps3 = $this->usps($w3, 3);
        $lenka = $this->persona($w3, 'SK', 'The Lunch-Break Office Worker', 'Lenka', PersonaPronoun::She, 'thirty minutes to eat');
        $first = null;
        foreach (['Radu', 'Ioana', 'Mihai', 'Elena', 'Andrei', 'Maria', 'Vlad', 'Ana'] as $i => $name) {
            $p = $this->persona($w3, 'RO', 'Romanian persona '.($i + 1), $name, PersonaPronoun::They, 'orders a few times a week');
            $first ??= $p;
        }
        $this->cells($bolt, $w3, $admin, 'SK', [[$lenka, $usps3[0], 'priority', 'Back at the desk on time.', 'Lunch in thirty minutes.']]);
        if ($first !== null) {
            $this->cells($bolt, $w3, $admin, 'RO', [[$first, $usps3[1], 'support', 'Good value, every week.', 'Value every week.']]);
        }
    }

    private function agencyB(): void
    {
        $agency = AgencyFactory::new()->createOne(['name' => 'PL-2.2 Walk Agency B', 'slug' => self::AGENCY_B_SLUG, 'country_code' => 'SK']);
        $admin = User::factory()->agencyAdmin($agency)->create(['email' => self::B_ADMIN_EMAIL, 'name' => 'Bea Admin', 'password' => self::PASSWORD]);
        $glovo = Brand::factory()->forAgency($agency->id)->create(['name' => 'Glovo', 'slug' => 'pl22-glovo', 'color' => 'amber']);
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
        foreach (array_slice(self::USPS, 0, $count) as $title) {
            $out[] = WaveUsp::factory()->create(['wave_id' => $wave->id, 'agency_id' => $wave->agency_id, 'title' => $title]);
        }

        return $out;
    }

    private function persona(Wave $wave, string $market, string $title, string $name, PersonaPronoun $pronoun, string $summary): WavePersona
    {
        return WavePersona::factory()->create([
            'wave_id' => $wave->id, 'agency_id' => $wave->agency_id, 'market' => $market,
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
        app(MatrixService::class)->save($brand, $wave, $actor, $market, $changes);
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
                $this->command->line("  {$wave->name}: /brands/{$bolt->ulid}/waves/{$wave->ulid}/strategy/matrix");
            }
        }
        $b = Agency::query()->where('slug', self::AGENCY_B_SLUG)->first();
        $glovo = $b === null ? null : Brand::query()->where('agency_id', $b->id)->first();
        $gw = $glovo === null ? null : Wave::query()->where('brand_id', $glovo->id)->first();
        if ($glovo !== null && $gw !== null) {
            $this->command->line("Glovo (Agency B): /brands/{$glovo->ulid}/waves/{$gw->ulid}/strategy/matrix");
        }
    }
}
