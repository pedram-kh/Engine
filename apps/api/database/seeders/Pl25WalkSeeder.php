<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Agencies\Database\Factories\AgencyFactory;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyMembership;
use App\Modules\Brands\Models\Brand;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Enums\PersonaPronoun;
use App\Modules\Strategy\Models\WaveConcept;
use App\Modules\Strategy\Models\WavePersona;
use App\Modules\Strategy\Models\WaveUsp;
use App\Modules\Strategy\Notes\NoteService;
use App\Modules\Strategy\Services\CompletionService;
use App\Modules\Strategy\Services\ConceptDecisionService;
use App\Modules\Strategy\Services\ConceptService;
use App\Modules\Strategy\Services\MatrixService;
use App\Modules\Waves\Database\Factories\WaveFactory;
use App\Modules\Waves\Models\Wave;
use App\Modules\Waves\Models\WaveStep;
use App\Modules\Waves\Services\WaveStepProvisioner;
use App\Modules\Waves\Services\WaveStepService;
use App\Modules\Waves\Steps\WaveStepKey;
use Illuminate\Console\Command;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * PL-2.5 — local data for the "Connecting it up" walk (completion, notes, the tracker). Self-contained.
 *
 * Agency A "PL-2.5 Walk Agency A": admin@, manager@, staff@ (all `@pl25-walk.local`), plus a member who has since left
 * (their notes stay, shown as "Former member"). Brand Bolt Food with four waves:
 *   - Connect Wave 1 (two markets, Slovakia and Nordics): USPs complete (six, marked), Personas complete in Slovakia only,
 *       Messaging matrix in progress (cells set in Slovakia, not marked), Concepts with one approved and one not (Slovakia).
 *       Notes by Ada Admin, Max Manager and the former member on USPs, Personas, the matrix and Concepts.
 *   - Connect Wave 2: everything done (one market): USPs, Personas, matrix marked, every concept approved.
 *   - Connect Wave 3: nothing started.
 *   - Connect Wave 4: two USPs, no personas, and the tracker row "Personas" set by hand to Done beyond what the step has.
 *
 * Agency B "PL-2.5 Walk Agency B": admin@pl25-walk-b.local, brand Glovo, one wave with a USP and a note (isolation).
 *
 * Everything goes through the services the screens call. It does NOT touch any flag.
 *
 *     php artisan db:seed --class='Database\Seeders\Pl25WalkSeeder'
 *
 * GUARDED to local + testing. Idempotent. Removed by {@see Pl25WalkCleanupSeeder}.
 */
final class Pl25WalkSeeder extends Seeder
{
    public const string DOMAIN = '@pl25-walk.local';

    public const string DOMAIN_B = '@pl25-walk-b.local';

    public const string AGENCY_A_SLUG = 'pl25-walk-agency-a';

    public const string AGENCY_B_SLUG = 'pl25-walk-agency-b';

    public const string PASSWORD = 'password-12chars';

    public const string ADMIN_EMAIL = 'admin'.self::DOMAIN;

    public const string MANAGER_EMAIL = 'manager'.self::DOMAIN;

    public const string STAFF_EMAIL = 'staff'.self::DOMAIN;

    public const string FORMER_EMAIL = 'former'.self::DOMAIN;

    public const string B_ADMIN_EMAIL = 'admin'.self::DOMAIN_B;

    private const array USPS = [
        'Category entry points', 'Affordability', 'Selection', 'Speed & reliability', 'Super app convenience', 'Local restaurant discovery',
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Pl25WalkSeeder is only allowed in local and testing environments.');
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
        $agency = AgencyFactory::new()->createOne(['name' => 'PL-2.5 Walk Agency A', 'slug' => self::AGENCY_A_SLUG, 'country_code' => 'SK']);
        $admin = User::factory()->agencyAdmin($agency)->create(['email' => self::ADMIN_EMAIL, 'name' => 'Ada Admin', 'password' => self::PASSWORD]);
        $manager = User::factory()->agencyManager($agency)->create(['email' => self::MANAGER_EMAIL, 'name' => 'Max Manager', 'password' => self::PASSWORD]);
        User::factory()->agencyStaff($agency)->create(['email' => self::STAFF_EMAIL, 'name' => 'Sam Staff', 'password' => self::PASSWORD]);
        $former = User::factory()->agencyStaff($agency)->create(['email' => self::FORMER_EMAIL, 'name' => 'Fay Former', 'password' => self::PASSWORD]);

        $bolt = Brand::factory()->forAgency($agency->id)->create(['name' => 'Bolt Food', 'slug' => 'pl25-bolt-food', 'color' => 'green']);
        $completions = app(CompletionService::class);
        $notes = app(NoteService::class);

        // ── Wave 1: USPs complete, Personas complete in one market, matrix in progress, one concept not approved ──
        $w1 = $this->wave($agency, $bolt, $admin, 'Connect Wave 1', '2027-01-09', [
            ['name' => 'Slovakia', 'code' => 'SK', 'countries' => ['SK']],
            ['name' => 'Nordics', 'code' => 'NOR', 'countries' => ['SE', 'NO', 'DK', 'FI']],
        ]);
        $usps = $this->usps($w1, 6);
        $sk1 = $this->persona($w1, 'SK', 'The Office Lunch Organiser', 'Zuzana', PersonaPronoun::She, 'shared services, Bratislava');
        $sk2 = $this->persona($w1, 'SK', 'The Value Seeker', 'Peter', PersonaPronoun::He, 'good food, fair price, Košice');
        $this->persona($w1, 'NOR', 'The Dark-Winter Homebody', 'Astrid', PersonaPronoun::She, 'it is dark at four, the couch wins');
        $this->matrix($bolt, $w1, $admin, 'SK', [
            [$sk1, $usps[0], 'priority', 'Lunch for the whole floor, ordered in a minute.', 'Lunch for the floor.'],
            [$sk2, $usps[1], 'priority', 'Fair price, good food.', 'Fair, and good.'],
            [$sk2, $usps[3], 'support', 'It arrives when promised.', 'On time.'],
        ]);
        $approved = $this->concept($bolt, $w1, $admin, 'SK', $sk1, 'Lunch for the floor', [
            'funnel_stage' => 'mofu', 'funnel_note' => 'Consideration', 'angle' => 'One order, whole team',
            'primary_message' => 'Order once, feed everybody.', 'hook' => 'Whose turn is it to order?', 'cta' => 'Order lunch',
        ]);
        $this->concept($bolt, $w1, $admin, 'SK', $sk2, 'Fair price, good food', [
            'funnel_stage' => 'tofu', 'funnel_note' => 'Awareness', 'angle' => 'Value without compromise', 'hook' => 'Good food does not have to cost more.',
        ]);
        app(ConceptDecisionService::class)->record($bolt, $w1, $approved->ulid, $admin, ['status' => 'approved', 'client_name' => 'Jana Nováková', 'note' => 'Good to go.']);
        app(WaveStepProvisioner::class)->ensure($w1);
        $completions->mark($bolt, $w1, $admin, 'usps', null);
        $completions->mark($bolt, $w1, $admin, 'personas', WaveFactory::marketUlid($w1, 'SK'));

        $sk = WaveFactory::marketUlid($w1, 'SK');
        $notes->create($bolt, $w1, $admin, 'usps', null, "Client asked to lead with Affordability this wave.\nKeep it value-led, not price-led.");
        $notes->create($bolt, $w1, $manager, 'usps', null, 'Local discovery is new for this wave: test it in Slovakia first.');
        $notes->create($bolt, $w1, $former, 'usps', null, 'Legal flagged the word "cheap" last wave: avoid it in every script.');
        $notes->create($bolt, $w1, $admin, 'personas', $sk, 'Two personas is the minimum; a third (the student flat) would help.');
        $notes->create($bolt, $w1, $manager, 'matrix', $sk, 'Cells for Speed & reliability still need a better line.');
        $notes->create($bolt, $w1, $former, 'concepts', $sk, 'The second concept needs a client look before Friday.');
        // Fay leaves the agency AFTER writing: the membership is removed (soft delete), the notes stay.
        AgencyMembership::query()->where('agency_id', $agency->id)->where('user_id', $former->id)->delete();

        // ── Wave 2: everything done ──
        $w2 = $this->wave($agency, $bolt, $admin, 'Connect Wave 2', '2027-02-01', [['name' => 'Slovakia', 'code' => 'SK', 'countries' => ['SK']]]);
        $u2 = $this->usps($w2, 4);
        $p2 = $this->persona($w2, 'SK', 'The Weekend Family', 'Eva', PersonaPronoun::She, 'Saturday lunch for four');
        $p2b = $this->persona($w2, 'SK', 'The Night Owl', 'Tomáš', PersonaPronoun::He, 'orders after nine');
        $this->matrix($bolt, $w2, $admin, 'SK', [
            [$p2, $u2[0], 'priority', 'Everyone finds something they like.', 'Something for everyone.'],
            [$p2b, $u2[3], 'priority', 'Dinner at ten, still hot.', 'Hot at ten.'],
        ]);
        $c2 = $this->concept($bolt, $w2, $admin, 'SK', $p2, 'Saturday for four', [
            'funnel_stage' => 'mofu', 'funnel_note' => 'Consideration', 'angle' => 'The family order', 'hook' => 'Saturday, sorted.',
        ]);
        app(ConceptDecisionService::class)->record($bolt, $w2, $c2->ulid, $admin, ['status' => 'approved', 'client_name' => 'Jana Nováková', 'note' => 'Approved.']);
        app(WaveStepProvisioner::class)->ensure($w2);
        $sk2m = WaveFactory::marketUlid($w2, 'SK');
        $completions->mark($bolt, $w2, $admin, 'usps', null);
        $completions->mark($bolt, $w2, $admin, 'personas', $sk2m);
        $completions->mark($bolt, $w2, $admin, 'matrix', $sk2m);

        // ── Wave 3: nothing started ──
        $this->wave($agency, $bolt, $admin, 'Connect Wave 3', '2027-03-01', [['name' => 'Slovakia', 'code' => 'SK', 'countries' => ['SK']]]);

        // ── Wave 4: a tracker row set by hand beyond its real step ──
        $w4 = $this->wave($agency, $bolt, $admin, 'Connect Wave 4', '2027-04-01', [['name' => 'Slovakia', 'code' => 'SK', 'countries' => ['SK']]]);
        $this->usps($w4, 2);
        app(WaveStepProvisioner::class)->ensure($w4);
        $row = WaveStep::query()->where('wave_id', $w4->id)->where('step_key', WaveStepKey::UGC_PERSONAS)->first();
        if ($row === null) {
            throw new RuntimeException('The Personas tracker row was not provisioned.');
        }
        app(WaveStepService::class)->update($bolt, $w4, $row->ulid, $admin, ['status' => 'done', 'confirm_incomplete' => true]);
    }

    /**
     * @param  list<array{name: string, code?: string, countries: list<string>}>  $markets
     */
    private function wave(Agency $agency, Brand $brand, User $admin, string $name, string $goLive, array $markets): Wave
    {
        return Wave::factory()->forBrand($agency->id, $brand->id)->withMarkets($markets)->create([
            'name' => $name, 'go_live_on' => $goLive, 'created_by_user_id' => $admin->id,
        ]);
    }

    private function agencyB(): void
    {
        $agency = AgencyFactory::new()->createOne(['name' => 'PL-2.5 Walk Agency B', 'slug' => self::AGENCY_B_SLUG, 'country_code' => 'SK']);
        $admin = User::factory()->agencyAdmin($agency)->create(['email' => self::B_ADMIN_EMAIL, 'name' => 'Bea Admin', 'password' => self::PASSWORD]);
        $glovo = Brand::factory()->forAgency($agency->id)->create(['name' => 'Glovo', 'slug' => 'pl25-glovo', 'color' => 'amber']);
        $wave = Wave::factory()->forBrand($agency->id, $glovo->id)->withMarkets([['name' => 'Iberia', 'code' => 'IBE', 'countries' => ['ES', 'PT']]])->create([
            'name' => 'Glovo Wave 1', 'go_live_on' => '2026-12-01', 'created_by_user_id' => $admin->id,
        ]);
        $this->usps($wave, 2);
        app(NoteService::class)->create($glovo, $wave, $admin, 'usps', null, 'Agency B internal note: nobody in Agency A may ever see this.');
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
