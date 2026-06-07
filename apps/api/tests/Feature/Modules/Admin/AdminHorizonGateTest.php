<?php

declare(strict_types=1);

use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Sprint 13 (D-8) — the embedded Horizon authorization gate.
 *
 * Horizon is gated behind the platform_admin bound on the `web_admin`
 * guard, with MFA enrolled. The authorization is gate-based (Horizon's
 * Authenticate middleware aborts 403 when the gate fails), so we assert
 * the `viewHorizon` gate directly across the privilege cases.
 */
function makeHorizonAdmin(): User
{
    return User::factory()->create([
        'type' => UserType::PlatformAdmin,
        'two_factor_confirmed_at' => now(),
    ]);
}

it('allows a platform_admin with MFA enrolled', function (): void {
    $admin = makeHorizonAdmin();

    expect(Gate::forUser($admin)->check('viewHorizon'))->toBeTrue();
});

it('denies a platform_admin who has NOT enrolled MFA', function (): void {
    $admin = User::factory()->create([
        'type' => UserType::PlatformAdmin,
        'two_factor_confirmed_at' => null,
    ]);

    expect(Gate::forUser($admin)->check('viewHorizon'))->toBeFalse();
});

it('denies a non-admin user', function (): void {
    $agencyUser = User::factory()->create([
        'type' => UserType::AgencyUser,
        'two_factor_confirmed_at' => now(),
    ]);

    expect(Gate::forUser($agencyUser)->check('viewHorizon'))->toBeFalse();
});

it('denies a guest (no user)', function (): void {
    expect(Gate::check('viewHorizon'))->toBeFalse();
});

it('serves the Horizon dashboard to an authenticated platform_admin', function (): void {
    $admin = makeHorizonAdmin();

    $response = $this->actingAs($admin, 'web_admin')->get('/horizon');

    // Horizon's home route returns its SPA layout (200) once the gate passes.
    expect($response->status())->toBe(200);
});

it('403s a non-admin hitting the Horizon dashboard', function (): void {
    $agencyUser = User::factory()->create([
        'type' => UserType::AgencyUser,
        'two_factor_confirmed_at' => now(),
    ]);

    $response = $this->actingAs($agencyUser, 'web_admin')->get('/horizon');

    expect($response->status())->toBe(403);
});
