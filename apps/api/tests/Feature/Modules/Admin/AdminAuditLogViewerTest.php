<?php

declare(strict_types=1);

use App\Modules\Audit\Database\Factories\AuditLogFactory;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Sprint 13 (D-5) — the admin audit-log viewer.
 *
 *   GET /api/v1/admin/audit-logs
 *
 * Read-only, cross-agency, cursor-paginated. Every filter targets an
 * indexed column. platform_admin-gated (the bounded bypass).
 */
function makeAuditViewerAdmin(): User
{
    return User::factory()->create([
        'type' => UserType::PlatformAdmin,
        'two_factor_confirmed_at' => now(),
    ]);
}

function auditRow(AuditAction $action, array $overrides = []): void
{
    AuditLogFactory::new()->create(array_merge([
        'action' => $action,
        'created_at' => now(),
    ], $overrides));
}

it('401s an unauthenticated request', function (): void {
    expect($this->getJson('/api/v1/admin/audit-logs')->status())->toBe(401);
});

it('403s a non-admin', function (): void {
    $nonAdmin = User::factory()->create([
        'type' => UserType::AgencyUser,
        'two_factor_confirmed_at' => now(),
    ]);

    expect($this->actingAs($nonAdmin, 'web_admin')->getJson('/api/v1/admin/audit-logs')->status())->toBe(403);
});

it('returns audit rows newest-first with cursor meta', function (): void {
    $admin = makeAuditViewerAdmin();
    auditRow(AuditAction::AuthLoginSucceeded);
    auditRow(AuditAction::AgencySuspended, ['reason' => 'Suspended for cause.']);

    $response = $this->actingAs($admin, 'web_admin')->getJson('/api/v1/admin/audit-logs');

    expect($response->status())->toBe(200);
    // Newest-first: the last-inserted row (highest id) leads.
    expect($response->json('data.0.attributes.action'))->toBe('agency.suspended');
    expect($response->json('meta'))->toHaveKeys(['per_page', 'next_cursor', 'prev_cursor', 'has_more']);
    expect($response->json('data.0.attributes'))->toHaveKeys([
        'action', 'actor_id', 'actor_name', 'agency_id', 'subject_ulid', 'reason', 'created_at',
    ]);
});

it('filters by action (indexed)', function (): void {
    $admin = makeAuditViewerAdmin();
    auditRow(AuditAction::AuthLoginSucceeded);
    auditRow(AuditAction::FeatureFlagToggled, ['reason' => 'Enabled for launch test.']);
    auditRow(AuditAction::FeatureFlagToggled, ['reason' => 'Disabled again.']);

    $response = $this->actingAs($admin, 'web_admin')
        ->getJson('/api/v1/admin/audit-logs?action=feature_flag.toggled');

    expect($response->status())->toBe(200);
    expect($response->json('data'))->toHaveCount(2);
});

it('filters by actor_id (indexed)', function (): void {
    $admin = makeAuditViewerAdmin();
    $actor = makeAuditViewerAdmin();
    auditRow(AuditAction::AuthLoginSucceeded, ['actor_type' => 'user', 'actor_id' => $actor->id]);
    auditRow(AuditAction::AuthLoginSucceeded);

    $response = $this->actingAs($admin, 'web_admin')
        ->getJson("/api/v1/admin/audit-logs?actor_id={$actor->id}");

    expect($response->status())->toBe(200);
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.attributes.actor_id'))->toBe($actor->id);
});

it('filters by date range (created_at, indexed)', function (): void {
    $admin = makeAuditViewerAdmin();
    // Isolate from the User-creation audit noise by pinning a distinct
    // action and combining the date filter with it.
    auditRow(AuditAction::FeatureFlagToggled, ['reason' => 'Old toggle.', 'created_at' => now()->subDays(10)]);
    auditRow(AuditAction::FeatureFlagToggled, ['reason' => 'Recent toggle.', 'created_at' => now()]);

    $from = now()->subDay()->toDateString();
    $response = $this->actingAs($admin, 'web_admin')
        ->getJson("/api/v1/admin/audit-logs?action=feature_flag.toggled&date_from={$from}");

    expect($response->status())->toBe(200);
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.attributes.reason'))->toBe('Recent toggle.');
});

it('cursor-paginates with per_page', function (): void {
    $admin = makeAuditViewerAdmin();
    for ($i = 0; $i < 5; $i++) {
        auditRow(AuditAction::AuthLoginSucceeded);
    }

    $first = $this->actingAs($admin, 'web_admin')
        ->getJson('/api/v1/admin/audit-logs?per_page=2');

    expect($first->status())->toBe(200);
    expect($first->json('data'))->toHaveCount(2);
    expect($first->json('meta.has_more'))->toBeTrue();

    $cursor = $first->json('meta.next_cursor');
    expect($cursor)->not->toBeNull();

    $second = $this->actingAs($admin, 'web_admin')
        ->getJson("/api/v1/admin/audit-logs?per_page=2&cursor={$cursor}");

    expect($second->status())->toBe(200);
    expect($second->json('data'))->toHaveCount(2);
});
