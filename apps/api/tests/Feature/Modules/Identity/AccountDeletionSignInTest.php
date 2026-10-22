<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Events\LoginFailed;
use App\Modules\Identity\Events\UserLoggedIn;
use App\Modules\Identity\Features\AccountDeletionEnabled;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AccountDeletionService;
use App\Modules\Identity\Services\FailedLoginTracker;
use App\Modules\Identity\Services\TwoFactorService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Pennant\Feature;
use Laravel\Sanctum\PersonalAccessToken;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| AH-104b-del A4 — signing in while a deletion is scheduled
|--------------------------------------------------------------------------
|
| Right password (and second factor) on a pending account: 403
| `auth.deletion_pending`, no session, no token, no counter. The same request
| with `cancel_deletion` restores the account. Every EARLIER failure must be
| byte-for-byte what an ordinary account gets (no state leak before the
| credentials are proven). Both doors share one gate graph, so every case runs
| on both.
*/

const SD_PASSWORD = 'password-12chars';

beforeEach(function (): void {
    RateLimiter::for('auth-ip', static fn (Request $request): Limit => Limit::none());
    RateLimiter::for('auth-login-email', static fn (Request $request): Limit => Limit::none());
    Cache::flush();
    Carbon::setTestNow('2026-11-02 10:00:00');
    Feature::activate(AccountDeletionEnabled::NAME);
    config()->set('app.frontend_main_url', 'http://127.0.0.1:5173');
    config()->set('app.frontend_admin_url', 'http://127.0.0.1:5174');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * @return array{0: User, 1: ?string, 2: list<string>} [creator, totp secret, plain recovery codes]
 */
function sdCreator(string $email, bool $pending = false, bool $mfa = false): array
{
    $user = User::factory()->creator()->createOne(['email' => $email]);
    Creator::factory()->createOne(['user_id' => $user->id]);
    $secret = null;
    $plain = [];

    if ($mfa) {
        $svc = app(TwoFactorService::class);
        $secret = $svc->generateSecret();
        $plain = $svc->generateRecoveryCodes();
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => array_map(fn (string $c) => $svc->hashRecoveryCode($c), $plain),
            'two_factor_confirmed_at' => now(),
        ])->saveQuietly();
    }

    if ($pending) {
        $user->forceFill([
            'deletion_requested_at' => now()->subDays(3),
            'deletion_scheduled_for' => now()->addDays(11),
        ])->saveQuietly();
    }

    return [$user->refresh(), $secret, $plain];
}

/** Both doors, same body: 'web' (cookie, stateful origin) or 'token' (bearer, capacitor origin). */
function sdSignIn(mixed $test, string $door, array $body, string $path = ''): mixed
{
    app('auth')->forgetGuards();

    if ($door === 'token') {
        return $test->withHeader('Origin', 'capacitor://localhost')->postJson('/api/v1/auth/token', $body + ['platform' => 'ios']);
    }

    return $test->postJson($path !== '' ? $path : '/api/v1/auth/login', $body);
}

function sdTotp(string $secret): string
{
    return app(Google2FA::class)->getCurrentOtp($secret);
}

/** The body of a response with the fields that legitimately differ per request removed. */
function sdEnvelope(mixed $response): array
{
    $json = $response->json();
    array_walk_recursive($json, static function (mixed &$v, string|int $k): void {
        if (in_array($k, ['request_id', 'trace_id', 'id', 'ulid', 'created_at'], true)) {
            $v = '*';
        }
    });

    return ['status' => $response->getStatusCode(), 'body' => $json, 'retry' => $response->headers->get('Retry-After')];
}

function sdTokenCount(): int
{
    return PersonalAccessToken::query()->count();
}

dataset('doors', ['web' => ['web'], 'token' => ['token']]);

// -----------------------------------------------------------------------------
// The pending answer
// -----------------------------------------------------------------------------

it('answers 403 auth.deletion_pending on right credentials, with no session, no token, no login', function (string $door): void {
    [$user] = sdCreator('p@example.test', pending: true);
    Event::fake([UserLoggedIn::class, LoginFailed::class]);

    $response = sdSignIn($this, $door, ['email' => 'p@example.test', 'password' => SD_PASSWORD]);

    $response->assertForbidden()
        ->assertJsonPath('errors.0.code', 'auth.deletion_pending')
        ->assertJsonPath('errors.0.meta.scheduled_for', '2026-11-13T10:00:00+00:00')
        ->assertJsonPath('errors.0.meta.mfa_via', 'none');

    $this->assertGuest('web');
    expect(sdTokenCount())->toBe(0)
        ->and(User::query()->findOrFail($user->id)->isDeletionPending())->toBeTrue()
        ->and(User::query()->findOrFail($user->id)->last_login_at)->toBeNull();
    Event::assertNotDispatched(UserLoggedIn::class);
    Event::assertDispatched(LoginFailed::class, fn (LoginFailed $e): bool => $e->reason === 'deletion_pending');
})->with('doors');

it('writes the pending attempt to the audit trail as a failed login with its own reason', function (): void {
    sdCreator('p@example.test', pending: true);

    sdSignIn($this, 'web', ['email' => 'p@example.test', 'password' => SD_PASSWORD])->assertForbidden();

    $row = AuditLog::query()->where('action', AuditAction::AuthLoginFailed)->sole();
    expect(((array) $row->metadata)['reason'] ?? null)->toBe('deletion_pending');
});

it('never feeds the failure counters or locks the account, however often a pending creator signs in', function (string $door): void {
    sdCreator('p@example.test', pending: true);
    $tracker = app(FailedLoginTracker::class);

    for ($i = 0; $i < 12; $i++) {
        sdSignIn($this, $door, ['email' => 'p@example.test', 'password' => SD_PASSWORD])
            ->assertForbidden()
            ->assertJsonPath('errors.0.code', 'auth.deletion_pending');
    }

    expect($tracker->shortWindowCount('p@example.test'))->toBe(0)
        ->and($tracker->longWindowCount('p@example.test'))->toBe(0)
        ->and(User::query()->where('email', 'p@example.test')->sole()->isSuspended())->toBeFalse();
})->with('doors');

// -----------------------------------------------------------------------------
// Nothing leaks before the credentials are proven: byte-identical envelopes
// -----------------------------------------------------------------------------

it('gives a pending account exactly the ordinary answer to every failure before the credentials are proven', function (string $door, string $case): void {
    $mfa = in_array($case, ['mfa_required', 'wrong_code'], true);
    sdCreator('pending@example.test', pending: true, mfa: $mfa);
    sdCreator('plain@example.test', pending: false, mfa: $mfa);

    $bodyFor = static fn (string $email): array => match ($case) {
        'wrong_password' => ['email' => $email, 'password' => 'not-the-password'],
        'mfa_required' => ['email' => $email, 'password' => SD_PASSWORD],
        'wrong_code' => ['email' => $email, 'password' => SD_PASSWORD, 'mfa_code' => '000000'],
        default => ['email' => $email, 'password' => 'not-the-password', 'cancel_deletion' => true],
    };

    $pending = sdEnvelope(sdSignIn($this, $door, $bodyFor('pending@example.test')));
    $plain = sdEnvelope(sdSignIn($this, $door, $bodyFor('plain@example.test')));

    expect($pending)->toBe($plain);
    expect($pending['status'])->not->toBe(200);
})->with('doors')->with(['wrong_password', 'mfa_required', 'wrong_code', 'with_cancel_flag_wrong_password']);

it('answers a pending account at the wrong SPA exactly as an ordinary creator at that SPA', function (): void {
    sdCreator('pending@example.test', pending: true);
    sdCreator('plain@example.test');

    $pending = sdEnvelope(sdSignIn($this, 'web', ['email' => 'pending@example.test', 'password' => SD_PASSWORD], '/api/v1/admin/auth/login'));
    $plain = sdEnvelope(sdSignIn($this, 'web', ['email' => 'plain@example.test', 'password' => SD_PASSWORD], '/api/v1/admin/auth/login'));

    expect($pending)->toBe($plain)->and($pending['body']['errors'][0]['code'] ?? null)->toBe('auth.wrong_spa');
});

it('lets suspension win over a pending deletion', function (): void {
    [$user] = sdCreator('s@example.test', pending: true);
    $user->forceFill(['is_suspended' => true, 'suspended_reason' => 'x'])->saveQuietly();

    sdSignIn($this, 'web', ['email' => 's@example.test', 'password' => SD_PASSWORD])
        ->assertStatus(423)
        ->assertJsonPath('errors.0.code', 'auth.account_locked.suspended');
});

// -----------------------------------------------------------------------------
// Second factor
// -----------------------------------------------------------------------------

it('asks for the second factor first, and names the pending state only after a right code', function (string $door): void {
    [, $secret] = sdCreator('m@example.test', pending: true, mfa: true);

    sdSignIn($this, $door, ['email' => 'm@example.test', 'password' => SD_PASSWORD])
        ->assertStatus(401)
        ->assertJsonPath('errors.0.code', 'auth.mfa_required');

    sdSignIn($this, $door, ['email' => 'm@example.test', 'password' => SD_PASSWORD, 'mfa_code' => sdTotp((string) $secret)])
        ->assertForbidden()
        ->assertJsonPath('errors.0.code', 'auth.deletion_pending')
        ->assertJsonPath('errors.0.meta.mfa_via', 'totp');
})->with('doors');

it('spends the recovery code on the pending step and says so, so the client can ask for a fresh one (P1)', function (): void {
    [$user, , $codes] = sdCreator('m@example.test', pending: true, mfa: true);

    sdSignIn($this, 'web', ['email' => 'm@example.test', 'password' => SD_PASSWORD, 'mfa_code' => $codes[0]])
        ->assertForbidden()
        ->assertJsonPath('errors.0.meta.mfa_via', 'recovery_code');
    expect(User::query()->findOrFail($user->id)->two_factor_recovery_codes)->toHaveCount(count($codes) - 1);

    // The same code is gone: reusing it on the cancel step fails as any spent code does.
    sdSignIn($this, 'web', ['email' => 'm@example.test', 'password' => SD_PASSWORD, 'mfa_code' => $codes[0], 'cancel_deletion' => true])
        ->assertStatus(401)
        ->assertJsonPath('errors.0.code', 'auth.mfa.invalid_code');
    expect(User::query()->findOrFail($user->id)->isDeletionPending())->toBeTrue();

    // A fresh one restores the account.
    sdSignIn($this, 'web', ['email' => 'm@example.test', 'password' => SD_PASSWORD, 'mfa_code' => $codes[1], 'cancel_deletion' => true])
        ->assertOk();
    expect(User::query()->findOrFail($user->id)->isDeletionPending())->toBeFalse();
});

// -----------------------------------------------------------------------------
// Cancelling
// -----------------------------------------------------------------------------

it('restores the account on right credentials with cancel_deletion, and signs in normally', function (string $door): void {
    [$user] = sdCreator('c@example.test', pending: true);
    $tracker = app(FailedLoginTracker::class);
    $tracker->record('c@example.test'); // a stale failure the successful sign-in clears

    $response = sdSignIn($this, $door, ['email' => 'c@example.test', 'password' => SD_PASSWORD, 'cancel_deletion' => true]);

    $response->assertOk()->assertJsonPath('data.id', $user->ulid);
    $fresh = User::query()->findOrFail($user->id);
    expect($fresh->deletion_requested_at)->toBeNull()
        ->and($fresh->deletion_scheduled_for)->toBeNull()
        ->and($fresh->isDeletionPending())->toBeFalse()
        ->and($fresh->last_login_at)->not->toBeNull()
        ->and($tracker->shortWindowCount('c@example.test'))->toBe(0);

    if ($door === 'web') {
        $this->assertAuthenticated('web');
    } else {
        expect(sdTokenCount())->toBe(1)->and($response->json('meta.token'))->toBeString();
    }
})->with('doors');

it('answers a restore with the user as it is now: the Security feature flag is read at that response, not carried over', function (string $door): void {
    sdCreator('c@example.test', pending: true);
    Feature::activate(AccountDeletionEnabled::NAME);

    sdSignIn($this, $door, ['email' => 'c@example.test', 'password' => SD_PASSWORD, 'cancel_deletion' => true])
        ->assertOk()
        ->assertJsonPath('data.attributes.features.account_deletion', true);
})->with('doors');

it('audits the cancellation once with the date it replaced', function (): void {
    [$user] = sdCreator('c@example.test', pending: true);

    sdSignIn($this, 'web', ['email' => 'c@example.test', 'password' => SD_PASSWORD, 'cancel_deletion' => true])->assertOk();

    $rows = AuditLog::query()->where('action', AuditAction::AccountDeletionCancelled)->get();
    expect($rows)->toHaveCount(1)
        ->and($rows->sole()->subject_id)->toBe($user->id)
        ->and(((array) $rows->sole()->metadata)['was_scheduled_for'] ?? null)->toBe('2026-11-13T10:00:00+00:00');
});

it('does not cancel on wrong credentials, a missing code or a wrong code', function (string $door): void {
    [$user] = sdCreator('c@example.test', pending: true, mfa: true);
    $attempts = [
        ['password' => 'not-the-password'],
        ['password' => SD_PASSWORD],
        ['password' => SD_PASSWORD, 'mfa_code' => '000000'],
    ];

    foreach ($attempts as $extra) {
        sdSignIn($this, $door, ['email' => 'c@example.test', 'cancel_deletion' => true] + $extra)->assertStatus(401);
    }

    expect(User::query()->findOrFail($user->id)->isDeletionPending())->toBeTrue()
        ->and(sdTokenCount())->toBe(0)
        ->and(AuditLog::query()->where('action', AuditAction::AccountDeletionCancelled)->count())->toBe(0);
})->with('doors');

it('ignores cancel_deletion on an ordinary account: same success, nothing audited', function (string $door): void {
    sdCreator('plain-a@example.test');
    sdCreator('plain-b@example.test');

    $with = sdSignIn($this, $door, ['email' => 'plain-a@example.test', 'password' => SD_PASSWORD, 'cancel_deletion' => true]);
    app('auth')->forgetGuards();
    $without = sdSignIn($this, $door, ['email' => 'plain-b@example.test', 'password' => SD_PASSWORD]);

    $with->assertOk();
    $without->assertOk();
    expect(array_keys($with->json('data.attributes')))->toBe(array_keys($without->json('data.attributes')))
        ->and(AuditLog::query()->where('action', AuditAction::AccountDeletionCancelled)->count())->toBe(0);
})->with('doors');

it('rejects a cancel_deletion that is not a boolean', function (): void {
    sdCreator('c@example.test', pending: true);

    sdSignIn($this, 'web', ['email' => 'c@example.test', 'password' => SD_PASSWORD, 'cancel_deletion' => 'banana'])->assertStatus(422);
});

it('lets a restored creator ask again later and gets a fresh 14 days', function (): void {
    [$user] = sdCreator('again@example.test', pending: true);
    sdSignIn($this, 'web', ['email' => 'again@example.test', 'password' => SD_PASSWORD, 'cancel_deletion' => true])->assertOk();
    Carbon::setTestNow('2026-12-01 08:00:00');

    $result = app(AccountDeletionService::class)->request(User::query()->findOrFail($user->id), SD_PASSWORD, null, Request::create('/x', 'POST'));

    expect($result->scheduledFor?->toIso8601String())->toBe('2026-12-15T08:00:00+00:00');
});

it('cancel() on an erased row restores nothing and says so; on a clean row it is a quiet yes', function (): void {
    [$erased] = sdCreator('e@example.test', pending: true);
    $erased->forceFill(['erased_at' => now()])->saveQuietly();
    [$clean] = sdCreator('k@example.test');
    $service = app(AccountDeletionService::class);

    expect($service->cancel($erased, null, null))->toBeFalse()
        ->and(User::query()->findOrFail($erased->id)->deletion_requested_at)->not->toBeNull()
        ->and($service->cancel($clean, null, null))->toBeTrue()
        ->and(AuditLog::query()->where('action', AuditAction::AccountDeletionCancelled)->count())->toBe(0);
});

it('answers an erased account exactly as an unknown email, even with a pending date', function (string $door): void {
    [$erased] = sdCreator('gone@example.test', pending: true);
    $erased->forceFill(['erased_at' => now()])->saveQuietly();

    $gone = sdEnvelope(sdSignIn($this, $door, ['email' => 'gone@example.test', 'password' => SD_PASSWORD, 'cancel_deletion' => true]));
    $never = sdEnvelope(sdSignIn($this, $door, ['email' => 'never@example.test', 'password' => SD_PASSWORD, 'cancel_deletion' => true]));

    expect($gone)->toBe($never);
})->with('doors');

// -----------------------------------------------------------------------------
// A token that survives is still refused
// -----------------------------------------------------------------------------

it('refuses a bearer token that belongs to a creator scheduled for deletion', function (): void {
    [$user] = sdCreator('t@example.test');
    $plain = $user->createToken('creator-app:ios', ['*'])->plainTextToken;

    app('auth')->forgetGuards();
    $this->withHeaders(['Origin' => 'capacitor://localhost', 'Authorization' => 'Bearer '.$plain])->getJson('/api/v1/me')->assertOk();

    // Scheduled without the revoke (what a bug or a race would leave behind).
    $user->forceFill(['deletion_requested_at' => now(), 'deletion_scheduled_for' => now()->addDays(14)])->saveQuietly();

    app('auth')->forgetGuards();
    $this->withHeaders(['Origin' => 'capacitor://localhost', 'Authorization' => 'Bearer '.$plain])->getJson('/api/v1/me')->assertUnauthorized();
    expect(DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count())->toBe(1);
});
