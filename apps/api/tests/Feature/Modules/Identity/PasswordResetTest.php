<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Contracts\PwnedPasswordsClientContract;
use App\Modules\Identity\Events\PasswordResetCompleted;
use App\Modules\Identity\Events\PasswordResetRequested;
use App\Modules\Identity\Mail\ResetPasswordMail;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AccountLockoutService;
use App\Modules\Identity\Services\FailedLoginTracker;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    RateLimiter::for('auth-ip', static fn (Request $request): Limit => Limit::none());
    RateLimiter::for('auth-password', static fn (Request $request): Limit => Limit::none());

    // HIBP: every password we test with is treated as not-breached unless
    // a specific test rebinds the contract.
    app()->bind(PwnedPasswordsClientContract::class, fn () => new class implements PwnedPasswordsClientContract
    {
        public function breachCount(string $plaintextPassword): int
        {
            return 0;
        }
    });
});

// -----------------------------------------------------------------------------
// /forgot-password
// -----------------------------------------------------------------------------

it('queues a localized reset mail for a known email', function (): void {
    Mail::fake();
    Event::fake([PasswordResetRequested::class]);

    $user = User::factory()->createOne([
        'email' => 'forgot@example.com',
        'preferred_language' => 'pt',
    ]);

    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'forgot@example.com'])
        ->assertStatus(204);

    Mail::assertQueued(ResetPasswordMail::class, function (ResetPasswordMail $mail) use ($user): bool {
        return $mail->hasTo($user->email)
            && $mail->locale === 'pt'
            && str_contains($mail->resetUrl, 'token=')
            && str_contains($mail->resetUrl, 'email=forgot%40example.com');
    });

    Event::assertDispatched(PasswordResetRequested::class);
});

it('audits auth.password.reset_requested when the email is known', function (): void {
    Mail::fake();

    $user = User::factory()->createOne(['email' => 'audit@example.com']);

    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'audit@example.com'])
        ->assertStatus(204);

    $audit = AuditLog::query()->where('action', AuditAction::AuthPasswordResetRequested->value)->latest('id')->firstOrFail();
    expect($audit->subject_id)->toBe($user->id);
});

it('returns 204 silently for an unknown email (user enumeration defence)', function (): void {
    Mail::fake();
    Event::fake([PasswordResetRequested::class]);

    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com'])
        ->assertStatus(204);

    Mail::assertNothingQueued();
    Event::assertNotDispatched(PasswordResetRequested::class);

    expect(AuditLog::query()->where('action', AuditAction::AuthPasswordResetRequested->value)->count())->toBe(0);
});

it('rejects forgot-password without an email', function (): void {
    $this->postJson('/api/v1/auth/forgot-password', [])
        ->assertStatus(422);
});

// -----------------------------------------------------------------------------
// /reset-password
// -----------------------------------------------------------------------------

it('completes a password reset and invalidates the failed-login state', function (): void {
    Event::fake([PasswordResetCompleted::class]);

    $user = User::factory()->createOne(['email' => 'reset@example.com']);
    $token = Password::broker()->createToken($user);

    /** @var FailedLoginTracker $tracker */
    $tracker = app(FailedLoginTracker::class);
    /** @var AccountLockoutService $lockout */
    $lockout = app(AccountLockoutService::class);
    $tracker->record('reset@example.com');
    $lockout->temporaryLock('reset@example.com');

    $this->postJson('/api/v1/auth/reset-password', [
        'email' => 'reset@example.com',
        'token' => $token,
        'password' => 'a-brand-new-passphrase-1234',
        'password_confirmation' => 'a-brand-new-passphrase-1234',
    ])->assertStatus(204);

    $user->refresh();
    expect(Hash::check('a-brand-new-passphrase-1234', $user->password))->toBeTrue()
        ->and($tracker->shortWindowCount('reset@example.com'))->toBe(0)
        ->and($lockout->isTemporarilyLocked('reset@example.com'))->toBeFalse();

    Event::assertDispatched(PasswordResetCompleted::class);
});

it('audits auth.password.reset_completed', function (): void {
    $user = User::factory()->createOne(['email' => 'audit2@example.com']);
    $token = Password::broker()->createToken($user);

    $this->postJson('/api/v1/auth/reset-password', [
        'email' => 'audit2@example.com',
        'token' => $token,
        'password' => 'a-brand-new-passphrase-1234',
        'password_confirmation' => 'a-brand-new-passphrase-1234',
    ])->assertStatus(204);

    expect(AuditLog::query()->where('action', AuditAction::AuthPasswordResetCompleted->value)->where('subject_id', $user->id)->count())->toBe(1);
});

it('rejects an invalid token with auth.password.invalid_token', function (): void {
    User::factory()->createOne(['email' => 'reset@example.com']);

    $this->postJson('/api/v1/auth/reset-password', [
        'email' => 'reset@example.com',
        'token' => 'totally-bogus-token',
        'password' => 'a-brand-new-passphrase-1234',
        'password_confirmation' => 'a-brand-new-passphrase-1234',
    ])->assertStatus(400)
        ->assertJsonPath('errors.0.code', 'auth.password.invalid_token');
});

it('rejects a too-short password with auth.password.too_short', function (): void {
    User::factory()->createOne(['email' => 'reset@example.com']);
    $token = Password::broker()->createToken(User::query()->where('email', 'reset@example.com')->firstOrFail());

    $this->postJson('/api/v1/auth/reset-password', [
        'email' => 'reset@example.com',
        'token' => $token,
        'password' => 'shortpw',
        'password_confirmation' => 'shortpw',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['password']);
});

it('rejects a breached password (HIBP) with auth.password.breached', function (): void {
    app()->bind(PwnedPasswordsClientContract::class, fn () => new class implements PwnedPasswordsClientContract
    {
        public function breachCount(string $plaintextPassword): int
        {
            return 9999;
        }
    });

    User::factory()->createOne(['email' => 'reset@example.com']);
    $token = Password::broker()->createToken(User::query()->where('email', 'reset@example.com')->firstOrFail());

    $response = $this->postJson('/api/v1/auth/reset-password', [
        'email' => 'reset@example.com',
        'token' => $token,
        'password' => 'a-very-strong-passphrase-1234',
        'password_confirmation' => 'a-very-strong-passphrase-1234',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['password']);
});

it('rejects a non-confirmed password', function (): void {
    User::factory()->createOne(['email' => 'reset@example.com']);
    $token = Password::broker()->createToken(User::query()->where('email', 'reset@example.com')->firstOrFail());

    $this->postJson('/api/v1/auth/reset-password', [
        'email' => 'reset@example.com',
        'token' => $token,
        'password' => 'a-brand-new-passphrase-1234',
        'password_confirmation' => 'something-else',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['password']);
});
