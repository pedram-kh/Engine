<?php

declare(strict_types=1);

use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Sprint 13 (D-8) — the admin system-health probe.
 *
 *   GET /api/v1/admin/health
 *
 * Cheap liveness over DB + cache. platform_admin-gated (the bounded
 * bypass); each probe isolated so one failure doesn't take the request
 * down.
 */
function makeHealthAdmin(): User
{
    return User::factory()->create([
        'type' => UserType::PlatformAdmin,
        'two_factor_confirmed_at' => now(),
    ]);
}

it('401s an unauthenticated probe', function (): void {
    expect($this->getJson('/api/v1/admin/health')->status())->toBe(401);
});

it('403s a non-admin', function (): void {
    $nonAdmin = User::factory()->create([
        'type' => UserType::AgencyUser,
        'two_factor_confirmed_at' => now(),
    ]);

    expect($this->actingAs($nonAdmin, 'web_admin')->getJson('/api/v1/admin/health')->status())
        ->toBe(403);
});

it('reports ok when DB + cache are reachable', function (): void {
    $admin = makeHealthAdmin();

    $response = $this->actingAs($admin, 'web_admin')->getJson('/api/v1/admin/health');

    expect($response->status())->toBe(200);
    expect($response->json('data.status'))->toBe('ok');
    expect($response->json('data.checks.database'))->toBe('ok');
    expect($response->json('data.checks.cache'))->toBe('ok');
});
