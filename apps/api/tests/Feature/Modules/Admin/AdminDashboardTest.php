<?php

declare(strict_types=1);

use App\Modules\Agencies\Database\Factories\AgencyFactory;
use App\Modules\Audit\Database\Factories\AuditLogFactory;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Enums\ApplicationStatus;
use App\Modules\Creators\Enums\KycStatus;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Sprint 13 (D-7) — the admin operational dashboard.
 *
 *   GET /api/v1/admin/dashboard/summary
 *   GET /api/v1/admin/dashboard/activity
 *
 * Non-payment KPIs are real counts; payment/dispute cards are stable null
 * placeholders (D-13). The activity feed is the recent cross-agency audit
 * trail, newest-first. platform_admin-gated (the bounded bypass).
 */
function makeDashboardAdmin(): User
{
    return User::factory()->create([
        'type' => UserType::PlatformAdmin,
        'two_factor_confirmed_at' => now(),
    ]);
}

it('401s an unauthenticated summary request', function (): void {
    expect($this->getJson('/api/v1/admin/dashboard/summary')->status())->toBe(401);
});

it('403s a non-admin hitting the summary', function (): void {
    $nonAdmin = User::factory()->create([
        'type' => UserType::AgencyUser,
        'two_factor_confirmed_at' => now(),
    ]);

    expect($this->actingAs($nonAdmin, 'web_admin')->getJson('/api/v1/admin/dashboard/summary')->status())
        ->toBe(403);
});

it('returns real non-payment counts and null payment placeholders', function (): void {
    $admin = makeDashboardAdmin();

    AgencyFactory::new()->count(2)->create();
    AgencyFactory::new()->suspended()->createOne();
    CreatorFactory::new()->count(3)->create(['application_status' => ApplicationStatus::Pending->value]);
    CreatorFactory::new()->createOne(['kyc_status' => KycStatus::Pending->value]);

    $response = $this->actingAs($admin, 'web_admin')->getJson('/api/v1/admin/dashboard/summary');

    expect($response->status())->toBe(200);
    expect($response->json('data.agencies_total'))->toBe(3);
    expect($response->json('data.agencies_active'))->toBe(2);
    expect($response->json('data.agencies_suspended'))->toBe(1);
    expect($response->json('data.creators_pending_approval'))->toBe(3);
    expect($response->json('data.creators_pending_kyc'))->toBeGreaterThanOrEqual(1);
    expect($response->json('data'))->toHaveKeys(['queue_pending', 'queue_failed']);
    // Coming-soon payment cards: stable null contract (D-13).
    expect($response->json('data.open_disputes'))->toBeNull();
    expect($response->json('data.failed_payments_today'))->toBeNull();
});

it('returns the recent audit activity feed newest-first', function (): void {
    $admin = makeDashboardAdmin();

    AuditLogFactory::new()->create(['action' => AuditAction::AuthLoginSucceeded, 'created_at' => now()->subMinute()]);
    AuditLogFactory::new()->create(['action' => AuditAction::AgencySuspended, 'reason' => 'Suspended for cause.', 'created_at' => now()]);

    $response = $this->actingAs($admin, 'web_admin')->getJson('/api/v1/admin/dashboard/activity');

    expect($response->status())->toBe(200);
    expect($response->json('data.0.attributes.action'))->toBe('agency.suspended');
    expect($response->json('data.0.attributes'))->toHaveKeys([
        'action', 'actor_name', 'actor_email', 'reason', 'created_at',
    ]);
});

it('403s a non-admin hitting the activity feed', function (): void {
    $nonAdmin = User::factory()->create([
        'type' => UserType::AgencyUser,
        'two_factor_confirmed_at' => now(),
    ]);

    expect($this->actingAs($nonAdmin, 'web_admin')->getJson('/api/v1/admin/dashboard/activity')->status())
        ->toBe(403);
});
