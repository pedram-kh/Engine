<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyMembership;
use App\Modules\Brands\Models\Brand;
use App\Modules\Identity\Models\User;
use App\Modules\Waves\Features\WavesEnabled;
use App\Modules\Waves\Models\Wave;
use App\Modules\Waves\Models\WaveStep;
use App\Modules\Waves\Steps\WaveTrackerCalculator;
use Database\Seeders\Pl13WalkCleanupSeeder;
use Database\Seeders\Pl13WalkSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function walkWave(string $name): Wave
{
    $agency = Agency::query()->where('slug', Pl13WalkSeeder::AGENCY_A_SLUG)->firstOrFail();

    return Wave::query()->where('agency_id', $agency->id)->where('name', $name)->firstOrFail();
}

it('S1: seeds both walk agencies, the five waves of the walk, leaves the flag off, and is idempotent', function (): void {
    $this->seed(Pl13WalkSeeder::class);

    $a = Agency::query()->where('slug', Pl13WalkSeeder::AGENCY_A_SLUG)->firstOrFail();
    expect(Wave::query()->where('agency_id', $a->id)->pluck('name')->sort()->values()->all())->toBe(['Wave 4', 'Wave 5', 'Wave 6', 'Wave 8'])
        ->and(Brand::query()->where('agency_id', $a->id)->pluck('name')->all())->toBe(['Bolt Food'])
        ->and(User::query()->where('email', 'like', '%'.Pl13WalkSeeder::DOMAIN)->count())->toBe(5)
        ->and(User::query()->where('email', 'like', '%'.Pl13WalkSeeder::DOMAIN_B)->count())->toBe(1)
        ->and(Feature::active(WavesEnabled::NAME))->toBeFalse();

    $before = [DB::table('wave_steps')->count(), DB::table('waves')->count(), DB::table('users')->count()];
    $this->seed(Pl13WalkSeeder::class);
    expect([DB::table('wave_steps')->count(), DB::table('waves')->count(), DB::table('users')->count()])->toBe($before);
});

it('S2: Wave 6 is an existing wave with no steps and no marker; Wave 5 reads Complete', function (): void {
    $this->seed(Pl13WalkSeeder::class);

    $six = walkWave('Wave 6');
    expect($six->steps_provisioned_at)->toBeNull()
        ->and(DB::table('wave_steps')->where('wave_id', $six->id)->count())->toBe(0);

    $five = walkWave('Wave 5');
    $steps = DB::table('wave_steps')->where('wave_id', $five->id)->get();
    $progress = WaveTrackerCalculator::progress(WaveStep::query()->where('wave_id', $five->id)->get());
    expect($steps->count())->toBe(24)
        ->and($progress['label_kind'])->toBe('complete');
});

it('S3: Wave 8 has USPs done, Personas in progress, a delayed step, a due-today step, a former owner and a Client step', function (): void {
    $this->seed(Pl13WalkSeeder::class);

    $eight = walkWave('Wave 8');
    $rows = DB::table('wave_steps')->where('wave_id', $eight->id)->get();
    $key = static fn (string $k): object => $rows->firstWhere('step_key', $k) ?? throw new RuntimeException($k);
    $today = now()->toDateString();

    expect($key('ugc.usps')->status)->toBe('done')
        ->and($key('ugc.personas')->status)->toBe('in_progress')
        ->and($rows->where('due_on', '<', $today)->count())->toBeGreaterThanOrEqual(1)
        ->and($rows->where('due_on', $today)->count())->toBeGreaterThanOrEqual(1)
        ->and($rows->where('owner_client', true)->count())->toBeGreaterThanOrEqual(1);

    $former = User::query()->where('email', Pl13WalkSeeder::FORMER_EMAIL)->firstOrFail();
    $a = Agency::query()->where('slug', Pl13WalkSeeder::AGENCY_A_SLUG)->firstOrFail();
    expect($rows->where('owner_user_id', $former->id)->count())->toBe(1)
        ->and(AgencyMembership::query()->where('agency_id', $a->id)->where('user_id', $former->id)->count())->toBe(0)
        ->and(AgencyMembership::withTrashed()->where('agency_id', $a->id)->where('user_id', $former->id)->count())->toBe(1);
});

it('S4: Wave 4 has no Statics stream, and Agency B has its own brand and a wave with steps', function (): void {
    $this->seed(Pl13WalkSeeder::class);

    $four = walkWave('Wave 4');
    expect(DB::table('wave_steps')->where('wave_id', $four->id)->where('stream', 'statics')->count())->toBe(0)
        ->and(DB::table('wave_steps')->where('wave_id', $four->id)->count())->toBe(19);

    $b = Agency::query()->where('slug', Pl13WalkSeeder::AGENCY_B_SLUG)->firstOrFail();
    $glovo = Wave::query()->where('agency_id', $b->id)->firstOrFail();
    expect($glovo->name)->toBe('Glovo Wave 1')
        ->and(DB::table('wave_steps')->where('wave_id', $glovo->id)->count())->toBe(24);
});

it('C1: the cleanup removes exactly the walk rows and nothing else, and is safe twice or before any seed', function (): void {
    $this->seed(Pl13WalkCleanupSeeder::class);

    $other = Agency::factory()->create();
    $bystander = User::factory()->agencyAdmin($other)->create();
    $this->seed(Pl13WalkSeeder::class);
    $this->seed(Pl13WalkCleanupSeeder::class);
    $this->seed(Pl13WalkCleanupSeeder::class);

    expect(Agency::query()->whereIn('slug', [Pl13WalkSeeder::AGENCY_A_SLUG, Pl13WalkSeeder::AGENCY_B_SLUG])->count())->toBe(0)
        ->and(DB::table('wave_steps')->count())->toBe(0)
        ->and(DB::table('waves')->count())->toBe(0)
        ->and(User::query()->where('email', 'like', '%pl13-walk%')->count())->toBe(0)
        ->and(Agency::query()->whereKey($other->id)->exists())->toBeTrue()
        ->and(User::query()->whereKey($bystander->id)->exists())->toBeTrue();
});
