<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Events\EmailVerificationSent;
use App\Modules\Identity\Mail\VerifyEmailMail;
use App\Modules\Identity\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    RateLimiter::for('auth-ip', static fn (Request $request): Limit => Limit::none());
});

// -----------------------------------------------------------------------------
// Happy path: queues mail, audits, fires event.
// -----------------------------------------------------------------------------

it('queues a localized verification mail and dispatches the event for an unverified user', function (): void {
    Mail::fake();
    Event::fake([EmailVerificationSent::class]);
    RateLimiter::for('auth-resend-verification', static fn (Request $r): Limit => Limit::none());

    User::factory()->unverified()->createOne([
        'email' => 'resend@example.com',
        'preferred_language' => 'it',
    ]);

    $this->postJson('/api/v1/auth/resend-verification', ['email' => 'resend@example.com'])
        ->assertStatus(204);

    Mail::assertQueued(VerifyEmailMail::class, function (VerifyEmailMail $mail): bool {
        return $mail->hasTo('resend@example.com') && $mail->locale === 'it';
    });

    Event::assertDispatched(EmailVerificationSent::class);
});

it('writes an auth.email.verification_sent audit row for the unverified user', function (): void {
    Mail::fake();
    RateLimiter::for('auth-resend-verification', static fn (Request $r): Limit => Limit::none());

    $user = User::factory()->unverified()->createOne(['email' => 'resend-audit@example.com']);

    $this->postJson('/api/v1/auth/resend-verification', ['email' => 'resend-audit@example.com'])
        ->assertStatus(204);

    $audit = AuditLog::query()
        ->where('action', AuditAction::AuthEmailVerificationSent->value)
        ->latest('id')
        ->firstOrFail();

    expect($audit->subject_id)->toBe($user->id)
        ->and($audit->actor_id)->toBe($user->id);
});

// -----------------------------------------------------------------------------
// Silent 204 for unknown / already-verified emails (user-enumeration defence).
// -----------------------------------------------------------------------------

it('returns 204 silently for an unknown email and queues nothing', function (): void {
    Mail::fake();
    Event::fake([EmailVerificationSent::class]);
    RateLimiter::for('auth-resend-verification', static fn (Request $r): Limit => Limit::none());

    $this->postJson('/api/v1/auth/resend-verification', ['email' => 'nobody@example.com'])
        ->assertStatus(204);

    Mail::assertNothingQueued();
    Event::assertNotDispatched(EmailVerificationSent::class);
    expect(AuditLog::query()->where('action', AuditAction::AuthEmailVerificationSent->value)->count())->toBe(0);
});

it('returns 204 silently for an already-verified email and queues nothing', function (): void {
    Mail::fake();
    Event::fake([EmailVerificationSent::class]);
    RateLimiter::for('auth-resend-verification', static fn (Request $r): Limit => Limit::none());

    User::factory()->createOne(['email' => 'already@example.com']); // factory default: verified

    $this->postJson('/api/v1/auth/resend-verification', ['email' => 'already@example.com'])
        ->assertStatus(204);

    Mail::assertNothingQueued();
    Event::assertNotDispatched(EmailVerificationSent::class);
});

// -----------------------------------------------------------------------------
// Rate limit (priority 4): 1/min/email returns 429 + standard envelope.
// -----------------------------------------------------------------------------

it('rate-limits the second request within 60s per email with the standard envelope', function (): void {
    Mail::fake();
    User::factory()->unverified()->createOne(['email' => 'limit@example.com']);

    $this->postJson('/api/v1/auth/resend-verification', ['email' => 'limit@example.com'])
        ->assertStatus(204);

    $this->postJson('/api/v1/auth/resend-verification', ['email' => 'limit@example.com'])
        ->assertStatus(429)
        ->assertJsonPath('errors.0.code', 'rate_limit.exceeded')
        ->assertHeader('Retry-After');
});

it('limits per email — different addresses are independently allowed', function (): void {
    Mail::fake();
    User::factory()->unverified()->createOne(['email' => 'one@example.com']);
    User::factory()->unverified()->createOne(['email' => 'two@example.com']);

    $this->postJson('/api/v1/auth/resend-verification', ['email' => 'one@example.com'])->assertStatus(204);
    $this->postJson('/api/v1/auth/resend-verification', ['email' => 'two@example.com'])->assertStatus(204);
});

it('rejects requests missing the email field with 422', function (): void {
    $this->postJson('/api/v1/auth/resend-verification', [])
        ->assertEnvelopeValidationErrors(['email']);
});
