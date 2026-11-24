<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Features\CreatorsScriptsEnabled;
use App\Modules\Strategy\Features\StrategyEnabled;
use App\Modules\Waves\Features\WavesEnabled;
use App\Modules\Waves\Models\Wave;
use Database\Seeders\Pl32WalkCleanupSeeder;
use Database\Seeders\Pl32WalkSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function pl32Wave(): Wave
{
    $agency = Agency::query()->where('slug', Pl32WalkSeeder::AGENCY_A_SLUG)->firstOrFail();

    return Wave::query()->where('agency_id', $agency->id)->where('name', 'Scripts Wave 1')->firstOrFail();
}

it('S1: seeds the logins, leaves ALL flags off, and is idempotent', function (): void {
    $this->seed(Pl32WalkSeeder::class);

    expect(User::query()->where('email', 'like', '%'.Pl32WalkSeeder::DOMAIN)->count())->toBe(3 + 1 + 6)
        ->and(User::query()->where('email', 'like', '%'.Pl32WalkSeeder::DOMAIN_B)->count())->toBe(1)
        ->and(Feature::active(WavesEnabled::NAME))->toBeFalse()
        ->and(Feature::active(StrategyEnabled::NAME))->toBeFalse()
        ->and(Feature::active(CreatorsScriptsEnabled::NAME))->toBeFalse();

    $before = [Wave::query()->count(), DB::table('wave_scripts')->count()];
    $this->seed(Pl32WalkSeeder::class);
    expect([Wave::query()->count(), DB::table('wave_scripts')->count()])->toBe($before);
});

it('S2: the scripts are as the walk describes: 2, 3, 0, 1 with no persona, one archived, one in the Nordics; no concepts', function (): void {
    $this->seed(Pl32WalkSeeder::class);
    $wave = pl32Wave();

    $perCreator = DB::table('wave_scripts')->join('creators', 'creators.id', '=', 'wave_scripts.creator_id')
        ->where('wave_scripts.wave_id', $wave->id)->whereNull('wave_scripts.archived_at')
        ->groupBy('creators.display_name')->pluck(DB::raw('count(*)'), 'creators.display_name')->all();

    expect($perCreator)->toMatchArray(['Lucia Horváthová' => 2, 'Martin Kováč' => 3, 'Tomáš Baláž' => 1, 'Astrid Lindqvist' => 1])
        ->and($perCreator)->not->toHaveKey('Katarína Nagy')
        ->and(DB::table('wave_scripts')->where('wave_id', $wave->id)->whereNotNull('archived_at')->count())->toBe(1)
        ->and(DB::table('wave_creators')->where('wave_id', $wave->id)->count())->toBe(5)
        ->and(DB::table('wave_creators')->where('wave_id', $wave->id)->whereNull('persona_id')->count())->toBe(1)
        ->and(DB::table('wave_markets')->where('wave_id', $wave->id)->count())->toBe(2)
        ->and(DB::table('wave_concepts')->where('wave_id', $wave->id)->count())->toBe(0);
});

it('S3: the hostile text is stored exactly as typed', function (): void {
    $this->seed(Pl32WalkSeeder::class);

    expect(DB::table('wave_script_hook_texts')->where('body', Pl32WalkSeeder::HOSTILE)->count())->toBeGreaterThanOrEqual(2);
});

it('S4: the cleanup removes the walk rows and nothing else, twice', function (): void {
    $other = Wave::factory()->create();
    $this->seed(Pl32WalkSeeder::class);
    $this->seed(Pl32WalkCleanupSeeder::class);
    $this->seed(Pl32WalkCleanupSeeder::class);

    expect(Agency::query()->whereIn('slug', [Pl32WalkSeeder::AGENCY_A_SLUG, Pl32WalkSeeder::AGENCY_B_SLUG])->count())->toBe(0)
        ->and(DB::table('wave_scripts')->count())->toBe(0)
        ->and(DB::table('wave_script_hooks')->count())->toBe(0)
        ->and(DB::table('creators')->count())->toBe(0)
        ->and(Wave::withoutGlobalScopes()->whereKey($other->id)->exists())->toBeTrue()
        ->and(User::query()->where('email', 'like', '%@pl32-walk%')->count())->toBe(0);
});
