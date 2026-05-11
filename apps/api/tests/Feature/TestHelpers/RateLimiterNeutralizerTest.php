<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\TestHelpers\Http\Middleware\VerifyTestHelperToken;
use App\TestHelpers\Services\RateLimiterNeutralizer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Rate-limiter neutralizer test-helper (chunk 7.1 spec #20 prerequisite)
|--------------------------------------------------------------------------
|
| Three concentric coverage rings:
|   1. Endpoint contract — POST sets the cache list, DELETE removes,
|      bad name 422, gating-closed 404. Cache state asserted directly
|      via the service rather than via a re-read endpoint, so a
|      regression in the controller cannot silently agree with itself.
|   2. Provider-side reapply — the chunk-5 LoginTest::beforeEach
|      pattern (RateLimiter::for(name, fn => Limit::none())) is exactly
|      what TestHelpersServiceProvider::boot() now does on every
|      request when the cache list is non-empty. We exercise the
|      override at the in-process level.
|   3. End-to-end composition — neutralise the named limiter, then
|      issue 5 wrong-password attempts. Without neutralisation the 5th
|      returns 429 (throttle preempts at the same threshold). With
|      neutralisation the lockout layer fires at the 5th attempt with
|      HTTP 423 + auth.account_locked.temporary — exactly what spec
|      #20 asserts via the SPA.
*/

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withHeader(VerifyTestHelperToken::HEADER, (string) config('test_helpers.token'));
    // Fresh slate every test; the neutraliser is process-global state
    // (cache-backed) and must not bleed between test cases.
    app(RateLimiterNeutralizer::class)->clear();
});

afterEach(function (): void {
    app(RateLimiterNeutralizer::class)->clear();
});

// -----------------------------------------------------------------------------
// Endpoint contract — POST
// -----------------------------------------------------------------------------

it('POST adds the limiter name to the cache-backed neutralised list', function (): void {
    $this->postJson('/api/v1/_test/rate-limiter/auth-login-email')
        ->assertOk()
        ->assertJsonPath('data.name', 'auth-login-email')
        ->assertJsonPath('data.neutralized', ['auth-login-email']);

    expect(app(RateLimiterNeutralizer::class)->isNeutralized('auth-login-email'))->toBeTrue();
});

it('POST is idempotent — re-posting the same name does not duplicate it', function (): void {
    $this->postJson('/api/v1/_test/rate-limiter/auth-login-email')->assertOk();
    $this->postJson('/api/v1/_test/rate-limiter/auth-login-email')->assertOk();

    expect(app(RateLimiterNeutralizer::class)->list())->toBe(['auth-login-email']);
});

it('POST accepts every name in the allowlist and accumulates them', function (): void {
    foreach (RateLimiterNeutralizer::ALLOWED_NAMES as $name) {
        $this->postJson("/api/v1/_test/rate-limiter/{$name}")->assertOk();
    }

    expect(app(RateLimiterNeutralizer::class)->list())
        ->toBe(RateLimiterNeutralizer::ALLOWED_NAMES);
});

it('POST returns 422 for an unknown limiter name (typo defence)', function (): void {
    $response = $this->postJson('/api/v1/_test/rate-limiter/auth-typo');

    $response->assertStatus(422);
    expect($response->json('error'))->toContain('unknown limiter name');
    expect(app(RateLimiterNeutralizer::class)->list())->toBe([]);
});

// -----------------------------------------------------------------------------
// Endpoint contract — DELETE
// -----------------------------------------------------------------------------

it('DELETE removes the named limiter from the neutralised list', function (): void {
    app(RateLimiterNeutralizer::class)->neutralize('auth-login-email');

    $this->deleteJson('/api/v1/_test/rate-limiter/auth-login-email')
        ->assertOk()
        ->assertJsonPath('data.name', 'auth-login-email')
        ->assertJsonPath('data.neutralized', []);

    expect(app(RateLimiterNeutralizer::class)->isNeutralized('auth-login-email'))->toBeFalse();
});

it('DELETE on a name that was never neutralised is a no-op (idempotent)', function (): void {
    $this->deleteJson('/api/v1/_test/rate-limiter/auth-login-email')->assertOk();

    expect(app(RateLimiterNeutralizer::class)->list())->toBe([]);
});

it('DELETE returns 422 for an unknown limiter name', function (): void {
    $this->deleteJson('/api/v1/_test/rate-limiter/auth-typo')->assertStatus(422);
});

it('DELETE leaves other neutralised names intact', function (): void {
    app(RateLimiterNeutralizer::class)->neutralize('auth-login-email');
    app(RateLimiterNeutralizer::class)->neutralize('auth-password');

    $this->deleteJson('/api/v1/_test/rate-limiter/auth-login-email')->assertOk();

    expect(app(RateLimiterNeutralizer::class)->list())->toBe(['auth-password']);
});

// -----------------------------------------------------------------------------
// Gating contract — env layer only
// -----------------------------------------------------------------------------
//
// Header-missing / wrong-token cases for the _test/* surface are
// exercised once centrally in `GatingTest.php` against a
// representative endpoint. Adding the same cases per-endpoint is
// over-coverage and trips on the PHPUnit `defaultHeaders` merge
// semantics (the file's `beforeEach` sets the header; `withHeaders([])`
// would not clear it). The env-layer case below is per-endpoint
// because it exercises a different branch (provider-level
// `gateOpen()` vs middleware-level token check).

it('returns 404 when env is production even with a correct token (POST)', function (): void {
    config()->set('app.env', 'production');

    $this->withHeader(VerifyTestHelperToken::HEADER, (string) config('test_helpers.token'))
        ->postJson('/api/v1/_test/rate-limiter/auth-login-email')
        ->assertStatus(404);
});

it('returns 404 when env is production even with a correct token (DELETE)', function (): void {
    config()->set('app.env', 'production');

    $this->withHeader(VerifyTestHelperToken::HEADER, (string) config('test_helpers.token'))
        ->deleteJson('/api/v1/_test/rate-limiter/auth-login-email')
        ->assertStatus(404);
});

// -----------------------------------------------------------------------------
// In-process re-registration — POST overrides the registry immediately
// -----------------------------------------------------------------------------

it('POST overrides the named limiter in the current process so subsequent requests in the same test see Limit::none()', function (): void {
    $this->postJson('/api/v1/_test/rate-limiter/auth-login-email')->assertOk();

    // Resolve the limiter callback from the registry the same way the
    // ThrottleRequests middleware does, and assert it returns
    // Limit::none(). We construct a minimal Request so the closure has
    // an `email` field to read (the production callback keys on it).
    $request = Request::create('/api/v1/auth/login', 'POST', ['email' => 'a@b.c']);
    $callback = RateLimiter::limiter('auth-login-email');
    expect($callback)->not->toBeNull();
    /** @var Closure $callback */
    $limit = $callback($request);

    expect($limit)->toBeInstanceOf(Limit::class);
    expect($limit->maxAttempts)->toBe(PHP_INT_MAX);
});

// -----------------------------------------------------------------------------
// End-to-end composition — neutralise, then exercise the lockout layer
// -----------------------------------------------------------------------------

it('with auth-login-email neutralised, the 5th wrong-password attempt is the lockout (not the throttle)', function (): void {
    // Sequence verified against `LoginTest::it('temporarily locks on
    // the 5th failed attempt within 15 minutes')`: the FIRST four
    // wrong-password attempts return 401; the FIFTH itself triggers
    // the lockout (FailedLoginTracker crosses SHORT_WINDOW_THRESHOLD
    // = 5 inside `recordFailureAndMaybeLock`, then AuthService
    // re-checks `isTemporarilyLocked` after the failed-password
    // branch and answers 423). Without neutralisation that fifth
    // attempt would have returned 429 + `rate_limit.exceeded` from
    // the route-level throttle (preempts the application-level
    // lockout at the same threshold). The composition this test
    // pins is the chunk-7.1 design choice: option (i) over option
    // (ii) for spec #20 — see the chunk-7.1 review.
    User::factory()->createOne(['email' => 'lockcomp@example.com']);

    $this->postJson('/api/v1/_test/rate-limiter/auth-login-email')->assertOk();

    for ($i = 0; $i < 4; $i++) {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'lockcomp@example.com',
            'password' => 'wrong-password-9999',
        ])->assertStatus(401);
    }

    // 5th attempt — application lockout fires.
    $this->postJson('/api/v1/auth/login', [
        'email' => 'lockcomp@example.com',
        'password' => 'wrong-password-9999',
    ])->assertStatus(423)
        ->assertJsonPath('errors.0.code', 'auth.account_locked.temporary');
});

// -----------------------------------------------------------------------------
// Defensive: a corrupted cache value cannot blow up the apply-loop
// -----------------------------------------------------------------------------

it('list() drops corrupted cache entries (non-string and unknown names)', function (): void {
    app('cache')->forever(RateLimiterNeutralizer::CACHE_KEY, [
        'auth-login-email',
        42,
        ['nested'],
        'auth-not-a-real-limiter',
        'auth-password',
    ]);

    expect(app(RateLimiterNeutralizer::class)->list())
        ->toBe(['auth-login-email', 'auth-password']);
});
