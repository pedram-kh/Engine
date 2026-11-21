<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Agencies\Database\Factories\AgencyFactory;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyMembership;
use App\Modules\Brands\Models\Brand;
use App\Modules\Identity\Models\User;
use App\Modules\Waves\Database\Factories\WaveFactory;
use App\Modules\Waves\Models\Wave;
use App\Modules\Waves\Services\WaveStepProvisioner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * PL-1.3 — local data for the wave tracker walk (plan section 10).
 *
 * Agency A "PL-1.3 Walk Agency A": admin@, manager@, staff@, designer@ (an extra staff member to pick as an owner) and
 * former@ (staff whose membership is removed after it owns a step); brand Bolt Food with
 *   - Wave 4: provisioned, the Statics stream already removed (the removed-stream proof);
 *   - Wave 5: provisioned, all six strategy steps done ("Complete" on the Brand page);
 *   - Wave 6: an EXISTING wave with NO steps and NO marker (the first-open proof);
 *   - Wave 8: provisioned, USPs done, Personas in progress, a step three days overdue, one due today, one owned by
 *     the former member, one owned by "Client" (the bar, delayed and former-member proofs).
 * Wave 7 is created in the walk.
 *
 * Agency B "PL-1.3 Walk Agency B": admin@pl13-walk-b.local, brand Glovo, "Glovo Wave 1" with steps (isolation proof).
 *
 * Every account lives under `@pl13-walk.local` or `@pl13-walk-b.local`, which is what {@see Pl13WalkCleanupSeeder}
 * removes it by. It does NOT touch the `waves_enabled` flag (it stays at its default, OFF): turn it on and off by
 * hand in the admin app. Additive: it never touches a row it did not make.
 *
 *     php artisan db:seed --class='Database\Seeders\Pl13WalkSeeder'
 *
 * GUARDED to local + testing only. Idempotent: a second run changes nothing. It prints the ulids the walk needs.
 */
final class Pl13WalkSeeder extends Seeder
{
    public const string DOMAIN = '@pl13-walk.local';

    public const string DOMAIN_B = '@pl13-walk-b.local';

    public const string AGENCY_A_SLUG = 'pl13-walk-agency-a';

    public const string AGENCY_B_SLUG = 'pl13-walk-agency-b';

    public const string PASSWORD = 'password-12chars';

    public const string ADMIN_EMAIL = 'admin'.self::DOMAIN;

    public const string MANAGER_EMAIL = 'manager'.self::DOMAIN;

    public const string STAFF_EMAIL = 'staff'.self::DOMAIN;

    public const string DESIGNER_EMAIL = 'designer'.self::DOMAIN;

    public const string FORMER_EMAIL = 'former'.self::DOMAIN;

    public const string B_ADMIN_EMAIL = 'admin'.self::DOMAIN_B;

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Pl13WalkSeeder is only allowed in local and testing environments.');
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
            'name' => 'PL-1.3 Walk Agency A',
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
        $designer = User::factory()->agencyStaff($agency)->create([
            'email' => self::DESIGNER_EMAIL, 'name' => 'Dee Designer', 'password' => self::PASSWORD,
        ]);
        $former = User::factory()->agencyStaff($agency)->create([
            'email' => self::FORMER_EMAIL, 'name' => 'Fay Former', 'password' => self::PASSWORD,
        ]);

        $bolt = Brand::factory()->forAgency($agency->id)->create(['name' => 'Bolt Food', 'slug' => 'pl13-bolt-food', 'color' => 'green']);

        $make = fn (string $name, string $goLive, array $markets): Wave => Wave::factory()->forBrand($agency->id, $bolt->id)->withMarkets(WaveFactory::singleCountryMarkets($markets))->create([
            'name' => $name, 'go_live_on' => $goLive, 'created_by_user_id' => $admin->id,
        ]);

        $provisioner = app(WaveStepProvisioner::class);

        // Wave 4: provisioned, then the Statics stream removed.
        $wave4 = $make('Wave 4', '2026-11-20', ['SK']);
        $provisioner->ensure($wave4);
        DB::table('wave_steps')->where('wave_id', $wave4->id)->where('stream', 'statics')->delete();

        // Wave 5: every strategy step done.
        $wave5 = $make('Wave 5', '2026-12-01', ['SK', 'CZ']);
        $provisioner->ensure($wave5);
        $this->done($wave5, ['ugc.usps', 'ugc.personas', 'ugc.matrix', 'ugc.concepts', 'ugc.creators', 'ugc.scripts']);

        // Wave 6: an existing wave, no steps and no marker. Nothing is provisioned here, on purpose.
        $make('Wave 6', '2026-12-15', ['SK', 'RO']);

        // Wave 8: the busy one.
        $wave8 = $make('Wave 8', '2027-01-09', ['SK', 'RO']);
        $provisioner->ensure($wave8);
        $this->done($wave8, ['ugc.usps']);
        $this->set($wave8, 'ugc.personas', ['status' => 'in_progress', 'status_source' => 'manual', 'owner_user_id' => $designer->id]);

        $statics = DB::table('wave_steps')->where('wave_id', $wave8->id)->where('stream', 'statics')->orderBy('position')->pluck('id')->all();
        $stills = DB::table('wave_steps')->where('wave_id', $wave8->id)->where('stream', 'stills')->orderBy('position')->pluck('id')->all();
        $ugc = DB::table('wave_steps')->where('wave_id', $wave8->id)->where('stream', 'ugc_video')->where('position', '>', 70)->orderBy('position')->pluck('id')->all();

        // Three days overdue (the delayed proof), due today, owned by a former member, owned by "Client".
        DB::table('wave_steps')->where('id', $statics[0])->update(['due_on' => now()->subDays(3)->toDateString(), 'status' => 'in_progress', 'status_source' => 'manual']);
        DB::table('wave_steps')->where('id', $stills[0])->update(['due_on' => now()->toDateString()]);
        DB::table('wave_steps')->where('id', $ugc[0])->update(['owner_user_id' => $former->id, 'owner_client' => false]);
        DB::table('wave_steps')->where('id', $ugc[1])->update(['owner_user_id' => null, 'owner_client' => true]);

        // The former member: the membership is removed (soft delete) AFTER the step was given to them.
        AgencyMembership::query()->where('agency_id', $agency->id)->where('user_id', $former->id)->delete();
    }

    private function agencyB(): void
    {
        $agency = AgencyFactory::new()->createOne([
            'name' => 'PL-1.3 Walk Agency B',
            'slug' => self::AGENCY_B_SLUG,
            'country_code' => 'SK',
        ]);
        $admin = User::factory()->agencyAdmin($agency)->create([
            'email' => self::B_ADMIN_EMAIL, 'name' => 'Bea Admin', 'password' => self::PASSWORD,
        ]);
        $glovo = Brand::factory()->forAgency($agency->id)->create(['name' => 'Glovo', 'slug' => 'pl13-glovo', 'color' => 'amber']);
        $wave = Wave::factory()->forBrand($agency->id, $glovo->id)->withMarkets(WaveFactory::singleCountryMarkets(['SK']))->create([
            'name' => 'Glovo Wave 1', 'go_live_on' => '2026-12-01', 'created_by_user_id' => $admin->id,
        ]);
        app(WaveStepProvisioner::class)->ensure($wave);
    }

    /**
     * @param  list<string>  $keys
     */
    private function done(Wave $wave, array $keys): void
    {
        foreach ($keys as $key) {
            $this->set($wave, $key, ['status' => 'done', 'status_source' => 'manual', 'completed_on' => now()->subDays(2)->toDateString()]);
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function set(Wave $wave, string $key, array $values): void
    {
        DB::table('wave_steps')->where('wave_id', $wave->id)->where('step_key', $key)->update($values);
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
            foreach (Wave::query()->where('brand_id', $bolt->id)->orderBy('name')->get() as $wave) {
                $this->command->line("  {$wave->name}: /brands/{$bolt->ulid}/waves/{$wave->ulid}");
            }
        }
        $b = Agency::query()->where('slug', self::AGENCY_B_SLUG)->first();
        $glovo = $b === null ? null : Brand::query()->where('agency_id', $b->id)->first();
        $gw = $glovo === null ? null : Wave::query()->where('brand_id', $glovo->id)->first();
        if ($glovo !== null && $gw !== null) {
            $this->command->line("Glovo (Agency B): /brands/{$glovo->ulid}/waves/{$gw->ulid}");
        }
    }
}
