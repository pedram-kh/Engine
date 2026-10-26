<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\PwnedPasswordsClientContract;
use App\Modules\Identity\Models\User;
use App\TestHelpers\Services\RateLimiterNeutralizer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| AH-104d S1 — the per-email sign-up limiter (`auth-signup-email`, 5/hour)
|--------------------------------------------------------------------------
|
| 429 only: nothing is created, suspended or written when it fires. It counts
| every attempt (including 422s) on purpose, so a person fixing typos spends
| it; the cost is pinned here and walked on the device (W13). `auth-ip` stays
| in force and is checked by its own spec below.
*/

beforeEach(function (): void {
    // `auth-ip` is neutralised by default so these specs isolate the new limiter.
    RateLimiter::for('auth-ip', static fn (Request $request): Limit => Limit::none());
    app()->bind(PwnedPasswordsClientContract::class, fn () => new class implements PwnedPasswordsClientContract
    {
        public function breachCount(string $plaintextPassword): int
        {
            return 0;
        }
    });
});

/** @return array<string, mixed> */
function srlPayload(string $email, string $password = 'a-strong-passphrase-1234'): array
{
    return [
        'name' => 'Pedro',
        'last_name' => 'Costa',
        'email' => $email,
        'password' => $password,
        'password_confirmation' => $password,
        'preferred_language' => 'en',
    ];
}

/** Resolve the limiter's `Limit` for a request, the way the throttle middleware does. */
function srlLimit(Request $request): Limit
{
    $resolver = RateLimiter::limiter('auth-signup-email');
    if ($resolver === null) {
        throw new RuntimeException('auth-signup-email is not registered');
    }
    $limit = $resolver($request);
    if (! $limit instanceof Limit) {
        throw new RuntimeException('auth-signup-email did not return a single Limit');
    }

    return $limit;
}

/** Spend the five allowed attempts for one address with a weak password (each a 422, each counted). */
function srlSpendFive(mixed $test, string $email): void
{
    for ($i = 1; $i <= 5; $i++) {
        $test->postJson('/api/v1/auth/sign-up', srlPayload($email, 'short'))->assertStatus(422);
    }
}

it('lets five attempts through and answers the sixth with the 429 envelope', function (): void {
    Mail::fake();
    srlSpendFive($this, 'five@example.test');

    $this->postJson('/api/v1/auth/sign-up', srlPayload('five@example.test'))
        ->assertStatus(429)
        ->assertJsonPath('errors.0.code', 'rate_limit.exceeded')
        ->assertHeader('Retry-After')
        ->assertJsonPath('errors.0.status', '429');

    expect($this->postJson('/api/v1/auth/sign-up', srlPayload('five@example.test'))->json('errors.0.meta.seconds'))->toBeInt()->toBeGreaterThan(0);
});

it('is 5 attempts per hour (not per minute, not 4, not 6)', function (): void {
    $limit = srlLimit(Request::create('/api/v1/auth/sign-up', 'POST', ['email' => 'a@b.test']));

    expect($limit)->toBeInstanceOf(Limit::class)
        ->and($limit->maxAttempts)->toBe(5)
        ->and($limit->decaySeconds)->toBe(3600);
});

it('creates nothing, queues no mail and writes no audit or lockout row on the 429', function (): void {
    Mail::fake();
    srlSpendFive($this, 'quiet@example.test');

    $users = DB::table('users')->count();
    $audit = DB::table('audit_logs')->count();

    $this->postJson('/api/v1/auth/sign-up', srlPayload('quiet@example.test'))->assertStatus(429);

    expect(DB::table('users')->count())->toBe($users)
        ->and(DB::table('audit_logs')->count())->toBe($audit)
        ->and(DB::table('users')->where('email', 'quiet@example.test')->exists())->toBeFalse();
    Mail::assertNothingQueued();
    Mail::assertNothingSent();
});

it('does not lock the sixth attempt out of a valid account: the limit is the only thing in the way', function (): void {
    Mail::fake();
    // Five attempts to a DIFFERENT address leave this one fresh and creatable.
    srlSpendFive($this, 'other@example.test');

    $this->postJson('/api/v1/auth/sign-up', srlPayload('fresh@example.test'))->assertStatus(201);
});

it('keys on the email, so another address is unaffected', function (): void {
    Mail::fake();
    srlSpendFive($this, 'one@example.test');

    $this->postJson('/api/v1/auth/sign-up', srlPayload('one@example.test'))->assertStatus(429);
    $this->postJson('/api/v1/auth/sign-up', srlPayload('two@example.test'))->assertStatus(201);
});

it('treats case and surrounding whitespace as the same address', function (): void {
    Mail::fake();
    for ($i = 1; $i <= 5; $i++) {
        $this->postJson('/api/v1/auth/sign-up', srlPayload($i % 2 === 0 ? '  CASE@Example.TEST ' : 'case@example.test', 'short'))
            ->assertStatus(422);
    }

    $this->postJson('/api/v1/auth/sign-up', srlPayload('Case@EXAMPLE.test'))->assertStatus(429);
});

it('counts a 422 as an attempt (the ruled cost, walked on the device)', function (): void {
    Mail::fake();
    for ($i = 1; $i <= 5; $i++) {
        $this->postJson('/api/v1/auth/sign-up', srlPayload('typo@example.test', 'short'))->assertStatus(422);
    }

    // The very next, now-valid, attempt is refused.
    $this->postJson('/api/v1/auth/sign-up', srlPayload('typo@example.test'))->assertStatus(429);
    expect(DB::table('users')->where('email', 'typo@example.test')->exists())->toBeFalse();
});

it('answers a taken address and a free one identically once the limit is hit', function (): void {
    Mail::fake();
    User::factory()->creator()->createOne(['email' => 'taken@example.test']);

    srlSpendFive($this, 'free@example.test');
    for ($i = 1; $i <= 5; $i++) {
        $this->postJson('/api/v1/auth/sign-up', srlPayload('taken@example.test'))->assertStatus(422);
    }

    $normalise = static fn (mixed $r): string => (string) preg_replace('/"(request_id|trace_id)":"[^"]*"/', '"$1":"*"', (string) $r->getContent());
    $free = $this->postJson('/api/v1/auth/sign-up', srlPayload('free@example.test'));
    $taken = $this->postJson('/api/v1/auth/sign-up', srlPayload('taken@example.test'));

    expect($free->getStatusCode())->toBe(429)->and($taken->getStatusCode())->toBe(429)
        ->and($normalise($taken))->toBe($normalise($free));
});

it('applies on the invitation path too (same endpoint, same limit)', function (): void {
    Mail::fake();
    for ($i = 1; $i <= 5; $i++) {
        $this->postJson('/api/v1/auth/sign-up', srlPayload('invitee@example.test', 'short') + ['invitation_token' => 'not-a-real-token']);
    }

    $this->postJson('/api/v1/auth/sign-up', srlPayload('invitee@example.test') + ['invitation_token' => 'not-a-real-token'])
        ->assertStatus(429)
        ->assertJsonPath('errors.0.code', 'rate_limit.exceeded');
});

it('never shares one bucket between everyone for an empty or non-string email', function (): void {
    $resolve = static fn (array $input, string $ip): Limit => srlLimit(
        Request::create('/api/v1/auth/sign-up', 'POST', $input, [], [], ['REMOTE_ADDR' => $ip]),
    );

    $emptyA = $resolve([], '10.0.0.1');
    $emptyB = $resolve(['email' => ''], '10.0.0.2');
    $arrayA = $resolve(['email' => ['x']], '10.0.0.1');
    $whitespace = $resolve(['email' => '   '], '10.0.0.1');

    // Per-IP, never one global empty key.
    expect($emptyA->key)->toBe('signup-ip:10.0.0.1')
        ->and($emptyB->key)->toBe('signup-ip:10.0.0.2')
        ->and($emptyA->key)->not->toBe($emptyB->key)
        ->and($arrayA->key)->toBe($emptyA->key)
        ->and($whitespace->key)->toBe($emptyA->key);

    // And it must not throw on an array email (the request would 500 instead of 422).
    $this->postJson('/api/v1/auth/sign-up', ['email' => ['x']] + srlPayload('x@example.test'))->assertStatus(422);
});

it('keys on a hash of the normalised email and never holds the address itself', function (): void {
    $limit = srlLimit(
        Request::create('/api/v1/auth/sign-up', 'POST', ['email' => '  Person@Example.TEST ']),
    );

    expect($limit->key)->toBe('signup:'.hash('sha256', 'person@example.test'))
        ->and($limit->key)->not->toContain('person')
        ->and($limit->key)->not->toContain('example');
});

it('leaves the per-IP cap in force on top of it', function (): void {
    Mail::fake();
    // Re-arm the per-IP cap (same numbers as production) for this spec; only the status is asserted.
    RateLimiter::for('auth-ip', static fn (Request $request): Limit => Limit::perMinute(10)->by((string) $request->ip()));

    for ($i = 1; $i <= 10; $i++) {
        $this->postJson('/api/v1/auth/sign-up', srlPayload("ip{$i}@example.test"))->assertStatus(201);
    }

    // A brand-new address, but the 11th request in the minute from this IP.
    $this->postJson('/api/v1/auth/sign-up', srlPayload('ip11@example.test'))->assertStatus(429);
    expect(DB::table('users')->where('email', 'ip11@example.test')->exists())->toBeFalse();
});

it('registers the limiter, mounts it on the sign-up route and lets end-to-end specs neutralise it', function (): void {
    $route = app('router')->getRoutes()->getByName('auth.signup');
    $middleware = $route?->gatherMiddleware() ?? [];

    expect(RateLimiter::limiter('auth-signup-email'))->not->toBeNull()
        ->and(RateLimiterNeutralizer::ALLOWED_NAMES)->toContain('auth-signup-email')
        ->and($middleware)->toContain('throttle:auth-signup-email')
        ->and($middleware)->toContain('throttle:auth-ip');
});

it('does not touch the other auth endpoints', function (): void {
    foreach (['auth.login', 'auth.token', 'auth.password.forgot', 'auth.email.verify', 'auth.email.resend'] as $name) {
        $middleware = app('router')->getRoutes()->getByName($name)?->gatherMiddleware() ?? [];
        expect($middleware)->not->toContain('throttle:auth-signup-email');
    }
});
