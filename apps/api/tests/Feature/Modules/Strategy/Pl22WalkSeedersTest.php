<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Features\StrategyEnabled;
use App\Modules\Strategy\Models\WavePersona;
use App\Modules\Strategy\Models\WavePersonaAngle;
use App\Modules\Waves\Database\Factories\WaveFactory;
use App\Modules\Waves\Features\WavesEnabled;
use App\Modules\Waves\Models\Wave;
use Database\Seeders\Pl22WalkCleanupSeeder;
use Database\Seeders\Pl22WalkSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function pl22Wave(string $name): Wave
{
    $agency = Agency::query()->where('slug', Pl22WalkSeeder::AGENCY_A_SLUG)->firstOrFail();

    return Wave::query()->where('agency_id', $agency->id)->where('name', $name)->firstOrFail();
}

it('M1: seeds the accounts and the three waves, leaves BOTH flags off, and is idempotent', function (): void {
    $this->seed(Pl22WalkSeeder::class);

    expect(User::query()->where('email', 'like', '%'.Pl22WalkSeeder::DOMAIN)->pluck('email')->sort()->values()->all())
        ->toBe(['admin@pl22-walk.local', 'manager@pl22-walk.local', 'staff@pl22-walk.local'])
        ->and(User::query()->where('email', 'like', '%'.Pl22WalkSeeder::DOMAIN_B)->count())->toBe(1)
        ->and(pl22Wave('Matrix Wave 1')->markets->pluck('code')->all())->toBe(['SK', 'RO', 'CZ'])
        ->and(Feature::active(WavesEnabled::NAME))->toBeFalse()
        ->and(Feature::active(StrategyEnabled::NAME))->toBeFalse();

    $count = static fn (): array => [
        DB::table('wave_usps')->count(), DB::table('wave_personas')->count(), DB::table('wave_persona_angles')->count(),
        DB::table('wave_matrix_cells')->count(), DB::table('waves')->count(), DB::table('users')->count(),
    ];
    $before = $count();
    $this->seed(Pl22WalkSeeder::class);
    expect($count())->toBe($before);
});

it('M2: Matrix Wave 1 holds the walk cases: all levels, a conflict angle, a persona with no Priority, an empty market', function (): void {
    $this->seed(Pl22WalkSeeder::class);
    $wave = pl22Wave('Matrix Wave 1');

    $cells = DB::table('wave_matrix_cells')->where('wave_id', $wave->id);
    expect(DB::table('wave_usps')->where('wave_id', $wave->id)->count())->toBe(6)
        ->and((clone $cells)->where('market_id', WaveFactory::marketId($wave, 'SK'))->count())->toBe(7)
        ->and((clone $cells)->distinct()->orderBy('level')->pluck('level')->all())->toBe(['low', 'priority', 'support'])
        ->and(WavePersona::withoutGlobalScopes()->where('wave_id', $wave->id)->where('market_id', WaveFactory::marketId($wave, 'SK'))->orderBy('slot')->pluck('code')->all())->toBe(['SK1', 'SK2'])
        ->and(WavePersona::withoutGlobalScopes()->where('wave_id', $wave->id)->where('market_id', WaveFactory::marketId($wave, 'RO'))->orderBy('slot')->pluck('code')->all())->toBe(['RO1', 'RO2'])
        ->and(WavePersona::withoutGlobalScopes()->where('wave_id', $wave->id)->where('market_id', WaveFactory::marketId($wave, 'CZ'))->count())->toBe(0);

    $ro2 = WavePersona::withoutGlobalScopes()->where('wave_id', $wave->id)->where('code', 'RO2')->firstOrFail();
    expect((clone $cells)->where('persona_id', $ro2->id)->where('level', 'priority')->count())->toBe(0)
        ->and((clone $cells)->where('persona_id', $ro2->id)->count())->toBeGreaterThan(0);

    // The manual angle sits on a Priority cell, and the sync kept it (it is not replaced by a synced one).
    $manual = WavePersonaAngle::withoutGlobalScopes()->where('wave_id', $wave->id)->where('origin', 'manual')->get();
    expect($manual)->toHaveCount(1);
    $angle = $manual->firstOrFail();
    expect(DB::table('wave_matrix_cells')->where('persona_id', $angle->persona_id)->where('usp_id', $angle->usp_id)->value('level'))->toBe('priority');
});

it('M3: every Priority and Support cell has exactly one angle; Low and Not set have none from the sync', function (): void {
    $this->seed(Pl22WalkSeeder::class);

    foreach (DB::table('wave_matrix_cells')->get() as $cell) {
        $angles = WavePersonaAngle::withoutGlobalScopes()->where('persona_id', $cell->persona_id)->where('usp_id', $cell->usp_id)->count();
        expect($angles)->toBe(in_array($cell->level, ['priority', 'support'], true) ? 1 : 0);
    }
});

it('M4: Matrix Wave 2 is empty, Matrix Wave 3 has eight personas in RO, Agency B stands apart', function (): void {
    $this->seed(Pl22WalkSeeder::class);

    $two = pl22Wave('Matrix Wave 2');
    $three = pl22Wave('Matrix Wave 3');
    $b = Agency::query()->where('slug', Pl22WalkSeeder::AGENCY_B_SLUG)->firstOrFail();

    expect(DB::table('wave_usps')->where('wave_id', $two->id)->count())->toBe(0)
        ->and(WavePersona::withoutGlobalScopes()->where('wave_id', $three->id)->where('market_id', WaveFactory::marketId($three, 'RO'))->count())->toBe(8)
        ->and(DB::table('wave_usps')->where('agency_id', $b->id)->count())->toBe(2)
        ->and(DB::table('wave_matrix_cells')->where('agency_id', $b->id)->count())->toBe(0);
});

it('M5: the cleanup removes exactly the walk rows, is safe twice or before any seed, and spares others', function (): void {
    $this->seed(Pl22WalkCleanupSeeder::class);

    $other = Agency::factory()->create();
    $bystander = User::factory()->agencyAdmin($other)->create();
    $this->seed(Pl22WalkSeeder::class);
    $this->seed(Pl22WalkCleanupSeeder::class);
    $this->seed(Pl22WalkCleanupSeeder::class);

    expect(Agency::query()->whereIn('slug', [Pl22WalkSeeder::AGENCY_A_SLUG, Pl22WalkSeeder::AGENCY_B_SLUG])->count())->toBe(0)
        ->and(DB::table('wave_matrix_cells')->count())->toBe(0)
        ->and(DB::table('wave_persona_angles')->count())->toBe(0)
        ->and(DB::table('wave_personas')->count())->toBe(0)
        ->and(DB::table('wave_usps')->count())->toBe(0)
        ->and(DB::table('waves')->count())->toBe(0)
        ->and(User::query()->where('email', 'like', '%pl22-walk%')->count())->toBe(0)
        ->and(Agency::query()->whereKey($other->id)->exists())->toBeTrue()
        ->and(User::query()->whereKey($bystander->id)->exists())->toBeTrue();
});

it('M6: both seeders refuse to run outside local and testing', function (): void {
    app()->detectEnvironment(static fn (): string => 'production');

    expect(fn () => (new Pl22WalkSeeder)->run())->toThrow(RuntimeException::class)
        ->and(fn () => (new Pl22WalkCleanupSeeder)->run())->toThrow(RuntimeException::class);
});
