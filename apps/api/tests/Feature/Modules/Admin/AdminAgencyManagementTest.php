<?php

declare(strict_types=1);

use App\Modules\Agencies\Database\Factories\AgencyFactory;
use App\Modules\Agencies\Database\Factories\AgencyMembershipFactory;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Sprint 13 (D-3) — admin agency management.
 *
 *   GET  /api/v1/admin/agencies
 *   GET  /api/v1/admin/agencies/{agency}
 *   POST /api/v1/admin/agencies/{agency}/suspend
 *   POST /api/v1/admin/agencies/{agency}/reactivate
 *
 * Authorization is the platform_admin bounded bypass; suspend/reactivate
 * are transactional with their audit rows (mandatory reason on suspend).
 */
function makeAgencyMgmtAdmin(): User
{
    return User::factory()->create([
        'type' => UserType::PlatformAdmin,
        'two_factor_confirmed_at' => now(),
    ]);
}

// ─── Authorization (the standing 403/401 standard) ──────────────────────

it('lists every agency for a platform admin (cross-agency bounded bypass)', function (): void {
    $admin = makeAgencyMgmtAdmin();
    AgencyFactory::new()->count(3)->create();

    $response = $this->actingAs($admin, 'web_admin')->getJson('/api/v1/admin/agencies');

    expect($response->status())->toBe(200);
    expect($response->json('meta.total'))->toBe(3);
});

it('403s an agency user hitting the admin agency list', function (): void {
    $agencyUser = User::factory()->create([
        'type' => UserType::AgencyUser,
        'two_factor_confirmed_at' => now(),
    ]);

    $response = $this->actingAs($agencyUser, 'web_admin')->getJson('/api/v1/admin/agencies');

    expect($response->status())->toBe(403);
});

it('401s an unauthenticated agency-list request', function (): void {
    expect($this->getJson('/api/v1/admin/agencies')->status())->toBe(401);
});

// ─── Filtering / detail ─────────────────────────────────────────────────

it('filters the list by suspended status', function (): void {
    $admin = makeAgencyMgmtAdmin();
    AgencyFactory::new()->createOne();
    AgencyFactory::new()->suspended()->createOne();

    $response = $this->actingAs($admin, 'web_admin')->getJson('/api/v1/admin/agencies?status=suspended');

    expect($response->status())->toBe(200);
    expect($response->json('meta.total'))->toBe(1);
    expect($response->json('data.0.attributes.is_suspended'))->toBeTrue();
});

it('returns the agency detail with member count', function (): void {
    $admin = makeAgencyMgmtAdmin();
    $agency = AgencyFactory::new()->createOne();
    AgencyMembershipFactory::new()->forAgency($agency)->count(2)->create();

    $response = $this->actingAs($admin, 'web_admin')->getJson("/api/v1/admin/agencies/{$agency->ulid}");

    expect($response->status())->toBe(200);
    expect($response->json('data.attributes.member_count'))->toBe(2);
    expect($response->json('data.attributes.is_suspended'))->toBeFalse();
});

// ─── Suspend ────────────────────────────────────────────────────────────

it('suspends an agency, persists the reason, and writes the audit row', function (): void {
    $admin = makeAgencyMgmtAdmin();
    $agency = AgencyFactory::new()->createOne();

    $response = $this->actingAs($admin, 'web_admin')
        ->postJson("/api/v1/admin/agencies/{$agency->ulid}/suspend", [
            'reason' => 'Fraudulent activity detected on the account.',
        ]);

    expect($response->status())->toBe(200);
    expect($response->json('data.attributes.is_suspended'))->toBeTrue();

    $agency->refresh();
    expect($agency->suspended_at)->not->toBeNull()
        ->and($agency->is_active)->toBeFalse()
        ->and($agency->suspended_reason)->toBe('Fraudulent activity detected on the account.');

    $audit = AuditLog::query()
        ->where('action', AuditAction::AgencySuspended->value)
        ->latest('id')
        ->firstOrFail();
    expect($audit->actor_id)->toBe($admin->id)
        ->and($audit->reason)->toBe('Fraudulent activity detected on the account.');
});

it('rejects a suspend with no reason (mandatory reason)', function (): void {
    $admin = makeAgencyMgmtAdmin();
    $agency = AgencyFactory::new()->createOne();

    $response = $this->actingAs($admin, 'web_admin')
        ->postJson("/api/v1/admin/agencies/{$agency->ulid}/suspend", []);

    expect($response->status())->toBe(422);
    expect($agency->refresh()->isSuspended())->toBeFalse();
});

it('409s when suspending an already-suspended agency (idempotency)', function (): void {
    $admin = makeAgencyMgmtAdmin();
    $agency = AgencyFactory::new()->suspended()->createOne();

    $response = $this->actingAs($admin, 'web_admin')
        ->postJson("/api/v1/admin/agencies/{$agency->ulid}/suspend", [
            'reason' => 'Another reason that is long enough.',
        ]);

    expect($response->status())->toBe(409);
    expect($response->json('errors.0.code'))->toBe('agency.already_suspended');
});

it('403s an agency user attempting to suspend', function (): void {
    $agencyUser = User::factory()->create([
        'type' => UserType::AgencyUser,
        'two_factor_confirmed_at' => now(),
    ]);
    $agency = AgencyFactory::new()->createOne();

    $response = $this->actingAs($agencyUser, 'web_admin')
        ->postJson("/api/v1/admin/agencies/{$agency->ulid}/suspend", [
            'reason' => 'Should never be allowed for an agency user.',
        ]);

    expect($response->status())->toBe(403);
    expect($agency->refresh()->isSuspended())->toBeFalse();
});

// ─── Reactivate ─────────────────────────────────────────────────────────

it('reactivates a suspended agency and writes the audit row', function (): void {
    $admin = makeAgencyMgmtAdmin();
    $agency = AgencyFactory::new()->suspended()->createOne();

    $response = $this->actingAs($admin, 'web_admin')
        ->postJson("/api/v1/admin/agencies/{$agency->ulid}/reactivate");

    expect($response->status())->toBe(200);
    expect($response->json('data.attributes.is_suspended'))->toBeFalse();

    $agency->refresh();
    expect($agency->suspended_at)->toBeNull()
        ->and($agency->suspended_reason)->toBeNull()
        ->and($agency->is_active)->toBeTrue();

    expect(
        AuditLog::query()->where('action', AuditAction::AgencyReactivated->value)->count(),
    )->toBe(1);
});

it('409s when reactivating an agency that is not suspended', function (): void {
    $admin = makeAgencyMgmtAdmin();
    $agency = AgencyFactory::new()->createOne();

    $response = $this->actingAs($admin, 'web_admin')
        ->postJson("/api/v1/admin/agencies/{$agency->ulid}/reactivate");

    expect($response->status())->toBe(409);
    expect($response->json('errors.0.code'))->toBe('agency.not_suspended');
});
