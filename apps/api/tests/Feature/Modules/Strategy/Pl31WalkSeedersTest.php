<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Creators\Features\CampaignBrandExclusivityEnabled;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Features\CreatorsScriptsEnabled;
use App\Modules\Strategy\Features\StrategyEnabled;
use App\Modules\Waves\Features\WavesEnabled;
use App\Modules\Waves\Models\Wave;
use Database\Seeders\Pl31WalkCleanupSeeder;
use Database\Seeders\Pl31WalkSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use Tests\Support\StrategyFixtures as S;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function pl31WalkWave(string $name): Wave
{
    $agency = Agency::query()->where('slug', Pl31WalkSeeder::AGENCY_A_SLUG)->firstOrFail();

    return Wave::query()->where('agency_id', $agency->id)->where('name', $name)->firstOrFail();
}

it('P1: seeds the logins and the second agency, leaves ALL flags off, and is idempotent', function (): void {
    $this->seed(Pl31WalkSeeder::class);

    expect(User::query()->where('email', 'like', '%'.Pl31WalkSeeder::DOMAIN)->count())->toBe(3 + 1 + 13)
        ->and(User::query()->where('email', 'like', '%'.Pl31WalkSeeder::DOMAIN_B)->count())->toBe(1)
        ->and(Feature::active(WavesEnabled::NAME))->toBeFalse()
        ->and(Feature::active(StrategyEnabled::NAME))->toBeFalse()
        ->and(Feature::active(CreatorsScriptsEnabled::NAME))->toBeFalse();

    $before = [Wave::query()->count(), DB::table('wave_creators')->count(), Campaign::query()->count()];
    $this->seed(Pl31WalkSeeder::class);
    expect([Wave::query()->count(), DB::table('wave_creators')->count(), Campaign::query()->count()])->toBe($before);
});

it('P2: wave 1 is linked to a listed for-a-wave campaign, wave 2 has none, a normal campaign is untouched', function (): void {
    $this->seed(Pl31WalkSeeder::class);
    $w1 = pl31WalkWave('Creators Wave 1');
    $campaign = Campaign::query()->findOrFail($w1->campaign_id);

    expect($campaign->name)->toBe('Bolt Creators Campaign')
        ->and(pl31WalkWave('Creators Wave 2')->campaign_id)->toBeNull()
        ->and(Campaign::query()->where('name', 'Bolt Spare Campaign')->exists())->toBeTrue()
        ->and(Campaign::query()->where('name', 'Bolt Evergreen')->exists())->toBeTrue()
        ->and(DB::table('wave_markets')->where('wave_id', $w1->id)->count())->toBe(2)
        ->and(DB::table('wave_market_countries')->where('wave_id', $w1->id)->count())->toBe(5);
});

it('P3: the picker lists applicants and invitees, never the blacklisted or the exclusive one', function (): void {
    $this->seed(Pl31WalkSeeder::class);
    Feature::activate(WavesEnabled::NAME);
    Feature::activate(StrategyEnabled::NAME);
    Feature::activate(CreatorsScriptsEnabled::NAME);
    Feature::activate(CampaignBrandExclusivityEnabled::NAME);
    $w1 = pl31WalkWave('Creators Wave 1');
    $admin = User::query()->where('email', Pl31WalkSeeder::ADMIN_EMAIL)->firstOrFail();
    $agency = Agency::query()->findOrFail($w1->agency_id);
    $brand = $w1->brand;
    $mk = DB::table('wave_markets')->where('wave_id', $w1->id)->where('code', 'NOR')->value('ulid');

    $url = "/api/v1/agencies/{$agency->ulid}/brands/{$brand->ulid}/waves/{$w1->ulid}/strategy/creators/candidates?market={$mk}&per_page=50";
    $names = array_column(S::call($admin, 'GET', $url)->assertOk()->json('data'), 'display_name');

    expect($names)->toContain('Astrid Lindqvist', 'Hana Skrytá', 'Pia Kollárová');
    expect(array_intersect($names, ['Maya Marcinková', 'Blake Blocked', 'Brandon Barred', 'Exo Exclusive']))->toBe([]);
});

it('P4: three creators are in the wave, one with the client approval; the board carries the In wave marker data', function (): void {
    $this->seed(Pl31WalkSeeder::class);
    $w1 = pl31WalkWave('Creators Wave 1');

    expect(DB::table('wave_creators')->where('wave_id', $w1->id)->count())->toBe(3)
        ->and(DB::table('wave_creator_decisions')->where('wave_id', $w1->id)->where('status', 'approved')->count())->toBe(1);
});

it('P5: the cleanup removes the walk rows and nothing else, twice', function (): void {
    $other = Wave::factory()->create();
    $this->seed(Pl31WalkSeeder::class);
    $this->seed(Pl31WalkCleanupSeeder::class);
    $this->seed(Pl31WalkCleanupSeeder::class);

    expect(Agency::query()->whereIn('slug', [Pl31WalkSeeder::AGENCY_A_SLUG, Pl31WalkSeeder::AGENCY_B_SLUG])->count())->toBe(0)
        ->and(DB::table('wave_creators')->count())->toBe(0)
        ->and(DB::table('creators')->count())->toBe(0)
        ->and(Wave::withoutGlobalScopes()->whereKey($other->id)->exists())->toBeTrue()
        ->and(User::query()->where('email', 'like', '%@pl31-walk%')->count())->toBe(0);
});
