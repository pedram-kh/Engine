<?php

declare(strict_types=1);

use App\Modules\Agencies\Database\Factories\AgencyCreatorRelationFactory;
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
        'display_name', 'email', 'application_status', 'kyc_status', 'profile_completeness_score', 'submitted_at',
    ]);
    // Email is surfaced from the related user so admins can identify creators.
    expect($response->json('data.0.attributes.email'))->toBe($pending->user?->email);
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

/**
 * AH-079 — the ?connected= filter. "Connected" = the AH-051 messaging gate
 * (permitsMessaging: roster + non-blacklisted) applied to ANY agency, not
 * "has a roster row" — a rostered-but-blacklisted creator is NOT connected.
 * §5.34's disjoint set below is the fixture every case below draws from.
 */
function seedConnectedDisjointSet(): array
{
    $pendingRequestOnly = CreatorFactory::new()->approved()->createOne();
    AgencyCreatorRelationFactory::new()->pendingRequest()->create(['creator_id' => $pendingRequestOnly->id]);

    $rosteredBlacklisted = CreatorFactory::new()->approved()->createOne();
    AgencyCreatorRelationFactory::new()->blacklisted()->create(['creator_id' => $rosteredBlacklisted->id]);

    $rosteredClean = CreatorFactory::new()->approved()->createOne();
    AgencyCreatorRelationFactory::new()->create(['creator_id' => $rosteredClean->id]);

    $noRelations = CreatorFactory::new()->approved()->createOne();

    return compact('pendingRequestOnly', 'rosteredBlacklisted', 'rosteredClean', 'noRelations');
}

it('connected=true matches only a rostered, non-blacklisted relation (§5.34 disjoint set)', function (): void {
    $admin = makeIndexAdmin();
    $set = seedConnectedDisjointSet();

    $response = $this->actingAs($admin, 'web_admin')
        ->getJson('/api/v1/admin/creators?connected=true');

    expect($response->status())->toBe(200);
    expect($response->json('meta.total'))->toBe(1);
    expect($response->json('data.0.id'))->toBe($set['rosteredClean']->ulid);
});

it('a pending_request-only relation does NOT count as connected', function (): void {
    $admin = makeIndexAdmin();
    $set = seedConnectedDisjointSet();

    $response = $this->actingAs($admin, 'web_admin')
        ->getJson('/api/v1/admin/creators?connected=true');

    $ids = array_column($response->json('data'), 'id');
    expect($ids)->not->toContain($set['pendingRequestOnly']->ulid);
});

it('a rostered-but-blacklisted relation does NOT count as connected', function (): void {
    $admin = makeIndexAdmin();
    $set = seedConnectedDisjointSet();

    $response = $this->actingAs($admin, 'web_admin')
        ->getJson('/api/v1/admin/creators?connected=true');

    $ids = array_column($response->json('data'), 'id');
    expect($ids)->not->toContain($set['rosteredBlacklisted']->ulid);
});

it('connected=false returns exactly the complement of connected=true, not merely "no roster row"', function (): void {
    $admin = makeIndexAdmin();
    $set = seedConnectedDisjointSet();

    $all = $this->actingAs($admin, 'web_admin')->getJson('/api/v1/admin/creators');
    $connected = $this->actingAs($admin, 'web_admin')->getJson('/api/v1/admin/creators?connected=true');
    $notConnected = $this->actingAs($admin, 'web_admin')->getJson('/api/v1/admin/creators?connected=false');

    expect($all->json('meta.total'))->toBe(4);
    expect($connected->json('meta.total'))->toBe(1);
    expect($notConnected->json('meta.total'))->toBe(3);

    $notConnectedIds = array_column($notConnected->json('data'), 'id');
    // The complement includes the pending-request-only and blacklisted-rostered
    // creators specifically BECAUSE they fail permitsMessaging(), not because
    // they lack a relation row entirely (that's $noRelations, a fourth reason).
    expect($notConnectedIds)->toEqualCanonicalizing([
        $set['pendingRequestOnly']->ulid,
        $set['rosteredBlacklisted']->ulid,
        $set['noRelations']->ulid,
    ]);
});

it('returns an empty page for an unknown connected value', function (): void {
    $admin = makeIndexAdmin();
    seedConnectedDisjointSet();

    $response = $this->actingAs($admin, 'web_admin')
        ->getJson('/api/v1/admin/creators?connected=maybe');

    expect($response->status())->toBe(200);
    expect($response->json('meta.total'))->toBe(0);
});

it('AND-composes status, kyc_status, and connected (chips are combinable, not exclusive)', function (): void {
    $admin = makeIndexAdmin();

    $match = CreatorFactory::new()->approved()->kycVerified()->createOne();
    AgencyCreatorRelationFactory::new()->create(['creator_id' => $match->id]);

    // Same status + kyc, but not connected — the connected leg must exclude it.
    $notConnected = CreatorFactory::new()->approved()->kycVerified()->createOne();

    // Connected + approved, but wrong kyc_status — the kyc leg must exclude it.
    $wrongKyc = CreatorFactory::new()->approved()->createOne();
    AgencyCreatorRelationFactory::new()->create(['creator_id' => $wrongKyc->id]);

    $response = $this->actingAs($admin, 'web_admin')
        ->getJson('/api/v1/admin/creators?status=approved&kyc_status=verified&connected=true');

    expect($response->status())->toBe(200);
    expect($response->json('meta.total'))->toBe(1);
    expect($response->json('data.0.id'))->toBe($match->ulid);

    $ids = array_column($response->json('data'), 'id');
    expect($ids)->not->toContain($notConnected->ulid);
    expect($ids)->not->toContain($wrongKyc->ulid);
});
