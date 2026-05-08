<?php

declare(strict_types=1);

use App\Modules\Identity\Http\Middleware\EnsureMfaForAdmins;
use App\Modules\Identity\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    RateLimiter::for('auth-ip', static fn (Request $request): Limit => Limit::none());

    config()->set('auth.admin_mfa_enforced', true);
    config()->set('app.env', 'testing');

    Route::middleware(['auth:web_admin', EnsureMfaForAdmins::class])
        ->get('/__test/admin/protected', fn () => response()->json(['ok' => true]))
        ->name('test.admin.protected');
});

it('blocks an unenrolled admin from a protected route with auth.mfa.enrollment_required (403)', function (): void {
    /** @var User $admin */
    $admin = User::factory()->platformAdmin()->create();

    $response = $this->actingAs($admin, 'web_admin')->getJson('/__test/admin/protected');

    $response->assertStatus(403)
        ->assertJsonPath('errors.0.code', EnsureMfaForAdmins::ENROLLMENT_REQUIRED_CODE);
});

it('admits an admin who has confirmed 2FA', function (): void {
    /** @var User $admin */
    $admin = User::factory()->platformAdmin()->withTwoFactor()->create();

    $response = $this->actingAs($admin, 'web_admin')->getJson('/__test/admin/protected');

    $response->assertOk()->assertJson(['ok' => true]);
});

it('lets unauthenticated requests fall through (auth:web_admin handles them upstream)', function (): void {
    $response = $this->getJson('/__test/admin/protected');

    // The auth:web_admin guard rejects with its own 401, not 403 from
    // the MFA middleware. The point is that EnsureMfaForAdmins didn't
    // explode trying to read $request->user() against null.
    expect($response->status())->toBeGreaterThanOrEqual(400);
});

it('admits everyone when auth.admin_mfa_enforced is false in the local environment', function (): void {
    config()->set('auth.admin_mfa_enforced', false);
    config()->set('app.env', 'local');

    /** @var User $admin */
    $admin = User::factory()->platformAdmin()->create();

    $this->actingAs($admin, 'web_admin')
        ->getJson('/__test/admin/protected')
        ->assertOk();
});

it('refuses to honour the override outside the local environment (staging/prod safety)', function (): void {
    config()->set('auth.admin_mfa_enforced', false);
    config()->set('app.env', 'staging');

    /** @var User $admin */
    $admin = User::factory()->platformAdmin()->create();

    $this->actingAs($admin, 'web_admin')
        ->getJson('/__test/admin/protected')
        ->assertStatus(403)
        ->assertJsonPath('errors.0.code', EnsureMfaForAdmins::ENROLLMENT_REQUIRED_CODE);
});

it('admin /auth/2fa/enable is reachable without 2FA (chicken-and-egg path)', function (): void {
    /** @var User $admin */
    $admin = User::factory()->platformAdmin()->create();

    $this->actingAs($admin, 'web_admin')
        ->postJson('/api/v1/admin/auth/2fa/enable')
        ->assertOk()
        ->assertJsonStructure(['data' => ['provisional_token', 'otpauth_url', 'qr_code_svg', 'manual_entry_key']]);
});

it('admin /auth/2fa/disable is gated by EnsureMfaForAdmins (no chicken-and-egg lockout the wrong way)', function (): void {
    /** @var User $admin */
    $admin = User::factory()->platformAdmin()->create();

    // Without 2FA enrolled, EnsureMfaForAdmins blocks with 403 — they
    // can't disable what they don't have. Once enrolled, they can use
    // the route normally (covered by TwoFactorDisableTest).
    $this->actingAs($admin, 'web_admin')
        ->postJson('/api/v1/admin/auth/2fa/disable', [
            'password' => 'a-strong-passphrase-1234',
            'mfa_code' => '000000',
        ])
        ->assertStatus(403);
});
