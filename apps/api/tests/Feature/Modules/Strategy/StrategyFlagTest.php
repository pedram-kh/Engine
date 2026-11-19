<?php

declare(strict_types=1);

use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Features\StrategyEnabled;
use App\Modules\Waves\Features\WavesEnabled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * PL-2.1 — K3 and K4: the `strategy_enabled` flag. Default OFF; `features.strategy` on `/me` is true only for an agency
 * user with BOTH flags on; the flag is in the admin list and in the test-helper allowlist.
 */

it('K3: is OFF on a fresh database and has the registry name', function (): void {
    expect(StrategyEnabled::NAME)->toBe('strategy_enabled')
        ->and(Feature::active(StrategyEnabled::NAME))->toBeFalse()
        ->and(StrategyEnabled::active())->toBeFalse();
});

it('K3: features.strategy is true only when waves and strategy are both on, for an agency user', function (): void {
    $user = User::factory()->agencyAdmin()->createOne();
    $me = fn () => $this->actingAs($user, 'web')->getJson('/api/v1/me')->assertOk()->json('data.attributes.features');

    expect($me()['strategy'])->toBeFalse();

    Feature::activate(StrategyEnabled::NAME);
    expect($me()['strategy'])->toBeFalse()->and($me()['waves'])->toBeFalse();

    Feature::activate(WavesEnabled::NAME);
    expect($me()['strategy'])->toBeTrue();

    Feature::deactivate(StrategyEnabled::NAME);
    expect($me()['strategy'])->toBeFalse()->and($me()['waves'])->toBeTrue();
});

it('K3: features.strategy is false for a creator and a platform admin even with both flags on', function (): void {
    Feature::activate(WavesEnabled::NAME);
    Feature::activate(StrategyEnabled::NAME);

    foreach ([User::factory()->creator()->createOne(), User::factory()->platformAdmin()->createOne()] as $user) {
        $this->actingAs($user, 'web')->getJson('/api/v1/me')
            ->assertOk()->assertJsonPath('data.attributes.features.strategy', false);
    }
});

it('K4: the flag is listed on the admin page with a label and a description and can be toggled', function (): void {
    $admin = User::factory()->create(['type' => UserType::PlatformAdmin, 'two_factor_confirmed_at' => now()]);

    /** @var list<array{attributes: array{name: string, label: string, description: string, enabled: bool}}> $rows */
    $rows = $this->actingAs($admin, 'web_admin')->getJson('/api/v1/admin/feature-flags')->assertOk()->json('data');
    $match = array_values(array_filter($rows, static fn (array $r): bool => $r['attributes']['name'] === StrategyEnabled::NAME));

    expect($match)->toHaveCount(1)
        ->and($match[0]['attributes']['label'])->toBe('Strategy workspace')
        ->and($match[0]['attributes']['enabled'])->toBeFalse();
});

it('K4: the flag is in the test-helper allowlist and in docs/feature-flags.md', function (): void {
    $helper = file_get_contents(base_path('app/TestHelpers/Http/Controllers/SetFeatureFlagController.php'));
    $docs = file_get_contents(base_path('../../docs/feature-flags.md'));

    expect($helper)->toContain('StrategyEnabled::NAME')
        ->and($docs)->toContain('| `strategy_enabled` |');
});
