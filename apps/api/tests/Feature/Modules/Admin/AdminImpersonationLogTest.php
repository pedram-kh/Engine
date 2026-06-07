<?php

declare(strict_types=1);

use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\ImpersonationSession;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Sprint 13 (D-9) — the impersonation log: GET /admin/impersonate/sessions.
 *
 * Read-only, cross-agency, cursor-paginated view over the append-only
 * admin_impersonation_sessions table. Gated by platform_admin + the admin
 * guard. Status is derived from the TTL authority (ended_at / expires_at).
 */
function makeLogAdmin(): User
{
    return User::factory()->create([
        'type' => UserType::PlatformAdmin,
        'two_factor_confirmed_at' => now(),
    ]);
}

function makeLogTarget(): User
{
    return User::factory()->create(['type' => UserType::AgencyUser]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function makeSession(User $admin, User $target, array $attributes = []): ImpersonationSession
{
    return ImpersonationSession::query()->create(array_merge([
        'admin_user_id' => $admin->id,
        'impersonated_user_id' => $target->id,
        'reason' => 'Investigating a reported checkout bug.',
        'started_at' => now(),
        'expires_at' => now()->addMinutes(30),
        'ip' => '127.0.0.1',
        'created_at' => now(),
    ], $attributes));
}

it('401s an unauthenticated request', function (): void {
    expect($this->getJson('/api/v1/admin/impersonate/sessions')->status())->toBe(401);
});

it('403s a non-admin', function (): void {
    $nonAdmin = makeLogTarget();

    expect($this->actingAs($nonAdmin, 'web_admin')->getJson('/api/v1/admin/impersonate/sessions')->status())
        ->toBe(403);
});

it('lists sessions newest-first with both parties resolved', function (): void {
    $admin = makeLogAdmin();
    $first = makeLogTarget();
    $second = makeLogTarget();

    makeSession($admin, $first, ['reason' => 'First investigation.']);
    makeSession($admin, $second, ['reason' => 'Second investigation.']);

    $response = $this->actingAs($admin, 'web_admin')->getJson('/api/v1/admin/impersonate/sessions');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(2);
    // Newest (highest id) first.
    expect($response->json('data.0.attributes.reason'))->toBe('Second investigation.');
    expect($response->json('data.0.attributes.admin_email'))->toBe($admin->email);
    expect($response->json('data.0.attributes.impersonated_user_email'))->toBe($second->email);
    expect($response->json('data.0.attributes.status'))->toBe('active');
});

it('derives status from the TTL authority', function (): void {
    $admin = makeLogAdmin();
    $target = makeLogTarget();

    // Created oldest-first; the list returns them newest-first (id desc).
    makeSession($admin, $target, ['expires_at' => now()->addMinutes(30), 'ended_at' => null]);
    makeSession($admin, $target, ['expires_at' => now()->subMinute(), 'ended_at' => null]);
    makeSession($admin, $target, ['ended_at' => now(), 'expires_at' => now()->addMinutes(30)]);

    $response = $this->actingAs($admin, 'web_admin')->getJson('/api/v1/admin/impersonate/sessions');

    $response->assertOk();
    $response->assertJsonPath('data.0.attributes.status', 'ended');
    $response->assertJsonPath('data.1.attributes.status', 'expired');
    $response->assertJsonPath('data.2.attributes.status', 'active');
});

it('filters by status=active (live, not ended, not expired)', function (): void {
    $admin = makeLogAdmin();
    $target = makeLogTarget();

    makeSession($admin, $target, ['expires_at' => now()->addMinutes(30)]);
    makeSession($admin, $target, ['expires_at' => now()->subMinute()]);
    makeSession($admin, $target, ['ended_at' => now()]);

    $response = $this->actingAs($admin, 'web_admin')
        ->getJson('/api/v1/admin/impersonate/sessions?status=active');

    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.attributes.status'))->toBe('active');
});

it('searches by the impersonated user email', function (): void {
    $admin = makeLogAdmin();
    $needle = User::factory()->create(['type' => UserType::AgencyUser, 'email' => 'needle@example.test']);
    $other = makeLogTarget();

    makeSession($admin, $needle);
    makeSession($admin, $other);

    $response = $this->actingAs($admin, 'web_admin')
        ->getJson('/api/v1/admin/impersonate/sessions?q=needle@example.test');

    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.attributes.impersonated_user_email'))->toBe('needle@example.test');
});

it('cursor-paginates with next/prev tokens', function (): void {
    $admin = makeLogAdmin();
    $target = makeLogTarget();

    foreach (range(1, 3) as $i) {
        makeSession($admin, $target, ['reason' => "Investigation {$i}."]);
    }

    $first = $this->actingAs($admin, 'web_admin')
        ->getJson('/api/v1/admin/impersonate/sessions?per_page=2');

    $first->assertOk();
    expect($first->json('data'))->toHaveCount(2);
    expect($first->json('meta.has_more'))->toBeTrue();
    expect($first->json('meta.next_cursor'))->toBeString();

    $cursor = $first->json('meta.next_cursor');
    $second = $this->actingAs($admin, 'web_admin')
        ->getJson("/api/v1/admin/impersonate/sessions?per_page=2&cursor={$cursor}");

    $second->assertOk();
    expect($second->json('data'))->toHaveCount(1);
    expect($second->json('meta.has_more'))->toBeFalse();
});
