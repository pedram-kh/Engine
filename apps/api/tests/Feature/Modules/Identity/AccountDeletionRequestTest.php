<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Events\AccountDeletionRequested;
use App\Modules\Identity\Events\LoginFailed;
use App\Modules\Identity\Features\AccountDeletionEnabled;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AccountDeletionService;
use App\Modules\Identity\Services\AccountDeletionStatus;
use App\Modules\Identity\Services\TwoFactorService;
use App\Modules\Identity\Services\TwoFactorVerificationThrottle;
use App\TestHelpers\Services\RateLimiterNeutralizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Laravel\Pennant\Feature;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\ErasureGraph;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| AH-104b-del A3 — POST /api/v1/me/account-deletion
|--------------------------------------------------------------------------
|
| A creator schedules their own account for deletion. Both doors (cookie
| session, creator-app bearer). The request only SCHEDULES: nothing is
| erased, every way of signing in is revoked, one audit row, one event after
| the commit. Re-authentication failures are 403 (never 401, which would sign
| the person out of the SPA), and none of them touches any counter.
*/

const DEL_PASSWORD = 'password-12chars';

beforeEach(function (): void {
    Cache::flush();
    Feature::activate(AccountDeletionEnabled::NAME);
});

/**
 * @return array{0: User, 1: string} [creator, bearer]
 */
function delCreator(string $tag = 'a'): array
{
    $user = User::factory()->creator()->createOne(['email' => "del-{$tag}@example.test"]);
    Creator::factory()->createOne(['user_id' => $user->id]);
    $plain = $user->createToken('creator-app:ios', ['*'])->plainTextToken;
    DB::table('sessions')->insert([
        'id' => "del-sess-{$tag}", 'user_id' => $user->id, 'ip_address' => '203.0.113.9',
        'user_agent' => 'phpunit', 'payload' => 'x', 'last_activity' => time(),
    ]);

    return [$user->refresh(), $plain];
}

/** @return array{0: User, 1: string, 2: list<string>} [creator with a confirmed 2FA, totp secret, plain recovery codes] */
function delMfaCreator(string $tag = 'm'): array
{
    [$user] = delCreator($tag);

    $svc = app(TwoFactorService::class);
    $secret = $svc->generateSecret();
    $plain = $svc->generateRecoveryCodes();
    $user->forceFill([
        'two_factor_secret' => $secret,
        'two_factor_recovery_codes' => array_map(fn (string $c) => $svc->hashRecoveryCode($c), $plain),
        'two_factor_confirmed_at' => now(),
    ])->saveQuietly();

    return [$user->refresh(), $secret, $plain];
}

function delTotp(string $secret): string
{
    return app(Google2FA::class)->getCurrentOtp($secret);
}

function delBearer(mixed $test, string $plain): mixed
{
    app('auth')->forgetGuards(); // a real request is a fresh app

    return $test->withHeaders(['Origin' => 'capacitor://localhost', 'Authorization' => 'Bearer '.$plain]);
}

/** @param array<string, mixed> $overrides */
function delBody(array $overrides = []): array
{
    return array_merge(['password' => DEL_PASSWORD, 'acknowledged' => true], $overrides);
}

/** @return array{tokens: int, sessions: int} */
function delCreds(User $user): array
{
    return [
        'tokens' => DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count(),
        'sessions' => DB::table('sessions')->where('user_id', $user->id)->count(),
    ];
}

/** Nothing about the account moved: still not pending, credentials intact, no audit row, no event. */
function delAssertUntouched(User $user, array $credsBefore): void
{
    $fresh = User::query()->findOrFail($user->id);
    expect($fresh->deletion_requested_at)->toBeNull()
        ->and($fresh->deletion_scheduled_for)->toBeNull()
        ->and($fresh->isSuspended())->toBeFalse()
        ->and(delCreds($user))->toBe($credsBefore)
        ->and(AuditLog::query()->where('action', AuditAction::AccountDeletionRequested)->count())->toBe(0);
}

// -----------------------------------------------------------------------------
// The flag
// -----------------------------------------------------------------------------

it('answers 404 as if the route were absent while the flag is off, and changes nothing', function (): void {
    Feature::deactivate(AccountDeletionEnabled::NAME);
    [$user, $plain] = delCreator();
    $before = delCreds($user);
    Event::fake([AccountDeletionRequested::class]);

    delBearer($this, $plain)->postJson('/api/v1/me/account-deletion', delBody())
        ->assertNotFound();

    delAssertUntouched($user, $before);
    Event::assertNotDispatched(AccountDeletionRequested::class);
});

// -----------------------------------------------------------------------------
// Happy path, both doors
// -----------------------------------------------------------------------------

it('schedules the deletion 14 days out over the bearer door and takes away every way in', function (): void {
    Carbon::setTestNow('2026-11-02 10:00:00');
    [$user, $plain] = delCreator();
    $user->createToken('second-device');
    Event::fake([AccountDeletionRequested::class]);

    $response = delBearer($this, $plain)->postJson('/api/v1/me/account-deletion', delBody());

    $response->assertOk()
        ->assertJsonPath('data.type', 'account_deletion')
        ->assertJsonPath('data.attributes.requested_at', '2026-11-02T10:00:00+00:00')
        ->assertJsonPath('data.attributes.scheduled_for', '2026-11-16T10:00:00+00:00');

    $fresh = User::query()->findOrFail($user->id);
    expect($fresh->deletion_requested_at?->toIso8601String())->toBe('2026-11-02T10:00:00+00:00')
        ->and($fresh->deletion_scheduled_for?->toIso8601String())->toBe('2026-11-16T10:00:00+00:00')
        ->and($fresh->isDeletionPending())->toBeTrue()
        ->and($fresh->erased_at)->toBeNull() // scheduled, not erased
        ->and(delCreds($user))->toBe(['tokens' => 0, 'sessions' => 0]);

    Event::assertDispatchedTimes(AccountDeletionRequested::class, 1);
    Event::assertDispatched(AccountDeletionRequested::class, fn (AccountDeletionRequested $e): bool => $e->user->is($user) && $e->scheduledFor->toIso8601String() === '2026-11-16T10:00:00+00:00');
});

it('schedules over the cookie door and signs the session out', function (): void {
    [$user] = delCreator();

    $this->actingAs($user, 'web')->postJson('/api/v1/me/account-deletion', delBody())->assertOk();

    expect(User::query()->findOrFail($user->id)->isDeletionPending())->toBeTrue()
        ->and(delCreds($user))->toBe(['tokens' => 0, 'sessions' => 0]);
    $this->assertGuest('web');
});

it('writes exactly one audit row, actor and subject the creator, with the date and what was revoked', function (): void {
    Carbon::setTestNow('2026-11-02 10:00:00');
    [$user, $plain] = delCreator();

    delBearer($this, $plain)->withHeader('User-Agent', 'CatalystApp/1.0')->postJson('/api/v1/me/account-deletion', delBody())->assertOk();

    $query = AuditLog::query()->where('action', AuditAction::AccountDeletionRequested);
    expect($query->count())->toBe(1);
    $row = $query->sole();
    $metadata = (array) $row->metadata;
    expect($row->actor_id)->toBe($user->id)
        ->and($row->subject_id)->toBe($user->id)
        ->and($metadata['scheduled_for'] ?? null)->toBe('2026-11-16T10:00:00+00:00')
        ->and($metadata['revoked'] ?? null)->toBe(['push_tokens' => 0, 'tokens' => 1, 'sessions' => 1])
        ->and($row->user_agent)->toBe('CatalystApp/1.0');
});

it('is idempotent: a second request answers with the first date, writes nothing, mails nothing', function (): void {
    Carbon::setTestNow('2026-11-02 10:00:00');
    [$user] = delCreator();
    Event::fake([AccountDeletionRequested::class]);

    $first = $this->actingAs($user, 'web')->postJson('/api/v1/me/account-deletion', delBody())->assertOk();

    Carbon::setTestNow('2026-11-05 09:00:00');
    $second = $this->actingAs($user->refresh(), 'web')->postJson('/api/v1/me/account-deletion', delBody())->assertOk();

    expect($second->json('data.attributes'))->toBe($first->json('data.attributes'))
        ->and(User::query()->findOrFail($user->id)->deletion_scheduled_for?->toIso8601String())->toBe('2026-11-16T10:00:00+00:00')
        ->and(AuditLog::query()->where('action', AuditAction::AccountDeletionRequested)->count())->toBe(1);
    Event::assertDispatchedTimes(AccountDeletionRequested::class, 1);
});

it('rolls everything back when the audit write fails: no date, credentials intact, no event', function (): void {
    [$user, $plain] = delCreator();
    $before = delCreds($user);
    Event::fake([AccountDeletionRequested::class]);
    Schema::drop('audit_logs');

    delBearer($this, $plain)->postJson('/api/v1/me/account-deletion', delBody())->assertStatus(500);

    $fresh = User::query()->findOrFail($user->id);
    expect($fresh->deletion_requested_at)->toBeNull()
        ->and($fresh->deletion_scheduled_for)->toBeNull()
        ->and(delCreds($user))->toBe($before);
    Event::assertNotDispatched(AccountDeletionRequested::class);
});

// -----------------------------------------------------------------------------
// Who may ask
// -----------------------------------------------------------------------------

it('refuses an unauthenticated caller', function (): void {
    $this->postJson('/api/v1/me/account-deletion', delBody())->assertUnauthorized();
});

it('refuses an agency user before the password or a code is even looked at', function (): void {
    $agencyUser = User::factory()->agencyAdmin()->createOne();
    $svc = app(TwoFactorService::class);
    $agencyUser->forceFill([
        'two_factor_secret' => $svc->generateSecret(),
        'two_factor_recovery_codes' => [],
        'two_factor_confirmed_at' => now(),
    ])->saveQuietly();

    // Wrong password, no code, 2FA on: every answer is the same "not available" (no oracle, no counters).
    $this->actingAs($agencyUser->refresh(), 'web')->postJson('/api/v1/me/account-deletion', delBody(['password' => 'nope']))
        ->assertForbidden()
        ->assertJsonPath('errors.0.code', 'account_deletion.not_available');

    expect(User::query()->findOrFail($agencyUser->id)->deletion_requested_at)->toBeNull()
        ->and(app(TwoFactorVerificationThrottle::class)->isSoftLocked($agencyUser))->toBeFalse();
});

it('refuses a suspended creator and an erased one before the password is looked at, and touches nothing', function (): void {
    [$suspended] = delCreator('s');
    $suspended->forceFill(['is_suspended' => true, 'suspended_reason' => 'x'])->saveQuietly();
    [$erased] = delCreator('e');
    $erased->forceFill(['erased_at' => now()])->saveQuietly();

    foreach ([$suspended, $erased] as $user) {
        $before = delCreds($user);
        $this->actingAs($user->refresh(), 'web')->postJson('/api/v1/me/account-deletion', delBody(['password' => 'nope']))
            ->assertForbidden()
            ->assertJsonPath('errors.0.code', 'account_deletion.not_available');
        expect(User::query()->findOrFail($user->id)->deletion_requested_at)->toBeNull()
            ->and(delCreds($user))->toBe($before);
    }
});

// -----------------------------------------------------------------------------
// The password
// -----------------------------------------------------------------------------

it('answers a wrong password 403 (never 401) with the sign-in code, and changes nothing', function (): void {
    [$user, $plain] = delCreator();
    $before = delCreds($user);

    delBearer($this, $plain)->postJson('/api/v1/me/account-deletion', delBody(['password' => 'not-the-password']))
        ->assertForbidden()
        ->assertJsonPath('errors.0.code', 'auth.invalid_credentials');

    delAssertUntouched($user, $before);
});

it('never suspends, locks or signs out an account however many wrong passwords arrive', function (): void {
    [$user] = delCreator();
    $before = delCreds($user);
    Event::fake([LoginFailed::class, AccountDeletionRequested::class]);
    $service = app(AccountDeletionService::class);

    for ($i = 0; $i < 10; $i++) {
        $result = $service->request($user, 'wrong-'.$i, null, Request::create('/x', 'POST'));
        expect($result->status)->toBe(AccountDeletionStatus::InvalidPassword);
    }

    delAssertUntouched($user, $before);
    Event::assertNotDispatched(LoginFailed::class); // the lockout counters hang on this event
    Event::assertNotDispatched(AccountDeletionRequested::class);
});

// -----------------------------------------------------------------------------
// The second factor
// -----------------------------------------------------------------------------

it('asks for the second factor when the account has one, and changes nothing without it', function (): void {
    [$user] = delMfaCreator();
    $before = delCreds($user);

    $this->actingAs($user, 'web')->postJson('/api/v1/me/account-deletion', delBody())
        ->assertForbidden()
        ->assertJsonPath('errors.0.code', 'auth.mfa_required');

    delAssertUntouched($user, $before);
});

it('refuses a wrong code and accepts a right TOTP', function (): void {
    [$user, $secret] = delMfaCreator();
    $before = delCreds($user);

    $this->actingAs($user, 'web')->postJson('/api/v1/me/account-deletion', delBody(['mfa_code' => '000000']))
        ->assertForbidden()
        ->assertJsonPath('errors.0.code', 'auth.mfa.invalid_code');
    delAssertUntouched($user, $before);

    $this->actingAs($user->refresh(), 'web')->postJson('/api/v1/me/account-deletion', delBody(['mfa_code' => delTotp($secret)]))
        ->assertOk();
    expect(User::query()->findOrFail($user->id)->isDeletionPending())->toBeTrue();
});

it('accepts a recovery code and burns it', function (): void {
    [$user, , $codes] = delMfaCreator();
    expect($user->two_factor_recovery_codes)->toHaveCount(count($codes));

    $this->actingAs($user, 'web')->postJson('/api/v1/me/account-deletion', delBody(['mfa_code' => $codes[0]]))
        ->assertOk();

    expect(User::query()->findOrFail($user->id)->two_factor_recovery_codes)->toHaveCount(count($codes) - 1);
});

it('does not spend a recovery code when the password is wrong', function (): void {
    [$user, , $codes] = delMfaCreator();

    $this->actingAs($user, 'web')->postJson('/api/v1/me/account-deletion', delBody(['password' => 'nope', 'mfa_code' => $codes[0]]))
        ->assertForbidden()
        ->assertJsonPath('errors.0.code', 'auth.invalid_credentials');

    expect(User::query()->findOrFail($user->id)->two_factor_recovery_codes)->toHaveCount(count($codes));
});

it('applies the sign-in second-factor throttle: locked at 5 bad codes, enrollment suspended at 10, never a lockout', function (): void {
    [$user, $secret] = delMfaCreator();
    $before = delCreds($user);
    $service = app(AccountDeletionService::class);
    $req = Request::create('/x', 'POST');

    for ($i = 0; $i < TwoFactorVerificationThrottle::SOFT_THRESHOLD; $i++) {
        expect($service->request($user, DEL_PASSWORD, '000000', $req)->status)->toBe(AccountDeletionStatus::MfaInvalidCode);
    }

    // Soft-locked: even the RIGHT code is refused now, with Retry-After, over HTTP.
    $this->actingAs($user->refresh(), 'web')
        ->postJson('/api/v1/me/account-deletion', delBody(['mfa_code' => delTotp($secret)]))
        ->assertStatus(423)
        ->assertJsonPath('errors.0.code', 'auth.mfa.rate_limited')
        ->assertHeader('Retry-After');
    delAssertUntouched($user, $before);

    // Keep knocking: the hard threshold is still reachable and suspends the enrollment.
    for ($i = TwoFactorVerificationThrottle::SOFT_THRESHOLD + 1; $i < TwoFactorVerificationThrottle::HARD_THRESHOLD; $i++) {
        $service->request($user->refresh(), DEL_PASSWORD, delTotp($secret), $req);
    }
    expect($user->refresh()->hasTwoFactorEnrollmentSuspended())->toBeTrue();

    $this->actingAs($user, 'web')->postJson('/api/v1/me/account-deletion', delBody(['mfa_code' => delTotp($secret)]))
        ->assertStatus(423)
        ->assertJsonPath('errors.0.code', 'auth.mfa.enrollment_suspended');
    delAssertUntouched($user, $before);
});

it('refuses a creator whose second factor was suspended by an admin, even with a right code', function (): void {
    [$user, $secret] = delMfaCreator();
    $user->forceFill(['two_factor_enrollment_suspended_at' => now()])->saveQuietly();
    $before = delCreds($user);

    $this->actingAs($user->refresh(), 'web')->postJson('/api/v1/me/account-deletion', delBody(['mfa_code' => delTotp($secret)]))
        ->assertStatus(423)
        ->assertJsonPath('errors.0.code', 'auth.mfa.enrollment_suspended');

    delAssertUntouched($user, $before);
});

// -----------------------------------------------------------------------------
// Eligibility (P3): told up front, not after the grace
// -----------------------------------------------------------------------------

it('refuses up front while money is held, or a real vendor holds the person, and says only why in a word', function (): void {
    $graph = ErasureGraph::seedSubject('del');
    $user = $graph->user;
    $user->forceFill(['password' => DEL_PASSWORD])->saveQuietly();
    $before = delCreds($user);

    $assignmentId = DB::table('campaign_assignments')->where('creator_id', $graph->creator->id)->value('id');
    DB::table('campaign_assignments')->where('id', $assignmentId)->update(['status' => 'payment_held']);

    $payment = $this->actingAs($user->refresh(), 'web')->postJson('/api/v1/me/account-deletion', delBody())
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'account_deletion.blocked')
        ->assertJsonPath('errors.0.meta.reasons', ['payment']);
    $content = (string) $payment->getContent();
    expect($content)->not->toContain('campaign_assignments');
    expect($content)->not->toContain($graph->email);

    DB::table('campaign_assignments')->where('id', $assignmentId)->update(['status' => 'accepted']);
    DB::table('creator_payout_methods')->where('creator_id', $graph->creator->id)->update(['provider' => 'stripe']);

    $this->actingAs($user->refresh(), 'web')->postJson('/api/v1/me/account-deletion', delBody())
        ->assertStatus(422)
        ->assertJsonPath('errors.0.meta.reasons', ['vendor']);

    delAssertUntouched($user, $before);
});

it('refuses up front while the creator has delivered work, in a word, and lets them through when it is gone (AH-104c)', function (string $status): void {
    $graph = ErasureGraph::seedSubject('del');
    $user = $graph->user;
    $user->forceFill(['password' => DEL_PASSWORD])->saveQuietly();
    $before = delCreds($user);

    $assignmentId = DB::table('campaign_assignments')->where('creator_id', $graph->creator->id)->value('id');
    DB::table('campaign_assignments')->where('id', $assignmentId)->update(['status' => $status]);

    $response = $this->actingAs($user->refresh(), 'web')->postJson('/api/v1/me/account-deletion', delBody())
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'account_deletion.blocked')
        ->assertJsonPath('errors.0.meta.reasons', ['delivered_work']);
    expect(str_contains((string) $response->getContent(), 'campaign_assignments'))->toBeFalse()
        ->and(str_contains((string) $response->getContent(), $graph->email))->toBeFalse();

    delAssertUntouched($user, $before);

    DB::table('campaign_assignments')->where('id', $assignmentId)->update(['status' => 'accepted']);

    $this->actingAs($user->refresh(), 'web')->postJson('/api/v1/me/account-deletion', delBody())->assertOk();
})->with(['live_verified', 'manually_verified', 'completed_on_approval']);

it('lets a creator with nothing blocking, and with real data around them, through', function (): void {
    $graph = ErasureGraph::seedSubject('ok');
    $graph->user->forceFill(['password' => DEL_PASSWORD])->saveQuietly();

    $this->actingAs($graph->user->refresh(), 'web')->postJson('/api/v1/me/account-deletion', delBody())->assertOk();

    expect(User::query()->findOrFail($graph->user->id)->isDeletionPending())->toBeTrue();
});

// -----------------------------------------------------------------------------
// Input and throttle
// -----------------------------------------------------------------------------

it('requires the password and the acknowledgement', function (array $body, string $field): void {
    [$user] = delCreator();
    $before = delCreds($user);

    $response = $this->actingAs($user, 'web')->postJson('/api/v1/me/account-deletion', $body)->assertStatus(422);

    expect($response->getContent())->toContain($field);
    delAssertUntouched($user, $before);
})->with([
    'no acknowledgement' => [['password' => DEL_PASSWORD], 'acknowledged'],
    'acknowledged false' => [['password' => DEL_PASSWORD, 'acknowledged' => false], 'acknowledged'],
    'no password' => [['acknowledged' => true], 'password'],
]);

it('allows 3 requests an hour per person, then 429 with Retry-After and nothing else', function (): void {
    [$user] = delCreator();
    [$other] = delCreator('other');

    for ($i = 0; $i < 3; $i++) {
        $this->actingAs($user, 'web')->postJson('/api/v1/me/account-deletion', delBody(['password' => 'wrong']))->assertForbidden();
    }

    $before = delCreds($user);
    $this->actingAs($user, 'web')->postJson('/api/v1/me/account-deletion', delBody())
        ->assertStatus(429)
        ->assertJsonPath('errors.0.code', 'rate_limit.exceeded')
        ->assertHeader('Retry-After');
    delAssertUntouched($user, $before); // the right password after three wrong ones is throttled, not honoured

    // Keyed on the person: someone else on the same IP is unaffected.
    $this->actingAs($other, 'web')->postJson('/api/v1/me/account-deletion', delBody())->assertOk();
});

it('registers the limiter and lets end-to-end specs neutralise it', function (): void {
    expect(RateLimiter::limiter('auth-account-deletion'))->not->toBeNull()
        ->and(RateLimiterNeutralizer::ALLOWED_NAMES)->toContain('auth-account-deletion');
});

it('is mounted without the agency tenancy gate and with both throttles', function (): void {
    $route = app('router')->getRoutes()->getByName('me.account-deletion');
    $middleware = $route?->gatherMiddleware() ?? [];

    expect($middleware)->toContain('tenancy.set')->toContain('throttle:auth-ip')->toContain('throttle:auth-account-deletion')
        ->and($middleware)->not->toContain('tenancy');
});
