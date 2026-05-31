<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Support\DashboardActivityFeed;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

/**
 * Sprint 4 Chunk 1 (1c) — GET /api/v1/agencies/{agency}/dashboard/activity.
 *
 * Agency-stamped, allowlist-curated audit feed. Spot-check anchors:
 *   - allowlist isolation (only ACTION_ALLOWLIST actions appear),
 *   - agency-stamped-only isolation (no agency_id-null rows, no other
 *     agency's rows),
 *   - metadata safety (per-action whitelist; no raw blob leaks),
 *   - newest-first ordering + 15-row cap,
 *   - empty state.
 */

/**
 * Insert an audit_logs row for the feed. `created_at` is explicit (the
 * model has timestamps off), so ordering tests are deterministic.
 *
 * @param  array<string, mixed>  $attributes
 */
function makeAuditRow(AuditAction $action, array $attributes = []): AuditLog
{
    return AuditLog::factory()->create(array_merge([
        'action' => $action,
        'created_at' => now(),
    ], $attributes));
}

// ---------------------------------------------------------------------------
// Auth + tenancy boundary
// ---------------------------------------------------------------------------

it('returns 401 when no user is authenticated', function (): void {
    $agency = Agency::factory()->createOne();

    $response = $this->getJson("/api/v1/agencies/{$agency->ulid}/dashboard/activity");

    expect($response->status())->toBe(401);
});

it('returns 404 when the authenticated user is not a member (tenancy invisibility)', function (): void {
    $agency = Agency::factory()->createOne();
    $otherAgency = Agency::factory()->createOne();
    $outsider = User::factory()->agencyAdmin($otherAgency)->createOne();

    $response = $this->actingAs($outsider)
        ->getJson("/api/v1/agencies/{$agency->ulid}/dashboard/activity");

    expect($response->status())->toBe(404);
});

it('returns an empty data array when the agency has no feed-relevant activity', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $response = $this->actingAs($admin)
        ->getJson("/api/v1/agencies/{$agency->ulid}/dashboard/activity");

    expect($response->status())->toBe(200);
    expect($response->json('data'))->toBe([]);
});

// ---------------------------------------------------------------------------
// Allowlist isolation
// ---------------------------------------------------------------------------

it('includes allowlisted actions and excludes non-allowlisted ones', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    // Allowlisted (lifecycle).
    makeAuditRow(AuditAction::CreatorInvited, ['agency_id' => $agency->id]);
    makeAuditRow(AuditAction::BrandCreated, ['agency_id' => $agency->id]);
    // NOT allowlisted (field churn / noise) — same agency, must be excluded.
    makeAuditRow(AuditAction::BrandUpdated, ['agency_id' => $agency->id]);
    makeAuditRow(AuditAction::BulkInviteStarted, ['agency_id' => $agency->id]);

    $response = $this->actingAs($admin)
        ->getJson("/api/v1/agencies/{$agency->ulid}/dashboard/activity");

    $actions = array_map(fn ($row) => $row['action'], $response->json('data'));
    expect($actions)->toHaveCount(2);
    expect($actions)->toContain('creator.invited');
    expect($actions)->toContain('brand.created');
    expect($actions)->not->toContain('brand.updated');
    expect($actions)->not->toContain('bulk_invite.started');
});

// ---------------------------------------------------------------------------
// Agency-stamped-only isolation
// ---------------------------------------------------------------------------

it('never returns agency_id-null rows or other agencies\' rows', function (): void {
    $agency = Agency::factory()->createOne();
    $other = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    // This agency — allowlisted, stamped → included.
    makeAuditRow(AuditAction::CreatorInvited, ['agency_id' => $agency->id]);
    // Tenant-less (e.g. creator wizard event) — allowlisted action but
    // agency_id null → excluded by the stamping mechanism.
    makeAuditRow(AuditAction::CreatorInvited, ['agency_id' => null]);
    // Another agency — allowlisted + stamped to B → excluded for A.
    makeAuditRow(AuditAction::BrandCreated, ['agency_id' => $other->id]);

    $response = $this->actingAs($admin)
        ->getJson("/api/v1/agencies/{$agency->ulid}/dashboard/activity");

    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.action'))->toBe('creator.invited');
});

// ---------------------------------------------------------------------------
// Row shape + actor label
// ---------------------------------------------------------------------------

it('exposes only render-needed fields with the actor name as actor_label', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne(['name' => 'Grace Hopper']);

    makeAuditRow(AuditAction::CreatorInvited, [
        'agency_id' => $agency->id,
        'actor_type' => 'user',
        'actor_id' => $admin->id,
    ]);

    $response = $this->actingAs($admin)
        ->getJson("/api/v1/agencies/{$agency->ulid}/dashboard/activity");

    $row = $response->json('data.0');
    expect(array_keys($row))->toEqualCanonicalizing([
        'id', 'action', 'actor_label', 'created_at', 'metadata',
    ]);
    expect($row['actor_label'])->toBe('Grace Hopper');
});

it('returns a null actor_label for system (actor-less) rows', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    makeAuditRow(AuditAction::BrandCreated, [
        'agency_id' => $agency->id,
        'actor_type' => 'system',
        'actor_id' => null,
    ]);

    $response = $this->actingAs($admin)
        ->getJson("/api/v1/agencies/{$agency->ulid}/dashboard/activity");

    expect($response->json('data.0.actor_label'))->toBeNull();
});

// ---------------------------------------------------------------------------
// Metadata safety (per-action whitelist, never the raw blob)
// ---------------------------------------------------------------------------

it('strips un-whitelisted metadata keys and exposes only safe ones', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    makeAuditRow(AuditAction::BulkInviteCompleted, [
        'agency_id' => $agency->id,
        'metadata' => [
            'invited' => 4,
            'already_invited' => 1,
            'failed' => 2,
            'failures' => ['leak@example.com'], // PII — must be dropped
            'secret' => 'nope',
        ],
    ]);

    $response = $this->actingAs($admin)
        ->getJson("/api/v1/agencies/{$agency->ulid}/dashboard/activity");

    $metadata = $response->json('data.0.metadata');
    expect(array_keys($metadata))->toEqualCanonicalizing(['invited', 'already_invited', 'failed']);
    expect($metadata)->not->toHaveKey('failures');
    expect($metadata)->not->toHaveKey('secret');
});

it('exposes no metadata for an allowlisted action that has no whitelist (creator.invited)', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    // Even if a creator.invited row somehow carried a sensitive blob, the
    // empty whitelist for that action drops every key.
    makeAuditRow(AuditAction::CreatorInvited, [
        'agency_id' => $agency->id,
        'metadata' => ['email' => 'leak@example.com'],
    ]);

    $response = $this->actingAs($admin)
        ->getJson("/api/v1/agencies/{$agency->ulid}/dashboard/activity");

    expect($response->json('data.0.metadata'))->toBe([]);
});

it('asserts no un-whitelisted metadata key reaches the response across mixed rows', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    makeAuditRow(AuditAction::BulkInviteCompleted, [
        'agency_id' => $agency->id,
        'metadata' => ['invited' => 1, 'already_invited' => 0, 'failed' => 0, 'failures' => ['x@y.com']],
    ]);
    makeAuditRow(AuditAction::CreatorInvited, [
        'agency_id' => $agency->id,
        'metadata' => ['email' => 'leak@example.com', 'token' => 'abc'],
    ]);

    $response = $this->actingAs($admin)
        ->getJson("/api/v1/agencies/{$agency->ulid}/dashboard/activity");

    $forbidden = ['failures', 'secret', 'email', 'token'];
    foreach ($response->json('data') as $row) {
        foreach (array_keys($row['metadata']) as $key) {
            expect($forbidden)->not->toContain($key);
        }
    }
});

// ---------------------------------------------------------------------------
// Ordering + cap
// ---------------------------------------------------------------------------

it('orders rows newest-first', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    makeAuditRow(AuditAction::BrandCreated, [
        'agency_id' => $agency->id,
        'created_at' => now()->subDays(2),
    ]);
    makeAuditRow(AuditAction::CreatorInvited, [
        'agency_id' => $agency->id,
        'created_at' => now()->subDay(),
    ]);
    makeAuditRow(AuditAction::BrandArchived, [
        'agency_id' => $agency->id,
        'created_at' => now(),
    ]);

    $response = $this->actingAs($admin)
        ->getJson("/api/v1/agencies/{$agency->ulid}/dashboard/activity");

    $actions = array_map(fn ($row) => $row['action'], $response->json('data'));
    expect($actions)->toBe(['brand.archived', 'creator.invited', 'brand.created']);
});

it('caps the feed at FEED_LIMIT rows', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $total = DashboardActivityFeed::FEED_LIMIT + 3;
    for ($i = 0; $i < $total; $i++) {
        makeAuditRow(AuditAction::CreatorInvited, [
            'agency_id' => $agency->id,
            'created_at' => now()->subMinutes($i),
        ]);
    }

    $response = $this->actingAs($admin)
        ->getJson("/api/v1/agencies/{$agency->ulid}/dashboard/activity");

    expect($response->json('data'))->toHaveCount(DashboardActivityFeed::FEED_LIMIT);
});
