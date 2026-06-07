<?php

declare(strict_types=1);

use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Sprint 13 (D-12) — the admin operational-alerts consumer.
 *
 *   GET /api/v1/admin/alerts
 *
 * The non-payment admin notification surface: the admin's own operational
 * feed, reusing the S11.0 notification subsystem. Payment-event alerts are
 * HELD BACK (coming-soon, D-13) — filtered out of the feed and surfaced as
 * a discrete `meta.payment_alerts` block, NOT silently dropped.
 * platform_admin-gated (401 / 403).
 */
function makeAlertsAdmin(): User
{
    return User::factory()->create([
        'type' => UserType::PlatformAdmin,
        'two_factor_confirmed_at' => now(),
    ]);
}

it('401s an unauthenticated request', function (): void {
    expect($this->getJson('/api/v1/admin/alerts')->status())->toBe(401);
});

it('403s a non-admin', function (): void {
    $nonAdmin = User::factory()->create([
        'type' => UserType::AgencyUser,
        'two_factor_confirmed_at' => now(),
    ]);

    expect($this->actingAs($nonAdmin, 'web_admin')->getJson('/api/v1/admin/alerts')->status())
        ->toBe(403);
});

it('returns an empty feed with the payment-alerts coming-soon block (the shell)', function (): void {
    $admin = makeAlertsAdmin();

    $response = $this->actingAs($admin, 'web_admin')->getJson('/api/v1/admin/alerts');

    expect($response->status())->toBe(200);
    expect($response->json('data'))->toBe([]);
    expect($response->json('meta.total'))->toBe(0);
    expect($response->json('meta.payment_alerts.coming_soon'))->toBeTrue();
    expect($response->json('meta.payment_alerts.types'))->toBe([
        'assignment.payment_funded',
        'assignment.payment_released',
    ]);
});

it('surfaces the admin own non-payment operational alerts', function (): void {
    $admin = makeAlertsAdmin();

    Notification::factory()
        ->ofType(NotificationType::CreatorApproved)
        ->create(['recipient_user_id' => $admin->getKey()]);

    $response = $this->actingAs($admin, 'web_admin')->getJson('/api/v1/admin/alerts');

    expect($response->status())->toBe(200);
    expect($response->json('meta.total'))->toBe(1);
    expect($response->json('data.0.attributes.notification_type'))->toBe('creator.approved');
});

it('holds back payment-event alerts from the feed (coming-soon, not shown)', function (): void {
    $admin = makeAlertsAdmin();

    // A payment-event alert addressed to the admin — must NOT appear in the
    // feed this sprint (the emit is S10), even if a row somehow exists.
    Notification::factory()
        ->ofType(NotificationType::AssignmentPaymentReleased)
        ->create(['recipient_user_id' => $admin->getKey()]);

    Notification::factory()
        ->ofType(NotificationType::CreatorRejected)
        ->create(['recipient_user_id' => $admin->getKey()]);

    $response = $this->actingAs($admin, 'web_admin')->getJson('/api/v1/admin/alerts');

    expect($response->json('meta.total'))->toBe(1);
    $types = collect((array) $response->json('data'))->pluck('attributes.notification_type')->all();
    expect($types)->toBe(['creator.rejected']);
});

it('never leaks another admin notifications (user-scoped)', function (): void {
    $admin = makeAlertsAdmin();
    $otherAdmin = makeAlertsAdmin();

    Notification::factory()
        ->ofType(NotificationType::CreatorApproved)
        ->create(['recipient_user_id' => $otherAdmin->getKey()]);

    $response = $this->actingAs($admin, 'web_admin')->getJson('/api/v1/admin/alerts');

    expect($response->json('meta.total'))->toBe(0);
    expect($response->json('data'))->toBe([]);
});
