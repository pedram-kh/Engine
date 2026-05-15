<?php

declare(strict_types=1);

use App\Modules\Agencies\Enums\AgencyRole;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyUserInvitation;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

/**
 * Sprint 3 Chunk 4 sub-step 3 — GET /api/v1/agencies/{agency}/invitations.
 *
 * Paginated invitation history (pending + accepted + expired). Admin-only
 * — history is sensitive (reveals failed acceptances, expired flows).
 * Non-admin agency members get 403.
 *
 * Default sort is `-invited_at` (== `-created_at`). Status filter is
 * computed against the row's accepted_at / expires_at columns rather
 * than stored — keeps the model simple and matches the existing
 * isPending / isExpired / isAccepted accessors.
 */
it('returns 401 when no user is authenticated', function (): void {
    $agency = Agency::factory()->createOne();

    $response = $this->getJson("/api/v1/agencies/{$agency->ulid}/invitations");

    expect($response->status())->toBe(401);
});

it('returns 403 when a non-admin member lists invitations', function (): void {
    $agency = Agency::factory()->createOne();
    $staff = User::factory()->agencyStaff($agency)->createOne();

    $response = $this->actingAs($staff)
        ->getJson("/api/v1/agencies/{$agency->ulid}/invitations");

    expect($response->status())->toBe(403);
});

it('returns 200 when an agency_admin lists invitations', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $response = $this->actingAs($admin)
        ->getJson("/api/v1/agencies/{$agency->ulid}/invitations");

    expect($response->status())->toBe(200);
});

it('lists invitations with the expected attribute keys including status + invited_at + invited_by_user_name', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne(['name' => 'Admin User']);

    AgencyUserInvitation::factory()->createOne([
        'agency_id' => $agency->id,
        'email' => 'pending@example.com',
        'role' => AgencyRole::AgencyManager->value,
        'invited_by_user_id' => $admin->id,
        'expires_at' => now()->addDays(7),
    ]);

    $response = $this->actingAs($admin)
        ->getJson("/api/v1/agencies/{$agency->ulid}/invitations");

    expect($response->status())->toBe(200);
    expect($response->json('data'))->toHaveCount(1);

    $first = $response->json('data.0.attributes');
    expect($first)->toHaveKey('status');
    expect($first)->toHaveKey('invited_at');
    expect($first)->toHaveKey('invited_by_user_name');
    expect($first['status'])->toBe('pending');
    expect($first['invited_by_user_name'])->toBe('Admin User');
});

it('filters invitations by status=pending', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    AgencyUserInvitation::factory()->createOne([
        'agency_id' => $agency->id,
        'email' => 'pending@example.com',
        'invited_by_user_id' => $admin->id,
        'expires_at' => now()->addDays(7),
    ]);
    AgencyUserInvitation::factory()->createOne([
        'agency_id' => $agency->id,
        'email' => 'accepted@example.com',
        'invited_by_user_id' => $admin->id,
        'expires_at' => now()->addDays(7),
        'accepted_at' => now(),
    ]);
    AgencyUserInvitation::factory()->createOne([
        'agency_id' => $agency->id,
        'email' => 'expired@example.com',
        'invited_by_user_id' => $admin->id,
        'expires_at' => now()->subDays(1),
    ]);

    $response = $this->actingAs($admin)
        ->getJson("/api/v1/agencies/{$agency->ulid}/invitations?status=pending");

    expect($response->status())->toBe(200);
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.attributes.email'))->toBe('pending@example.com');
});

it('filters invitations by status=expired', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    AgencyUserInvitation::factory()->createOne([
        'agency_id' => $agency->id,
        'email' => 'pending@example.com',
        'invited_by_user_id' => $admin->id,
        'expires_at' => now()->addDays(7),
    ]);
    AgencyUserInvitation::factory()->createOne([
        'agency_id' => $agency->id,
        'email' => 'expired@example.com',
        'invited_by_user_id' => $admin->id,
        'expires_at' => now()->subDays(1),
    ]);

    $response = $this->actingAs($admin)
        ->getJson("/api/v1/agencies/{$agency->ulid}/invitations?status=expired");

    expect($response->status())->toBe(200);
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.attributes.email'))->toBe('expired@example.com');
});

it('filters invitations by status=accepted', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    AgencyUserInvitation::factory()->createOne([
        'agency_id' => $agency->id,
        'email' => 'accepted@example.com',
        'invited_by_user_id' => $admin->id,
        'expires_at' => now()->addDays(7),
        'accepted_at' => now(),
    ]);
    AgencyUserInvitation::factory()->createOne([
        'agency_id' => $agency->id,
        'email' => 'pending@example.com',
        'invited_by_user_id' => $admin->id,
        'expires_at' => now()->addDays(7),
    ]);

    $response = $this->actingAs($admin)
        ->getJson("/api/v1/agencies/{$agency->ulid}/invitations?status=accepted");

    expect($response->status())->toBe(200);
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.attributes.status'))->toBe('accepted');
});

it('default sort is -invited_at (newest first)', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    AgencyUserInvitation::factory()->createOne([
        'agency_id' => $agency->id,
        'email' => 'older@example.com',
        'invited_by_user_id' => $admin->id,
        'created_at' => now()->subDays(3),
        'expires_at' => now()->addDays(7),
    ]);
    AgencyUserInvitation::factory()->createOne([
        'agency_id' => $agency->id,
        'email' => 'newer@example.com',
        'invited_by_user_id' => $admin->id,
        'created_at' => now()->subDays(1),
        'expires_at' => now()->addDays(7),
    ]);

    $response = $this->actingAs($admin)
        ->getJson("/api/v1/agencies/{$agency->ulid}/invitations");

    expect($response->json('data.0.attributes.email'))->toBe('newer@example.com');
    expect($response->json('data.1.attributes.email'))->toBe('older@example.com');
});

it('does not leak invitations from other agencies', function (): void {
    $agency = Agency::factory()->createOne();
    $other = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $otherAdmin = User::factory()->agencyAdmin($other)->createOne();

    AgencyUserInvitation::factory()->createOne([
        'agency_id' => $other->id,
        'email' => 'other-pending@example.com',
        'invited_by_user_id' => $otherAdmin->id,
        'expires_at' => now()->addDays(7),
    ]);

    $response = $this->actingAs($admin)
        ->getJson("/api/v1/agencies/{$agency->ulid}/invitations");

    expect($response->status())->toBe(200);
    expect($response->json('data'))->toHaveCount(0);
});
