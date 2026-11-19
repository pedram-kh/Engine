<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Brands\Models\Brand;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Features\StrategyEnabled;
use App\Modules\Strategy\Models\WavePersona;
use App\Modules\Strategy\Models\WavePersonaAngle;
use App\Modules\Strategy\Models\WaveUsp;
use App\Modules\Waves\Features\WavesEnabled;
use App\Modules\Waves\Models\Wave;
use Database\Seeders\Pl21WalkCleanupSeeder;
use Database\Seeders\Pl21WalkSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function pl21Wave(string $name): Wave
{
    $agency = Agency::query()->where('slug', Pl21WalkSeeder::AGENCY_A_SLUG)->firstOrFail();

    return Wave::query()->where('agency_id', $agency->id)->where('name', $name)->firstOrFail();
}

function pl21Usps(Wave $wave): int
{
    return WaveUsp::withoutGlobalScopes()->where('wave_id', $wave->id)->count();
}

function pl21Personas(Wave $wave, string $market): int
{
    return WavePersona::withoutGlobalScopes()->where('wave_id', $wave->id)->where('market', $market)->count();
}

it('S1: seeds both walk agencies and the accounts, leaves BOTH flags off, and is idempotent', function (): void {
    $this->seed(Pl21WalkSeeder::class);

    $a = Agency::query()->where('slug', Pl21WalkSeeder::AGENCY_A_SLUG)->firstOrFail();
    expect(Wave::query()->where('agency_id', $a->id)->orderBy('id')->pluck('name')->all())->toBe(['Wave 6', 'Wave 7', 'Wave 8', 'Wave 9', 'Wave 10'])
        ->and(Brand::query()->where('agency_id', $a->id)->pluck('name')->all())->toBe(['Bolt Food'])
        ->and(User::query()->where('email', 'like', '%'.Pl21WalkSeeder::DOMAIN)->pluck('email')->sort()->values()->all())
        ->toBe(['admin@pl21-walk.local', 'manager@pl21-walk.local', 'staff@pl21-walk.local'])
        ->and(User::query()->where('email', 'like', '%'.Pl21WalkSeeder::DOMAIN_B)->count())->toBe(1)
        ->and(Feature::active(WavesEnabled::NAME))->toBeFalse()
        ->and(Feature::active(StrategyEnabled::NAME))->toBeFalse();

    $count = static fn (): array => [
        DB::table('wave_usps')->count(), DB::table('wave_personas')->count(), DB::table('wave_persona_angles')->count(),
        DB::table('waves')->count(), DB::table('users')->count(),
    ];
    $before = $count();
    $this->seed(Pl21WalkSeeder::class);
    expect($count())->toBe($before);
});

it('S2: the waves hold the cases of the walk (two markets, under four and six USPs, one market with personas)', function (): void {
    $this->seed(Pl21WalkSeeder::class);

    $six = pl21Wave('Wave 6');
    $seven = pl21Wave('Wave 7');
    $eight = pl21Wave('Wave 8');
    $nine = pl21Wave('Wave 9');
    $ten = pl21Wave('Wave 10');

    expect($six->markets)->toBe(['SK', 'RO'])
        ->and(pl21Usps($six))->toBe(0)
        ->and(WavePersona::withoutGlobalScopes()->where('wave_id', $six->id)->count())->toBe(0)
        ->and($seven->markets)->toBe(['SK', 'RO', 'CZ'])
        ->and(pl21Usps($seven))->toBe(6)
        ->and($seven->usp_audience_title)->toBe('Bolt Food Eaters — 6 core USPs')
        ->and(pl21Personas($seven, 'SK'))->toBe(2)
        ->and(pl21Personas($seven, 'RO'))->toBe(0)
        ->and(pl21Personas($seven, 'CZ'))->toBe(0)
        ->and(pl21Usps($eight))->toBe(0)
        ->and(pl21Usps($nine))->toBeLessThan(4)
        ->and(pl21Usps($nine))->toBe(3)
        ->and(pl21Personas($nine, 'SK'))->toBe(1)
        ->and(pl21Personas($nine, 'RO'))->toBe(8)
        ->and(pl21Usps($ten))->toBe(5)
        ->and(pl21Personas($ten, 'SK'))->toBe(3)
        ->and(pl21Personas($ten, 'RO'))->toBe(6);
});

it('S3: a persona angle uses a USP in Wave 9 (delete-in-use) and in Wave 10, and every code is valid', function (): void {
    $this->seed(Pl21WalkSeeder::class);

    $nine = pl21Wave('Wave 9');
    $firstUsp = WaveUsp::withoutGlobalScopes()->where('wave_id', $nine->id)->orderBy('position')->firstOrFail();
    expect(WavePersonaAngle::withoutGlobalScopes()->where('wave_id', $nine->id)->where('usp_id', $firstUsp->id)->count())->toBe(1);

    $ten = pl21Wave('Wave 10');
    $second = WaveUsp::withoutGlobalScopes()->where('wave_id', $ten->id)->orderBy('position')->skip(1)->firstOrFail();
    expect(WavePersonaAngle::withoutGlobalScopes()->where('wave_id', $ten->id)->where('usp_id', $second->id)->count())->toBe(1);

    $codes = WavePersona::withoutGlobalScopes()->pluck('code')->all();
    foreach ($codes as $code) {
        expect($code)->toMatch('/^[A-Z0-9]{2,8}$/');
    }
});

it('S4: Agency B has its own brand and a wave with two USPs, and no row crosses agencies', function (): void {
    $this->seed(Pl21WalkSeeder::class);

    $b = Agency::query()->where('slug', Pl21WalkSeeder::AGENCY_B_SLUG)->firstOrFail();
    $glovo = Wave::query()->where('agency_id', $b->id)->firstOrFail();
    expect($glovo->name)->toBe('Glovo Wave 1')
        ->and(pl21Usps($glovo))->toBe(2);

    $a = Agency::query()->where('slug', Pl21WalkSeeder::AGENCY_A_SLUG)->firstOrFail();
    expect(DB::table('wave_usps')->where('agency_id', $a->id)->count() + DB::table('wave_usps')->where('agency_id', $b->id)->count())
        ->toBe(DB::table('wave_usps')->count());
});

it('C1: the cleanup removes exactly the walk rows and nothing else, and is safe twice or before any seed', function (): void {
    $this->seed(Pl21WalkCleanupSeeder::class);

    $other = Agency::factory()->create();
    $bystander = User::factory()->agencyAdmin($other)->create();
    $this->seed(Pl21WalkSeeder::class);
    $this->seed(Pl21WalkCleanupSeeder::class);
    $this->seed(Pl21WalkCleanupSeeder::class);

    expect(Agency::query()->whereIn('slug', [Pl21WalkSeeder::AGENCY_A_SLUG, Pl21WalkSeeder::AGENCY_B_SLUG])->count())->toBe(0)
        ->and(DB::table('wave_usps')->count())->toBe(0)
        ->and(DB::table('wave_personas')->count())->toBe(0)
        ->and(DB::table('wave_persona_angles')->count())->toBe(0)
        ->and(DB::table('waves')->count())->toBe(0)
        ->and(User::query()->where('email', 'like', '%pl21-walk%')->count())->toBe(0)
        ->and(Agency::query()->whereKey($other->id)->exists())->toBeTrue()
        ->and(User::query()->whereKey($bystander->id)->exists())->toBeTrue();
});
