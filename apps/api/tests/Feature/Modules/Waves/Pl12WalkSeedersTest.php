<?php

declare(strict_types=1);

use App\Modules\Agencies\Database\Factories\AgencyFactory;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Boards\Models\Board;
use App\Modules\Boards\Models\BoardCard;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Enums\CampaignStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use App\Modules\Waves\Features\WavesEnabled;
use App\Modules\Waves\Models\Wave;
use Database\Seeders\Pl12WalkCleanupSeeder;
use Database\Seeders\Pl12WalkSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('S1: seeds the walk agencies, leaves the flag off, and is idempotent', function (): void {
    $this->seed(Pl12WalkSeeder::class);

    $a = Agency::query()->where('slug', Pl12WalkSeeder::AGENCY_A_SLUG)->firstOrFail();
    $summer = Campaign::query()->where('agency_id', $a->id)->where('name', 'Bolt Summer Wave Campaign')->firstOrFail();
    $evergreen = Campaign::query()->where('agency_id', $a->id)->where('name', 'Bolt Evergreen')->firstOrFail();
    $promo = Campaign::query()->where('agency_id', $a->id)->where('name', 'Bolt Normal Promo')->firstOrFail();

    // Creators can apply to the wave campaign: for-a-wave, active, listed, budget above zero, every floor field.
    expect($summer->isForWave())->toBeTrue()
        ->and($summer->status)->toBe(CampaignStatus::Active)
        ->and($summer->listed_on_jobs_board)->toBeTrue()
        ->and($summer->budget_minor_units)->toBeGreaterThan(0)
        ->and($summer->description)->not->toBeNull()
        ->and($summer->listing_duration)->not->toBeNull()
        ->and($summer->listing_fee)->not->toBeNull()
        ->and($summer->listing_languages)->not->toBeEmpty()
        ->and($summer->listing_regions)->not->toBeEmpty()
        ->and($summer->listing_examples_url)->not->toBeNull()
        ->and($summer->creator_posts_content)->toBeFalse()
        ->and($summer->wave)->toBeNull()
        ->and($evergreen->isForWave())->toBeFalse()
        ->and($promo->isForWave())->toBeFalse()
        ->and(CampaignAssignment::query()->where('campaign_id', $promo->id)->count())->toBe(0)
        ->and(Wave::query()->where('agency_id', $a->id)->pluck('name')->sort()->values()->all())->toBe(['Wave 7', 'Wave 8'])
        ->and(Wave::query()->where('agency_id', $a->id)->whereNotNull('campaign_id')->count())->toBe(0);

    // The old board: seven columns, one card, no stage keys.
    $board = Board::query()->where('campaign_id', $evergreen->id)->firstOrFail();
    expect($board->columns()->count())->toBe(7)
        ->and($board->columns()->whereNotNull('stage_key')->count())->toBe(0)
        ->and(BoardCard::query()->where('board_id', $board->id)->count())->toBe(1);

    // Logins: three agency users, five creators, one agency B admin, all on the walk domain, all approved.
    expect(User::query()->where('email', 'like', '%'.Pl12WalkSeeder::DOMAIN)->count())->toBe(3 + count(Pl12WalkSeeder::CREATORS) + 1)
        ->and(Creator::query()->count())->toBe(count(Pl12WalkSeeder::CREATORS))
        ->and(Feature::active(WavesEnabled::NAME))->toBeFalse();

    $this->seed(Pl12WalkSeeder::class);
    expect(Agency::query()->whereIn('slug', [Pl12WalkSeeder::AGENCY_A_SLUG, Pl12WalkSeeder::AGENCY_B_SLUG])->count())->toBe(2)
        ->and(Wave::query()->count())->toBe(3)
        ->and(Campaign::query()->count())->toBe(4);
});

it('S2: maya can really apply to the seeded wave campaign through the jobs board, and it lands as an application', function (): void {
    $this->seed(Pl12WalkSeeder::class);

    $a = Agency::query()->where('slug', Pl12WalkSeeder::AGENCY_A_SLUG)->firstOrFail();
    $summer = Campaign::query()->where('agency_id', $a->id)->where('name', 'Bolt Summer Wave Campaign')->firstOrFail();
    $maya = User::query()->where('email', 'maya'.Pl12WalkSeeder::DOMAIN)->firstOrFail();

    $this->actingAs($maya)->postJson('/api/v1/creators/me/jobs/'.$summer->ulid.'/apply')->assertCreated();

    expect(DB::table('campaign_applications')->where('campaign_id', $summer->id)->count())->toBe(1);
});

it('S3: cleanup removes everything the walk made, leaves a bystander alone, and does not touch the flag', function (): void {
    config(['pennant.default' => 'database']);
    Feature::purge();

    $bystander = AgencyFactory::new()->createOne();
    $bystanderBrand = Brand::factory()->forAgency($bystander->id)->create();
    Wave::factory()->forBrand($bystander->id, $bystanderBrand->id)->create();
    Campaign::factory()->create(['agency_id' => $bystander->id, 'brand_id' => $bystanderBrand->id]);

    $this->seed(Pl12WalkSeeder::class);
    Feature::activate(WavesEnabled::NAME);
    $this->seed(Pl12WalkCleanupSeeder::class);

    expect(Agency::query()->whereIn('slug', [Pl12WalkSeeder::AGENCY_A_SLUG, Pl12WalkSeeder::AGENCY_B_SLUG])->count())->toBe(0)
        ->and(User::query()->where('email', 'like', '%'.Pl12WalkSeeder::DOMAIN)->count())->toBe(0)
        ->and(Creator::query()->count())->toBe(0)
        ->and(Wave::query()->count())->toBe(1)
        ->and(Campaign::query()->count())->toBe(1)
        ->and(Board::query()->count())->toBe(0)
        ->and(CampaignAssignment::query()->count())->toBe(0)
        ->and(Brand::query()->where('agency_id', $bystander->id)->count())->toBe(1)
        ->and(DB::table('features')->where('name', WavesEnabled::NAME)->exists())->toBeTrue();

    // Safe twice.
    $this->seed(Pl12WalkCleanupSeeder::class);
    expect(Wave::query()->count())->toBe(1);
});

it('S4: both seeders refuse to run outside local and testing', function (): void {
    app()->instance('env', 'production');

    expect(fn () => app(Pl12WalkSeeder::class)->run())->toThrow(RuntimeException::class)
        ->and(fn () => app(Pl12WalkCleanupSeeder::class)->run())->toThrow(RuntimeException::class);
});
