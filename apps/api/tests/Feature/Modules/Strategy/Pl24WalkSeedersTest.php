<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Features\StrategyEnabled;
use App\Modules\Strategy\Models\WaveConcept;
use App\Modules\Strategy\Models\WavePersona;
use App\Modules\Waves\Features\WavesEnabled;
use App\Modules\Waves\Models\Wave;
use App\Modules\Waves\Models\WaveMarket;
use Database\Seeders\Pl24WalkCleanupSeeder;
use Database\Seeders\Pl24WalkSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function pl24Wave(string $name): Wave
{
    $agency = Agency::query()->where('slug', Pl24WalkSeeder::AGENCY_A_SLUG)->firstOrFail();

    return Wave::query()->where('agency_id', $agency->id)->where('name', $name)->firstOrFail();
}

it('M1: seeds three roles and a second agency, leaves BOTH flags off, and is idempotent', function (): void {
    $this->seed(Pl24WalkSeeder::class);

    expect(User::query()->where('email', 'like', '%'.Pl24WalkSeeder::DOMAIN)->pluck('email')->sort()->values()->all())
        ->toBe(['admin@pl24-walk.local', 'manager@pl24-walk.local', 'staff@pl24-walk.local'])
        ->and(User::query()->where('email', 'like', '%'.Pl24WalkSeeder::DOMAIN_B)->count())->toBe(1)
        ->and(Feature::active(WavesEnabled::NAME))->toBeFalse()
        ->and(Feature::active(StrategyEnabled::NAME))->toBeFalse();

    $before = [Wave::query()->count(), WaveMarket::withoutGlobalScopes()->count(), WavePersona::withoutGlobalScopes()->count()];
    $this->seed(Pl24WalkSeeder::class);
    expect([Wave::query()->count(), WaveMarket::withoutGlobalScopes()->count(), WavePersona::withoutGlobalScopes()->count()])->toBe($before);
});

it('M2: wave 1 has Slovakia and the two multi-country markets, with personas and concepts in the multi-country ones', function (): void {
    $this->seed(Pl24WalkSeeder::class);
    $wave = pl24Wave('Markets Wave 1');
    $markets = WaveMarket::withoutGlobalScopes()->where('wave_id', $wave->id)->with('countries')->orderBy('position')->get();

    expect($markets->pluck('name')->all())->toBe(['Slovakia', 'UK & Ireland', 'Nordics'])
        ->and($markets->pluck('code')->all())->toBe(['SK', 'UKI', 'NOR']);
    $countries = [];
    foreach ($markets as $m) {
        $codes = $m->countryCodes();
        sort($codes);
        $countries[$m->code] = $codes;
    }
    expect($countries)->toBe(['SK' => ['SK'], 'UKI' => ['GB', 'IE'], 'NOR' => ['DK', 'FI', 'NO', 'SE']]);

    $codes = WavePersona::withoutGlobalScopes()->where('wave_id', $wave->id)->orderBy('id')->pluck('code')->all();
    expect($codes)->toContain('UKI1', 'UKI2', 'NOR1', 'NOR2', 'SK1');
    expect(WaveConcept::withoutGlobalScopes()->where('wave_id', $wave->id)->count())->toBe(3)
        ->and(DB::table('wave_matrix_cells')->where('wave_id', $wave->id)->count())->toBe(5);
});

it('M3: wave 2 is the minimum, wave 3 has the hostile market name verbatim, agency B has its own wave', function (): void {
    $this->seed(Pl24WalkSeeder::class);

    expect(WaveMarket::withoutGlobalScopes()->where('wave_id', pl24Wave('Markets Wave 2')->id)->count())->toBe(1)
        ->and(WavePersona::withoutGlobalScopes()->where('wave_id', pl24Wave('Markets Wave 2')->id)->count())->toBe(0)
        ->and(WaveMarket::withoutGlobalScopes()->where('wave_id', pl24Wave('Markets Wave 3')->id)->value('name'))->toBe(Pl24WalkSeeder::HOSTILE_NAME);

    $b = Agency::query()->where('slug', Pl24WalkSeeder::AGENCY_B_SLUG)->firstOrFail();
    expect(Wave::withoutGlobalScopes()->where('agency_id', $b->id)->count())->toBe(1);
});

it('M4: the cleanup removes exactly the walk rows and nothing else, twice', function (): void {
    $other = Wave::factory()->create();
    $this->seed(Pl24WalkSeeder::class);
    $this->seed(Pl24WalkCleanupSeeder::class);
    $this->seed(Pl24WalkCleanupSeeder::class);

    expect(Agency::query()->whereIn('slug', [Pl24WalkSeeder::AGENCY_A_SLUG, Pl24WalkSeeder::AGENCY_B_SLUG])->count())->toBe(0)
        ->and(WaveMarket::withoutGlobalScopes()->count())->toBe(1)
        ->and(Wave::withoutGlobalScopes()->whereKey($other->id)->exists())->toBeTrue()
        ->and(User::query()->where('email', 'like', '%@pl24-walk%')->count())->toBe(0);
});
