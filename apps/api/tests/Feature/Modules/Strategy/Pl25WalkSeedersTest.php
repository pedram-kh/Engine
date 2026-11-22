<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Features\StrategyEnabled;
use App\Modules\Strategy\Http\Resources\StepsPresenter;
use App\Modules\Waves\Features\WavesEnabled;
use App\Modules\Waves\Models\Wave;
use App\Modules\Waves\Models\WaveStep;
use Database\Seeders\Pl25WalkCleanupSeeder;
use Database\Seeders\Pl25WalkSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function pl25Wave(string $name): Wave
{
    $agency = Agency::query()->where('slug', Pl25WalkSeeder::AGENCY_A_SLUG)->firstOrFail();

    return Wave::query()->where('agency_id', $agency->id)->where('name', $name)->firstOrFail();
}

it('N1: seeds admin, manager, staff, a former member and a second agency, leaves BOTH flags off, and is idempotent', function (): void {
    $this->seed(Pl25WalkSeeder::class);

    expect(User::query()->where('email', 'like', '%'.Pl25WalkSeeder::DOMAIN)->count())->toBe(4)
        ->and(User::query()->where('email', 'like', '%'.Pl25WalkSeeder::DOMAIN_B)->count())->toBe(1)
        ->and(Feature::active(WavesEnabled::NAME))->toBeFalse()
        ->and(Feature::active(StrategyEnabled::NAME))->toBeFalse();

    $before = [Wave::query()->count(), DB::table('wave_strategy_notes')->count(), DB::table('wave_strategy_completions')->count()];
    $this->seed(Pl25WalkSeeder::class);
    expect([Wave::query()->count(), DB::table('wave_strategy_notes')->count(), DB::table('wave_strategy_completions')->count()])->toBe($before);
});

it('N2: wave 1 reads USPs done, Personas done in one market only, matrix in progress, Concepts in progress', function (): void {
    $this->seed(Pl25WalkSeeder::class);
    $steps = StepsPresenter::build(pl25Wave('Connect Wave 1'))['steps'];

    expect($steps['usps']['state'])->toBe('done')
        ->and($steps['personas']['state'])->toBe('in_progress')
        ->and($steps['personas']['markets_done'])->toBe(1)
        ->and($steps['personas']['markets_total'])->toBe(2)
        ->and($steps['matrix']['state'])->toBe('in_progress')
        ->and($steps['concepts']['state'])->toBe('in_progress');
});

it('N3: wave 2 is everything done, wave 3 nothing started, wave 4 has a Personas row set by hand beyond its step', function (): void {
    $this->seed(Pl25WalkSeeder::class);
    $done = StepsPresenter::build(pl25Wave('Connect Wave 2'))['steps'];
    $none = StepsPresenter::build(pl25Wave('Connect Wave 3'))['steps'];

    foreach (['usps', 'personas', 'matrix', 'concepts'] as $step) {
        expect($done[$step]['state'])->toBe('done')->and($none[$step]['state'])->toBe('not_started');
    }
    $row = WaveStep::query()->where('wave_id', pl25Wave('Connect Wave 4')->id)->where('step_key', 'ugc.personas')->firstOrFail();
    expect($row->status->value)->toBe('done')->and($row->status_source->value)->toBe('manual')
        ->and(StepsPresenter::build(pl25Wave('Connect Wave 4'))['steps']['personas']['state'])->toBe('not_started');
});

it('N4: notes by two authors and a former member (kept), and a note in the second agency', function (): void {
    $this->seed(Pl25WalkSeeder::class);
    $w1 = pl25Wave('Connect Wave 1');
    $authors = DB::table('wave_strategy_notes')->where('wave_id', $w1->id)->join('users', 'users.id', '=', 'wave_strategy_notes.author_user_id')->pluck('users.email')->unique()->sort()->values()->all();

    expect($authors)->toBe(['admin@pl25-walk.local', 'former@pl25-walk.local', 'manager@pl25-walk.local'])
        ->and(DB::table('wave_strategy_notes')->count())->toBe(7)
        ->and(DB::table('agency_users')->join('users', 'users.id', '=', 'agency_users.user_id')->where('users.email', 'former@pl25-walk.local')->whereNull('agency_users.deleted_at')->count())->toBe(0);
});

it('N5: the cleanup removes the walk rows, notes and completions, and nothing else, twice', function (): void {
    $other = Wave::factory()->create();
    $this->seed(Pl25WalkSeeder::class);
    $this->seed(Pl25WalkCleanupSeeder::class);
    $this->seed(Pl25WalkCleanupSeeder::class);

    expect(Agency::query()->whereIn('slug', [Pl25WalkSeeder::AGENCY_A_SLUG, Pl25WalkSeeder::AGENCY_B_SLUG])->count())->toBe(0)
        ->and(DB::table('wave_strategy_notes')->count())->toBe(0)
        ->and(DB::table('wave_strategy_completions')->count())->toBe(0)
        ->and(Wave::withoutGlobalScopes()->whereKey($other->id)->exists())->toBeTrue()
        ->and(User::query()->where('email', 'like', '%@pl25-walk%')->count())->toBe(0);
});

it('N6: every earlier walk cleanup seeder deletes the notes and the completions BEFORE the markets (L2)', function (): void {
    foreach (glob(database_path('seeders/Pl*WalkCleanupSeeder.php')) ?: [] as $file) {
        $code = (string) file_get_contents($file);
        $notes = strpos($code, "DB::table('wave_strategy_notes')");
        $done = strpos($code, "DB::table('wave_strategy_completions')");
        $markets = strpos($code, "DB::table('wave_markets')");

        expect($notes)->not->toBeFalse(basename($file))
            ->and($done)->not->toBeFalse(basename($file))
            ->and($notes)->toBeLessThan($markets)
            ->and($done)->toBeLessThan($markets);
    }
});
