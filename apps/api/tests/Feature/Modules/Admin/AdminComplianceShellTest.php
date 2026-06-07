<?php

declare(strict_types=1);

use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Sprint 13 (D-11) — the GDPR compliance queue SHELLS.
 *
 *   GET /api/v1/admin/compliance/export-requests
 *   GET /api/v1/admin/compliance/erasure-queue
 *
 * Both ship empty this sprint: 200 + `data: []` + `meta.shell: true`,
 * NOT 404. The 404-vs-empty-list distinction is the contract — the
 * surface EXISTS (S14 fills it), it just has no pending requests yet.
 * platform_admin-gated like every admin endpoint (401 / 403).
 */
function makeComplianceAdmin(): User
{
    return User::factory()->create([
        'type' => UserType::PlatformAdmin,
        'two_factor_confirmed_at' => now(),
    ]);
}

dataset('compliance shells', [
    'export requests' => ['/api/v1/admin/compliance/export-requests'],
    'erasure queue' => ['/api/v1/admin/compliance/erasure-queue'],
]);

it('401s an unauthenticated request', function (string $url): void {
    expect($this->getJson($url)->status())->toBe(401);
})->with('compliance shells');

it('403s a non-admin', function (string $url): void {
    $nonAdmin = User::factory()->create([
        'type' => UserType::AgencyUser,
        'two_factor_confirmed_at' => now(),
    ]);

    expect($this->actingAs($nonAdmin, 'web_admin')->getJson($url)->status())->toBe(403);
})->with('compliance shells');

it('returns an empty shell list (200, not 404) for an admin', function (string $url): void {
    $admin = makeComplianceAdmin();

    $response = $this->actingAs($admin, 'web_admin')->getJson($url);

    expect($response->status())->toBe(200);
    expect($response->json('data'))->toBe([]);
    expect($response->json('meta.total'))->toBe(0);
    expect($response->json('meta.shell'))->toBeTrue();
})->with('compliance shells');
