<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\ImpersonationSession;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\ImpersonationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Sprint 13 (D-9) — impersonation core: start / hand-off claim / end and
 * the dual-session mechanism (admin session survives; a separate `web`
 * session acts as the impersonated user).
 */
function makeImpersonationAdmin(): User
{
    return User::factory()->create([
        'type' => UserType::PlatformAdmin,
        'two_factor_confirmed_at' => now(),
    ]);
}

function makeImpersonationTarget(): User
{
    return User::factory()->create([
        'type' => UserType::AgencyUser,
        'two_factor_confirmed_at' => now(),
    ]);
}

it('401s an unauthenticated start', function (): void {
    $target = makeImpersonationTarget();

    expect($this->postJson('/api/v1/admin/impersonate', [
        'user_ulid' => $target->ulid,
        'reason' => 'Investigating a reported bug.',
    ])->status())->toBe(401);
});

it('403s a non-admin start', function (): void {
    $nonAdmin = makeImpersonationTarget();
    $target = makeImpersonationTarget();

    expect($this->actingAs($nonAdmin, 'web_admin')->postJson('/api/v1/admin/impersonate', [
        'user_ulid' => $target->ulid,
        'reason' => 'Investigating a reported bug.',
    ])->status())->toBe(403);
});

it('starts impersonation: writes the row, audits with reason, returns a hand-off token', function (): void {
    $admin = makeImpersonationAdmin();
    $target = makeImpersonationTarget();

    $response = $this->actingAs($admin, 'web_admin')->postJson('/api/v1/admin/impersonate', [
        'user_ulid' => $target->ulid,
        'reason' => 'Investigating a reported checkout bug.',
    ]);

    expect($response->status())->toBe(201);
    expect($response->json('data.attributes.handoff_token'))->toBeString();
    expect($response->json('data.attributes.expires_at'))->toBeString();

    $session = ImpersonationSession::query()->firstOrFail();
    expect($session->admin_user_id)->toBe($admin->id);
    expect($session->impersonated_user_id)->toBe($target->id);
    expect($session->ended_at)->toBeNull();
    // TTL authority: ~30 minutes out.
    expect((int) round(abs($session->started_at->diffInMinutes($session->expires_at))))->toBe(30);

    $log = AuditLog::query()->where('action', AuditAction::AdminImpersonationStarted->value)->firstOrFail();
    expect($log->reason)->toBe('Investigating a reported checkout bug.');
    expect($log->actor_id)->toBe($admin->id);
    expect($log->subject_id)->toBe($target->id);
});

it('returns the MAIN SPA origin as the hand-off URL (not the framework default)', function (): void {
    config(['app.frontend_main_url' => 'http://127.0.0.1:5173']);

    $admin = makeImpersonationAdmin();
    $target = makeImpersonationTarget();

    $response = $this->actingAs($admin, 'web_admin')->postJson('/api/v1/admin/impersonate', [
        'user_ulid' => $target->ulid,
        'reason' => 'Investigating a reported checkout bug.',
    ]);

    $response->assertCreated();
    // The hand-off must point at the configured main SPA origin — a
    // regression here (the Laravel framework default http://localhost:3000)
    // sends the admin to a dead port and the claim never lands.
    expect($response->json('data.attributes.main_spa_url'))->toBe('http://127.0.0.1:5173');
});

it('refuses to impersonate a platform admin (no escalation)', function (): void {
    $admin = makeImpersonationAdmin();
    $otherAdmin = makeImpersonationAdmin();

    $response = $this->actingAs($admin, 'web_admin')->postJson('/api/v1/admin/impersonate', [
        'user_ulid' => $otherAdmin->ulid,
        'reason' => 'Trying to impersonate another admin.',
    ]);

    expect($response->status())->toBe(422);
    expect($response->json('errors.0.code'))->toBe('admin.impersonation.target_admin');
    expect(ImpersonationSession::query()->count())->toBe(0);
});

it('refuses self-impersonation', function (): void {
    $admin = makeImpersonationAdmin();

    $response = $this->actingAs($admin, 'web_admin')->postJson('/api/v1/admin/impersonate', [
        'user_ulid' => $admin->ulid,
        'reason' => 'Trying to impersonate myself somehow.',
    ]);

    expect($response->status())->toBe(422);
    expect($response->json('errors.0.code'))->toBe('admin.impersonation.target_self');
});

it('422s a start with no reason (the verb requiresReason)', function (): void {
    $admin = makeImpersonationAdmin();
    $target = makeImpersonationTarget();

    $response = $this->actingAs($admin, 'web_admin')->postJson('/api/v1/admin/impersonate', [
        'user_ulid' => $target->ulid,
    ]);

    expect($response->status())->toBe(422);
    expect(ImpersonationSession::query()->count())->toBe(0);
});

it('claims the hand-off: logs the impersonated user into the web guard, admin session intact (dual-session)', function (): void {
    $admin = makeImpersonationAdmin();
    $target = makeImpersonationTarget();

    $start = $this->actingAs($admin, 'web_admin')->postJson('/api/v1/admin/impersonate', [
        'user_ulid' => $target->ulid,
        'reason' => 'Investigating a reported checkout bug.',
    ]);
    $token = $start->json('data.attributes.handoff_token');

    $claim = $this->postJson('/api/v1/auth/impersonation/claim', ['token' => $token]);

    expect($claim->status())->toBe(200);
    expect($claim->json('data.attributes.impersonated'))->toBeTrue();
    expect($claim->json('data.id'))->toBe($target->ulid);

    // Dual-session: the impersonated user is on the `web` guard, while the
    // admin remains authenticated on `web_admin` — the admin session is NOT
    // destroyed by the hand-off.
    $this->assertAuthenticated('web');
    $this->assertAuthenticated('web_admin');
    expect(auth()->guard('web')->id())->toBe($target->id);
});

it('burns the hand-off token after a single use', function (): void {
    $admin = makeImpersonationAdmin();
    $target = makeImpersonationTarget();

    $start = $this->actingAs($admin, 'web_admin')->postJson('/api/v1/admin/impersonate', [
        'user_ulid' => $target->ulid,
        'reason' => 'Investigating a reported checkout bug.',
    ]);
    $token = $start->json('data.attributes.handoff_token');

    expect($this->postJson('/api/v1/auth/impersonation/claim', ['token' => $token])->status())->toBe(200);

    $replay = $this->postJson('/api/v1/auth/impersonation/claim', ['token' => $token]);
    expect($replay->status())->toBe(403);
    expect($replay->json('errors.0.code'))->toBe('admin.impersonation.invalid_handoff');
});

it('ends the active impersonation from the admin side and audits it', function (): void {
    $admin = makeImpersonationAdmin();
    $target = makeImpersonationTarget();

    $this->actingAs($admin, 'web_admin')->postJson('/api/v1/admin/impersonate', [
        'user_ulid' => $target->ulid,
        'reason' => 'Investigating a reported checkout bug.',
    ]);

    $end = $this->actingAs($admin, 'web_admin')->postJson('/api/v1/admin/impersonate/end');

    expect($end->status())->toBe(200);
    expect($end->json('data.ended'))->toBeTrue();

    $session = ImpersonationSession::query()->firstOrFail();
    expect($session->ended_at)->not->toBeNull();

    expect(AuditLog::query()->where('action', AuditAction::AdminImpersonationEnded->value)->exists())
        ->toBeTrue();
});

it('refuses to nest: a second start while one is active is rejected', function (): void {
    $admin = makeImpersonationAdmin();
    $first = makeImpersonationTarget();
    $second = makeImpersonationTarget();

    $this->actingAs($admin, 'web_admin')->postJson('/api/v1/admin/impersonate', [
        'user_ulid' => $first->ulid,
        'reason' => 'Investigating the first user.',
    ])->assertCreated();

    $response = $this->actingAs($admin, 'web_admin')->postJson('/api/v1/admin/impersonate', [
        'user_ulid' => $second->ulid,
        'reason' => 'Trying to nest a second impersonation.',
    ]);

    expect($response->status())->toBe(409);
    expect($response->json('errors.0.code'))->toBe('admin.impersonation.already_active');
    // Only the first session exists.
    expect(ImpersonationSession::query()->count())->toBe(1);
});

it('allows a fresh start once the prior impersonation is ended', function (): void {
    $admin = makeImpersonationAdmin();
    $first = makeImpersonationTarget();
    $second = makeImpersonationTarget();

    $this->actingAs($admin, 'web_admin')->postJson('/api/v1/admin/impersonate', [
        'user_ulid' => $first->ulid,
        'reason' => 'Investigating the first user.',
    ])->assertCreated();

    $this->actingAs($admin, 'web_admin')->postJson('/api/v1/admin/impersonate/end')->assertOk();

    $this->actingAs($admin, 'web_admin')->postJson('/api/v1/admin/impersonate', [
        'user_ulid' => $second->ulid,
        'reason' => 'Now investigating the second user.',
    ])->assertCreated();

    expect(ImpersonationSession::query()->count())->toBe(2);
});

it('exposes the TTL constant as 30 minutes (Q2)', function (): void {
    expect(ImpersonationService::TTL_MINUTES)->toBe(30);
});
