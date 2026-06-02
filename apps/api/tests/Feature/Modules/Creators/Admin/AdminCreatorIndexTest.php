<?php

declare(strict_types=1);

use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Enums\ApplicationStatus;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Sprint 4 Chunk 3 — Cluster 3: the admin review queue.
 *
 *   GET /api/v1/admin/creators?status=pending
 *
 * platform_admin-gated (CreatorPolicy::viewAny), filterable by
 * application_status, paginated, returns list-card fields only.
 */
function makeIndexAdmin(): User
{
    return User::factory()->create([
        'type' => UserType::PlatformAdmin,
        'two_factor_confirmed_at' => now(),
    ]);
}

it('returns only creators matching the status filter', function (): void {
    $admin = makeIndexAdmin();
    $pending = CreatorFactory::new()->submitted()->createOne();
    CreatorFactory::new()->approved()->createOne();
    CreatorFactory::new()->createOne(['application_status' => ApplicationStatus::Incomplete->value]);

    $response = $this->actingAs($admin, 'web_admin')
        ->getJson('/api/v1/admin/creators?status=pending');

    expect($response->status())->toBe(200);
    expect($response->json('meta.total'))->toBe(1);
    expect($response->json('data.0.id'))->toBe($pending->ulid);
    expect($response->json('data.0.attributes.application_status'))->toBe('pending');
    // List-card fields present; no admin drill-in payload.
    expect($response->json('data.0.attributes'))->toHaveKeys([
        'display_name', 'application_status', 'kyc_status', 'profile_completeness_score', 'submitted_at',
    ]);
});

it('returns all creators when no status filter is supplied', function (): void {
    $admin = makeIndexAdmin();
    CreatorFactory::new()->submitted()->createOne();
    CreatorFactory::new()->approved()->createOne();

    $response = $this->actingAs($admin, 'web_admin')
        ->getJson('/api/v1/admin/creators');

    expect($response->status())->toBe(200);
    expect($response->json('meta.total'))->toBe(2);
});

it('paginates with per_page and reports paging meta', function (): void {
    $admin = makeIndexAdmin();
    CreatorFactory::new()->submitted()->count(3)->create();

    $response = $this->actingAs($admin, 'web_admin')
        ->getJson('/api/v1/admin/creators?status=pending&per_page=2&page=1');

    expect($response->status())->toBe(200);
    expect($response->json('meta.total'))->toBe(3);
    expect($response->json('meta.per_page'))->toBe(2);
    expect($response->json('meta.last_page'))->toBe(2);
    expect($response->json('data'))->toHaveCount(2);
});

it('returns an empty page for an unknown status value', function (): void {
    $admin = makeIndexAdmin();
    CreatorFactory::new()->submitted()->createOne();

    $response = $this->actingAs($admin, 'web_admin')
        ->getJson('/api/v1/admin/creators?status=not_a_status');

    expect($response->status())->toBe(200);
    expect($response->json('meta.total'))->toBe(0);
});

it('returns 403 when the authenticated user is not platform_admin', function (): void {
    CreatorFactory::new()->submitted()->createOne();
    $otherUser = User::factory()->create([
        'type' => UserType::Creator,
        'two_factor_confirmed_at' => now(),
    ]);

    $response = $this->actingAs($otherUser, 'web_admin')
        ->getJson('/api/v1/admin/creators');

    expect($response->status())->toBe(403);
});

it('returns 401 when no admin is authenticated', function (): void {
    $response = $this->getJson('/api/v1/admin/creators');

    expect($response->status())->toBe(401);
});
