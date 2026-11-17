<?php

declare(strict_types=1);

use App\Modules\Admin\Http\Controllers\AdminFeatureFlagController;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use App\Modules\Waves\Features\WavesEnabled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * PL-1.1 — the `waves_enabled` flag: default OFF, on `/me` agency-side only, listed and toggleable on the
 * admin Feature-flags page, and present in the admin FLAGS map (B1 flag part, B13, B16).
 */

function wavesFlagAdmin(): User
{
    return User::factory()->create(['type' => UserType::PlatformAdmin, 'two_factor_confirmed_at' => now()]);
}

it('B1: is OFF on a fresh database and has the registry name', function (): void {
    expect(WavesEnabled::NAME)->toBe('waves_enabled')
        ->and(Feature::active(WavesEnabled::NAME))->toBeFalse();
});

it('B1: shows features.waves false by default and true when armed, for an agency user', function (): void {
    $user = User::factory()->agencyAdmin()->createOne();

    $this->actingAs($user, 'web')->getJson('/api/v1/me')
        ->assertOk()->assertJsonPath('data.attributes.features.waves', false);

    Feature::activate(WavesEnabled::NAME);

    $this->actingAs($user, 'web')->getJson('/api/v1/me')
        ->assertOk()->assertJsonPath('data.attributes.features.waves', true);
});

it('B1: shows features.waves false for a creator and a platform admin even when armed', function (): void {
    Feature::activate(WavesEnabled::NAME);

    $creator = User::factory()->creator()->createOne();
    $this->actingAs($creator, 'web')->getJson('/api/v1/me')
        ->assertOk()->assertJsonPath('data.attributes.features.waves', false);

    $admin = User::factory()->platformAdmin()->createOne();
    $this->actingAs($admin, 'web')->getJson('/api/v1/me')
        ->assertOk()->assertJsonPath('data.attributes.features.waves', false);
});

it('B13: lists the flag with its label and description, and toggles it with a reason and an audit row', function (): void {
    $admin = wavesFlagAdmin();

    /** @var list<array{attributes: array{name: string, label: string, description: string, enabled: bool}}> $rows */
    $rows = $this->actingAs($admin, 'web_admin')->getJson('/api/v1/admin/feature-flags')->assertOk()->json('data');
    $matching = array_values(array_filter($rows, static fn (array $r): bool => $r['attributes']['name'] === WavesEnabled::NAME));
    expect($matching)->toHaveCount(1);
    $attributes = $matching[0]['attributes'];

    expect($attributes['label'])->toBe('Waves and client files')
        ->and($attributes['description'])->not->toBe('')
        ->and($attributes['enabled'])->toBeFalse();

    $this->actingAs($admin, 'web_admin')->postJson('/api/v1/admin/feature-flags/'.WavesEnabled::NAME, [
        'enabled' => true,
        'reason' => 'Arming PL-1 on the local walk database.',
    ])->assertOk();

    expect(Feature::active(WavesEnabled::NAME))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'feature_flag.toggled')->count())->toBe(1);
});

it('B16: waves_enabled is in the admin FLAGS map with a label and a description, and is defined OFF', function (): void {
    $flags = (new ReflectionClassConstant(AdminFeatureFlagController::class, 'FLAGS'))->getValue();

    expect($flags)->toHaveKey(WavesEnabled::NAME)
        ->and($flags[WavesEnabled::NAME]['label'])->not->toBe('')
        ->and($flags[WavesEnabled::NAME]['description'])->not->toBe('');

    // Registered with Feature::define and OFF: the closure answers false.
    expect(WavesEnabled::default()())->toBeFalse()
        ->and(Feature::defined())->toContain(WavesEnabled::NAME);
});
