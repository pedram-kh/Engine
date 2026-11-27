<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Features\CreatorsScriptsEnabled;
use App\Modules\Strategy\Features\StrategyEnabled;
use App\Modules\Strategy\StrategyLimits;
use App\Modules\Waves\Features\WavesEnabled;
use App\Modules\Waves\Models\Wave;
use Database\Seeders\Pl32bWalkSeeder;
use Database\Seeders\Pl32WalkCleanupSeeder;
use Database\Seeders\Pl32WalkSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
        ->groupBy('creators.display_name')->select('creators.display_name', DB::raw('count(*) as n'))->get()
        ->mapWithKeys(fn ($row): array => [(string) $row->display_name => (int) $row->n])->all();

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

// ---------------------------------------------------------------- PL-3.2b

it('S5: the 3.2b walk seed: languages, lines, B-roll and images through the services; Martin\'s script is at every limit; the flags stay off; it is idempotent', function (): void {
    Storage::fake('media');
    $this->seed(Pl32bWalkSeeder::class);
    $wave = pl32Wave();
    $count = static fn (string $table): int => DB::table($table)->where('wave_id', $wave->id)->count();

    expect(DB::table('wave_market_script_languages')->where('wave_id', $wave->id)->orderBy('market_id')->orderBy('position')->pluck('language')->all())->toBe(['sk', 'hu'])
        ->and($count('wave_script_lines'))->toBe(4 + 30 + 29 + 3 + 1 + 1 + 2)
        ->and($count('wave_script_broll'))->toBe(3 + 40 + 39 + 1 + 1)
        ->and($count('wave_script_images'))->toBe(2 + 10 + 9 + 2)
        ->and(DB::table('wave_script_images')->where('wave_id', $wave->id)->where('status', 'ready')->count())->toBe(23)
        ->and(Storage::disk('media')->allFiles())->toHaveCount(46)
        ->and(Feature::active(WavesEnabled::NAME))->toBeFalse()->and(Feature::active(StrategyEnabled::NAME))->toBeFalse()->and(Feature::active(CreatorsScriptsEnabled::NAME))->toBeFalse();

    $martin = DB::table('wave_scripts')->join('creators', 'creators.id', '=', 'wave_scripts.creator_id')->where('creators.display_name', 'Martin Kováč')->orderBy('wave_scripts.number')->value('wave_scripts.id');
    expect(DB::table('wave_script_lines')->where('script_id', $martin)->count())->toBe(StrategyLimits::LINES_PER_SCRIPT)
        ->and(DB::table('wave_script_broll')->where('script_id', $martin)->count())->toBe(StrategyLimits::BROLL_PER_SCRIPT)
        ->and(DB::table('wave_script_images')->where('script_id', $martin)->count())->toBe(StrategyLimits::IMAGES_PER_BLOCK);
    // the walk ruling: a line with a shot type and a visual description, a line with only a shot type, and the script's setting
    expect(DB::table('wave_script_lines')->where('wave_id', $wave->id)->whereNotNull('shot_type')->count())->toBe(2)
        ->and(DB::table('wave_script_lines')->where('wave_id', $wave->id)->whereNotNull('visual_description')->count())->toBe(1)
        ->and(DB::table('wave_scripts')->where('wave_id', $wave->id)->whereNotNull('setting')->count())->toBe(1);
    expect(DB::table('wave_script_line_texts')->where('body', Pl32WalkSeeder::HOSTILE)->count())->toBe(2)
        ->and(DB::table('wave_script_broll')->where('description', Pl32WalkSeeder::HOSTILE)->count())->toBe(1);

    $before = [DB::table('wave_script_lines')->count(), DB::table('wave_script_images')->count(), count(Storage::disk('media')->allFiles())];
    $this->seed(Pl32bWalkSeeder::class);
    expect([DB::table('wave_script_lines')->count(), DB::table('wave_script_images')->count(), count(Storage::disk('media')->allFiles())])->toBe($before);
});

it('S6: the cleanup removes the 3.2b walk rows AND their stored objects, and nothing else, twice', function (): void {
    Storage::fake('media');
    $other = Wave::factory()->create();
    $this->seed(Pl32bWalkSeeder::class);
    expect(Storage::disk('media')->allFiles())->not->toBe([]);

    $this->seed(Pl32WalkCleanupSeeder::class);
    $this->seed(Pl32WalkCleanupSeeder::class);

    foreach (['wave_script_lines', 'wave_script_line_texts', 'wave_script_broll', 'wave_script_images', 'wave_market_script_languages', 'wave_scripts'] as $table) {
        expect(DB::table($table)->count())->toBe(0, $table);
    }
    expect(Storage::disk('media')->allFiles())->toBe([])->and(Wave::withoutGlobalScopes()->whereKey($other->id)->exists())->toBeTrue();
});

it('S6: when a stored object cannot be deleted the cleanup stops before any row goes', function (): void {
    Storage::fake('media');
    $this->seed(Pl32bWalkSeeder::class);
    $real = Storage::disk('media');
    $failing = Mockery::mock($real)->makePartial();
    $failing->shouldReceive('delete')->andReturn(false);
    Storage::set('media', $failing);
    $images = DB::table('wave_script_images')->count();

    $stopped = false;
    try {
        $this->seed(Pl32WalkCleanupSeeder::class);
    } catch (RuntimeException) {
        $stopped = true;
    }
    expect($stopped)->toBeTrue();
    expect(DB::table('wave_script_images')->count())->toBe($images)->and(DB::table('agencies')->where('slug', Pl32WalkSeeder::AGENCY_A_SLUG)->exists())->toBeTrue();
});
