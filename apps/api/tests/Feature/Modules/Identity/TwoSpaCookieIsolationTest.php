<?php

declare(strict_types=1);

use App\Modules\Identity\Http\Middleware\UseAdminSessionCookie;
use App\Modules\Identity\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    RateLimiter::for('auth-ip', static fn (Request $request): Limit => Limit::none());
    RateLimiter::for('auth-login-email', static fn (Request $request): Limit => Limit::none());
});

it('main SPA login leaves config(session.cookie) at catalyst_main_session', function (): void {
    User::factory()->createOne(['email' => 'main@example.com']);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'main@example.com',
        'password' => 'password-12chars',
    ])->assertOk();

    expect(config('session.cookie'))->toBe('catalyst_main_session');
});

it('admin SPA login uses the catalyst_admin_session cookie name', function (): void {
    // The session-cookie name flip happens BEFORE Sanctum's stateful
    // middleware injects StartSession, so by the time the request reaches
    // the controller, config('session.cookie') is already the admin name.
    // We verify that contract on a request that goes all the way through
    // the admin login pipeline. (We assert on config rather than the
    // emitted cookie because SESSION_DRIVER=array in tests does not
    // round-trip a Set-Cookie header — the named-cookie behavior is
    // covered structurally below.)
    $admin = User::factory()->platformAdmin()->createOne(['email' => 'admin@example.com']);
    $admin->forceFill(['mfa_required' => false])->saveQuietly();

    $this->withMiddleware()->postJson('/api/v1/admin/auth/login', [
        'email' => 'admin@example.com',
        'password' => 'password-12chars',
    ])->assertOk();

    expect(config('session.cookie'))->toBe(UseAdminSessionCookie::COOKIE);
});

it('UseAdminSessionCookie::shouldApply matches admin paths only', function (): void {
    $admin = Request::create('/api/v1/admin/auth/login', 'POST');
    $main = Request::create('/api/v1/auth/login', 'POST');
    $other = Request::create('/api/v1/health', 'GET');

    expect(UseAdminSessionCookie::shouldApply($admin))->toBeTrue()
        ->and(UseAdminSessionCookie::shouldApply($main))->toBeFalse()
        ->and(UseAdminSessionCookie::shouldApply($other))->toBeFalse();
});

it('overwrites config(session.cookie) on admin paths only', function (): void {
    $middleware = app(UseAdminSessionCookie::class);
    $next = fn (): Response => new Response;

    config()->set('session.cookie', 'catalyst_main_session');

    $middleware->handle(Request::create('/api/v1/health', 'GET'), $next);
    expect(config('session.cookie'))->toBe('catalyst_main_session');

    $middleware->handle(Request::create('/api/v1/admin/auth/login', 'POST'), $next);
    expect(config('session.cookie'))->toBe(UseAdminSessionCookie::COOKIE);
});

it('shouldApply fires on /sanctum/csrf-cookie when Origin matches the admin SPA URL', function (): void {
    // Reproduces the chunk-6 admin-login fix. Before the widening,
    // `/sanctum/csrf-cookie` always ran under the main session, so the
    // CSRF token issued for an admin-SPA preflight ended up in the wrong
    // session and the follow-up `/api/v1/admin/auth/login` POST 419'd.
    config()->set('app.frontend_admin_url', 'http://127.0.0.1:5174');

    $request = Request::create('/sanctum/csrf-cookie', 'GET');
    $request->headers->set('Origin', 'http://127.0.0.1:5174');

    expect(UseAdminSessionCookie::shouldApply($request))->toBeTrue();
});

it('shouldApply does NOT fire on /sanctum/csrf-cookie from the main SPA Origin', function (): void {
    config()->set('app.frontend_admin_url', 'http://127.0.0.1:5174');

    $request = Request::create('/sanctum/csrf-cookie', 'GET');
    $request->headers->set('Origin', 'http://127.0.0.1:5173');

    expect(UseAdminSessionCookie::shouldApply($request))->toBeFalse();
});

it('shouldApply does NOT fire on /sanctum/csrf-cookie with no Origin and no Referer', function (): void {
    // A bare same-origin preflight without any browser-issued Origin
    // header (e.g. server-side health probes, curl without -e) must
    // continue to use the main session so we don't accidentally flip
    // unrelated traffic onto the admin cookie.
    config()->set('app.frontend_admin_url', 'http://127.0.0.1:5174');

    $request = Request::create('/sanctum/csrf-cookie', 'GET');

    expect(UseAdminSessionCookie::shouldApply($request))->toBeFalse();
});

it('shouldApply falls back to Referer when Origin is absent on the CSRF preflight', function (): void {
    // Some browsers omit Origin on simple GETs but still send Referer.
    // The middleware must accept that fallback so the admin SPA's
    // preflight is still detected.
    config()->set('app.frontend_admin_url', 'http://127.0.0.1:5174');

    $request = Request::create('/sanctum/csrf-cookie', 'GET');
    $request->headers->set('Referer', 'http://127.0.0.1:5174/login');

    expect(UseAdminSessionCookie::shouldApply($request))->toBeTrue();
});

it('shouldApply ignores trailing slash differences between Origin and configured admin URL', function (): void {
    config()->set('app.frontend_admin_url', 'http://127.0.0.1:5174/');

    $request = Request::create('/sanctum/csrf-cookie', 'GET');
    $request->headers->set('Origin', 'http://127.0.0.1:5174');

    expect(UseAdminSessionCookie::shouldApply($request))->toBeTrue();
});

it('shouldApply only widens for the csrf-cookie path, not arbitrary top-level paths from the admin origin', function (): void {
    // The widening is keyed on the specific Sanctum preflight path so we
    // don't accidentally treat unrelated top-level traffic (e.g. health
    // checks, OAuth callbacks) as admin-session-bound just because the
    // browser happens to be on the admin SPA.
    config()->set('app.frontend_admin_url', 'http://127.0.0.1:5174');

    $request = Request::create('/api/v1/health', 'GET');
    $request->headers->set('Origin', 'http://127.0.0.1:5174');

    expect(UseAdminSessionCookie::shouldApply($request))->toBeFalse();
});
