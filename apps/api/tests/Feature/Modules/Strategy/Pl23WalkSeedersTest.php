<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Features\StrategyEnabled;
use App\Modules\Strategy\Models\WaveConcept;
use App\Modules\Strategy\Models\WavePersona;
use App\Modules\Waves\Database\Factories\WaveFactory;
use App\Modules\Waves\Features\WavesEnabled;
use App\Modules\Waves\Models\Wave;
use Database\Seeders\Pl23WalkCleanupSeeder;
use Database\Seeders\Pl23WalkSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Pennant\Feature;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('media');
});

afterEach(function (): void {
    $dir = storage_path('app/'.Pl23WalkSeeder::FILES_DIR);
    foreach (glob($dir.'/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($dir);
});

function pl23Wave(string $name): Wave
{
    $agency = Agency::query()->where('slug', Pl23WalkSeeder::AGENCY_A_SLUG)->firstOrFail();

    return Wave::query()->where('agency_id', $agency->id)->where('name', $name)->firstOrFail();
}

/** @return list<string> */
function pl23Statuses(Wave $wave, string $market): array
{
    $out = [];
    foreach (WaveConcept::withoutGlobalScopes()->where('wave_id', $wave->id)->where('market_id', WaveFactory::marketId($wave, $market))->orderBy('position')->get() as $concept) {
        $out[] = $concept->status->value;
    }

    return $out;
}

it('C1: seeds the accounts and the waves, leaves BOTH flags off, and is idempotent', function (): void {
    $this->seed(Pl23WalkSeeder::class);

    expect(User::query()->where('email', 'like', '%'.Pl23WalkSeeder::DOMAIN)->pluck('email')->sort()->values()->all())
        ->toBe(['admin@pl23-walk.local', 'manager@pl23-walk.local', 'staff@pl23-walk.local'])
        ->and(User::query()->where('email', 'like', '%'.Pl23WalkSeeder::DOMAIN_B)->count())->toBe(1)
        ->and(Feature::active(WavesEnabled::NAME))->toBeFalse()
        ->and(Feature::active(StrategyEnabled::NAME))->toBeFalse();

    $count = static fn (): array => [
        DB::table('wave_concepts')->count(), DB::table('wave_concept_usps')->count(), DB::table('wave_concept_links')->count(),
        DB::table('wave_concept_images')->count(), DB::table('wave_concept_decisions')->count(), DB::table('waves')->count(), DB::table('users')->count(),
    ];
    $before = $count();
    $this->seed(Pl23WalkSeeder::class);
    expect($count())->toBe($before);
});

it('C2: Wave 1 has three concepts in three statuses in SK, personas but no concept in RO, no persona in CZ; default persona codes', function (): void {
    $this->seed(Pl23WalkSeeder::class);
    $wave = pl23Wave('Concepts Wave 1');

    expect(pl23Statuses($wave, 'SK'))->toBe(['approved', 'awaiting', 'changes_requested'])
        ->and(WaveConcept::withoutGlobalScopes()->where('wave_id', $wave->id)->where('market_id', WaveFactory::marketId($wave, 'RO'))->count())->toBe(0)
        ->and(WavePersona::withoutGlobalScopes()->where('wave_id', $wave->id)->where('market_id', WaveFactory::marketId($wave, 'RO'))->count())->toBe(2)
        ->and(WavePersona::withoutGlobalScopes()->where('wave_id', $wave->id)->where('market_id', WaveFactory::marketId($wave, 'CZ'))->count())->toBe(0)
        ->and(WavePersona::withoutGlobalScopes()->where('wave_id', $wave->id)->where('market_id', WaveFactory::marketId($wave, 'SK'))->orderBy('slot')->pluck('code')->all())->toBe(['SK1', 'SK2'])
        ->and(WavePersona::withoutGlobalScopes()->where('wave_id', $wave->id)->where('market_id', WaveFactory::marketId($wave, 'RO'))->orderBy('slot')->pluck('code')->all())->toBe(['RO1', 'RO2'])
        ->and(DB::table('wave_usps')->where('wave_id', $wave->id)->count())->toBe(6)
        ->and(DB::table('wave_matrix_cells')->where('wave_id', $wave->id)->count())->toBeGreaterThan(0);

    $changes = WaveConcept::withoutGlobalScopes()->where('wave_id', $wave->id)->where('status', 'changes_requested')->firstOrFail();
    $decision = DB::table('wave_concept_decisions')->where('concept_id', $changes->id)->first();
    expect($decision)->not->toBeNull();
    expect(data_get($decision, 'client_name'))->toBe('Tomáš Kováč')
        ->and(data_get($decision, 'comment'))->not->toBeNull();

    // The approved concept carries a link and stayed approved (the link was added before the decision).
    $approved = WaveConcept::withoutGlobalScopes()->where('wave_id', $wave->id)->where('status', 'approved')->firstOrFail();
    expect(DB::table('wave_concept_links')->where('concept_id', $approved->id)->count())->toBe(1);
});

it('C3: Wave 2 has two approved concepts in SK and an empty RO; Wave 3 has a concept with links and, when GD is there, images', function (): void {
    $this->seed(Pl23WalkSeeder::class);

    expect(pl23Statuses(pl23Wave('Concepts Wave 2'), 'SK'))->toBe(['approved', 'approved'])
        ->and(pl23Statuses(pl23Wave('Concepts Wave 2'), 'RO'))->toBe([]);

    $refs = WaveConcept::withoutGlobalScopes()->where('wave_id', pl23Wave('Concepts Wave 3')->id)->firstOrFail();
    expect(DB::table('wave_concept_links')->where('concept_id', $refs->id)->count())->toBe(4);
    if (function_exists('imagecreatetruecolor')) {
        $images = DB::table('wave_concept_images')->where('concept_id', $refs->id)->where('status', 'ready')->get();
        expect($images->count())->toBeGreaterThanOrEqual(2);
        foreach ($images as $image) {
            expect(Storage::disk('media')->exists($image->storage_key))->toBeTrue();
        }
    }
});

it('C4: Agency B stands apart: one concept of its own and nothing else of Agency A', function (): void {
    $this->seed(Pl23WalkSeeder::class);
    $b = Agency::query()->where('slug', Pl23WalkSeeder::AGENCY_B_SLUG)->firstOrFail();

    expect(DB::table('wave_concepts')->where('agency_id', $b->id)->count())->toBe(1)
        ->and(DB::table('wave_usps')->where('agency_id', $b->id)->count())->toBe(2);
});

it('C5: the cleanup removes exactly the walk rows and objects, is safe twice or before any seed, and spares others', function (): void {
    $this->seed(Pl23WalkCleanupSeeder::class);

    $other = Agency::factory()->create();
    $bystander = User::factory()->agencyAdmin($other)->create();
    $this->seed(Pl23WalkSeeder::class);
    $this->seed(Pl23WalkCleanupSeeder::class);
    $this->seed(Pl23WalkCleanupSeeder::class);

    expect(Agency::query()->whereIn('slug', [Pl23WalkSeeder::AGENCY_A_SLUG, Pl23WalkSeeder::AGENCY_B_SLUG])->count())->toBe(0);
    foreach (['wave_concept_decisions', 'wave_concept_links', 'wave_concept_images', 'wave_concept_usps', 'wave_concepts', 'wave_matrix_cells', 'wave_personas', 'wave_usps', 'waves'] as $table) {
        expect(DB::table($table)->count())->toBe(0, $table);
    }
    expect(User::query()->where('email', 'like', '%pl23-walk%')->count())->toBe(0)
        ->and(Agency::query()->whereKey($other->id)->exists())->toBeTrue()
        ->and(User::query()->whereKey($bystander->id)->exists())->toBeTrue()
        ->and(Storage::disk('media')->allFiles())->toBe([])
        ->and(is_dir(storage_path('app/'.Pl23WalkSeeder::FILES_DIR)))->toBeFalse();
});

it('C6: both seeders refuse to run outside local and testing', function (): void {
    app()->detectEnvironment(static fn (): string => 'production');

    expect(fn () => (new Pl23WalkSeeder)->run())->toThrow(RuntimeException::class)
        ->and(fn () => (new Pl23WalkCleanupSeeder)->run())->toThrow(RuntimeException::class);
});
