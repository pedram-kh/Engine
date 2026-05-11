<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('limits the login endpoint to 5 attempts per minute per email', function (): void {
    User::factory()->createOne(['email' => 'rl@example.com']);

    for ($i = 1; $i <= 5; $i++) {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'rl@example.com',
            'password' => 'wrong-password-9999',
        ]);
    }

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'rl@example.com',
        'password' => 'wrong-password-9999',
    ]);

    $response->assertStatus(429)
        ->assertJsonPath('errors.0.code', 'rate_limit.exceeded');
});

it('limits the forgot-password endpoint to 5 requests per minute per IP', function (): void {
    User::factory()->createOne(['email' => 'rl-pw@example.com']);

    for ($i = 1; $i <= 5; $i++) {
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'rl-pw@example.com']);
    }

    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'rl-pw@example.com'])
        ->assertStatus(429)
        ->assertJsonPath('errors.0.code', 'rate_limit.exceeded');
});

it('emits a Retry-After header on 429 responses', function (): void {
    User::factory()->createOne(['email' => 'retry@example.com']);

    for ($i = 1; $i <= 6; $i++) {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'retry@example.com',
            'password' => 'wrong-password-9999',
        ]);
        if ($response->getStatusCode() === 429) {
            expect($response->headers->has('Retry-After'))->toBeTrue();

            return;
        }
    }
    $this->fail('expected at least one 429 response within 6 attempts');
});

// -----------------------------------------------------------------------------
// chunk 7.1 — `errors.0.meta.seconds` envelope per named limiter
// -----------------------------------------------------------------------------
//
// All four named limiters in IdentityServiceProvider::registerRateLimits()
// share a single `$rateLimitResponse` closure, so the contract is
// structurally enforced at the source. We still exercise each limiter
// from its own trigger endpoint, so a future refactor that re-inlines
// responses and forgets `meta.seconds` for ONE limiter cannot land
// without tripping CI. The SPA's `useErrorMessage` resolver pulls
// interpolation values exclusively from `details[0].meta`; without
// `meta.seconds` the bundled "Too many requests. Please try again in
// {seconds} seconds." string would render with the literal placeholder.

/**
 * Asserts the chunk-7.1 envelope on a 429 response: integer
 * `meta.seconds` in `[0, 60]` (per-minute limiter Retry-After ceiling)
 * and the `rate_limit.exceeded` code. Shared by the four cases below.
 *
 * @param  TestResponse<Response>  $response
 */
function assertRateLimitMetaSecondsShape(TestResponse $response): void
{
    $response->assertStatus(429)
        ->assertJsonPath('errors.0.code', 'rate_limit.exceeded');

    /** @var int $seconds */
    $seconds = $response->json('errors.0.meta.seconds');

    expect($seconds)->toBeInt()
        ->and($seconds)->toBeGreaterThanOrEqual(0)
        ->and($seconds)->toBeLessThanOrEqual(60);
}

it('emits errors.0.meta.seconds on the auth-login-email 429 (per-email login throttle)', function (): void {
    User::factory()->createOne(['email' => 'meta-login@example.com']);

    for ($i = 1; $i <= 6; $i++) {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'meta-login@example.com',
            'password' => 'wrong-password-9999',
        ]);
        if ($response->getStatusCode() === 429) {
            assertRateLimitMetaSecondsShape($response);

            return;
        }
    }
    $this->fail('expected at least one 429 response within 6 attempts');
});

it('emits errors.0.meta.seconds on the auth-ip 429 (per-IP umbrella throttle on the auth surface)', function (): void {
    // Vary the email each attempt so the per-email limiter
    // (auth-login-email, 5/min/email) never trips. The auth-ip
    // limiter is 10/min/IP applied to every unauthenticated auth
    // endpoint; attempt 11 from the same IP trips it.
    for ($i = 1; $i <= 12; $i++) {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'meta-ip-'.$i.'@example.com',
            'password' => 'wrong-password-9999',
        ]);
        if ($response->getStatusCode() === 429) {
            assertRateLimitMetaSecondsShape($response);

            return;
        }
    }
    $this->fail('expected at least one 429 response within 12 attempts');
});

it('emits errors.0.meta.seconds on the auth-password 429 (per-IP forgot-password throttle)', function (): void {
    User::factory()->createOne(['email' => 'meta-pw@example.com']);

    for ($i = 1; $i <= 6; $i++) {
        $response = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'meta-pw@example.com',
        ]);
        if ($response->getStatusCode() === 429) {
            assertRateLimitMetaSecondsShape($response);

            return;
        }
    }
    $this->fail('expected at least one 429 response within 6 attempts');
});

it('emits errors.0.meta.seconds on the auth-resend-verification 429 (1/min/email throttle)', function (): void {
    User::factory()->createOne(['email' => 'meta-rv@example.com']);

    // 1/min/email — second attempt within the same minute trips it.
    for ($i = 1; $i <= 3; $i++) {
        $response = $this->postJson('/api/v1/auth/resend-verification', [
            'email' => 'meta-rv@example.com',
        ]);
        if ($response->getStatusCode() === 429) {
            assertRateLimitMetaSecondsShape($response);

            return;
        }
    }
    $this->fail('expected at least one 429 response within 3 attempts');
});
