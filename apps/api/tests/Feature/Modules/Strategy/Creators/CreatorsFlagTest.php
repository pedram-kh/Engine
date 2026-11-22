<?php

declare(strict_types=1);

use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Features\CreatorsScriptsEnabled;
use App\Modules\Strategy\Features\StrategyEnabled;
use App\Modules\Waves\Features\WavesEnabled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * PL-3.1 B1 — the `creators_scripts_enabled` flag: default OFF, needs waves AND strategy, `features.creators_scripts` on
 * `/me` for an agency user only.
 */

it('B1: is OFF on a fresh database and has the registry name', function (): void {
    expect(CreatorsScriptsEnabled::NAME)->toBe('creators_scripts_enabled')
        ->and(Feature::active(CreatorsScriptsEnabled::NAME))->toBeFalse()
        ->and(CreatorsScriptsEnabled::active())->toBeFalse();
});

it('B1: active() needs all three flags', function (): void {
    Feature::activate(CreatorsScriptsEnabled::NAME);
    expect(CreatorsScriptsEnabled::active())->toBeFalse();

    Feature::activate(WavesEnabled::NAME);
    expect(CreatorsScriptsEnabled::active())->toBeFalse();

    Feature::activate(StrategyEnabled::NAME);
    expect(CreatorsScriptsEnabled::active())->toBeTrue();

    Feature::deactivate(WavesEnabled::NAME);
    expect(CreatorsScriptsEnabled::active())->toBeFalse();
});

it('B1: features.creators_scripts is true only with all three flags, for an agency user', function (): void {
    $user = User::factory()->agencyAdmin()->createOne();
    $me = fn () => $this->actingAs($user, 'web')->getJson('/api/v1/me')->assertOk()->json('data.attributes.features');

    expect($me()['creators_scripts'])->toBeFalse();
    Feature::activate(CreatorsScriptsEnabled::NAME);
    Feature::activate(StrategyEnabled::NAME);
    expect($me()['creators_scripts'])->toBeFalse();
    Feature::activate(WavesEnabled::NAME);
    expect($me()['creators_scripts'])->toBeTrue();

    foreach ([User::factory()->creator()->createOne(), User::factory()->platformAdmin()->createOne()] as $other) {
        $this->actingAs($other, 'web')->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.attributes.features.creators_scripts', false);
    }
});

it('B1: is in the admin flag list (off), the test-helper allowlist and docs/feature-flags.md', function (): void {
    $admin = User::factory()->create(['type' => UserType::PlatformAdmin, 'two_factor_confirmed_at' => now()]);

    /** @var list<array{attributes: array{name: string, label: string, enabled: bool}}> $rows */
    $rows = $this->actingAs($admin, 'web_admin')->getJson('/api/v1/admin/feature-flags')->assertOk()->json('data');
    $match = array_values(array_filter($rows, static fn (array $r): bool => $r['attributes']['name'] === CreatorsScriptsEnabled::NAME));
    $helper = file_get_contents(base_path('app/TestHelpers/Http/Controllers/SetFeatureFlagController.php'));
    $docs = file_get_contents(base_path('../../docs/feature-flags.md'));

    expect($match)->toHaveCount(1)->and($match[0]['attributes']['enabled'])->toBeFalse()
        ->and($helper)->toContain('CreatorsScriptsEnabled::NAME')
        ->and($docs)->toContain('| `creators_scripts_enabled` |');
});
