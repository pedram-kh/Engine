<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Events\EmailVerified;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\EmailVerificationToken;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    RateLimiter::for('auth-ip', static fn (Request $request): Limit => Limit::none());
});

// -----------------------------------------------------------------------------
// Happy path: 204, email_verified_at flips, audit + event fire.
// -----------------------------------------------------------------------------

it('verifies a fresh user and stamps email_verified_at', function (): void {
    Event::fake([EmailVerified::class]);

    $user = User::factory()->unverified()->createOne(['email' => 'verify@example.com']);
    $token = app(EmailVerificationToken::class)->mint($user);

    $this->postJson('/api/v1/auth/verify-email', ['token' => $token])
        ->assertStatus(204);

    $user->refresh();
    expect($user->email_verified_at)->not()->toBeNull();

    Event::assertDispatched(EmailVerified::class);
});

it('writes an auth.email.verified audit row on first verification', function (): void {
    $user = User::factory()->unverified()->createOne(['email' => 'verify-audit@example.com']);
    $token = app(EmailVerificationToken::class)->mint($user);

    $this->postJson('/api/v1/auth/verify-email', ['token' => $token])->assertStatus(204);

    $audit = AuditLog::query()
        ->where('action', AuditAction::AuthEmailVerified->value)
        ->where('subject_id', $user->id)
        ->firstOrFail();

    expect($audit->actor_id)->toBe($user->id);
});

// -----------------------------------------------------------------------------
// Single-use guarantee: re-clicking returns 409 + auth.email.already_verified.
// -----------------------------------------------------------------------------

it('returns 409 with auth.email.already_verified on re-click of a verified user', function (): void {
    $user = User::factory()->createOne(['email' => 'verified@example.com']);
    // factory default sets email_verified_at = now()
    $token = app(EmailVerificationToken::class)->mint($user);

    $this->postJson('/api/v1/auth/verify-email', ['token' => $token])
        ->assertStatus(409)
        ->assertJsonPath('errors.0.code', 'auth.email.already_verified');
});

it('does NOT fire EmailVerified or write a fresh audit row on re-click', function (): void {
    Event::fake([EmailVerified::class]);

    $user = User::factory()->createOne();
    $token = app(EmailVerificationToken::class)->mint($user);

    $this->postJson('/api/v1/auth/verify-email', ['token' => $token])->assertStatus(409);

    Event::assertNotDispatched(EmailVerified::class);

    expect(AuditLog::query()->where('action', AuditAction::AuthEmailVerified->value)->count())->toBe(0);
});

// -----------------------------------------------------------------------------
// Expired token: 410 Gone with auth.email.verification_expired.
// -----------------------------------------------------------------------------

it('returns 410 with auth.email.verification_expired for tokens past 24h', function (): void {
    $user = User::factory()->unverified()->createOne(['email' => 'expired@example.com']);
    $tokens = app(EmailVerificationToken::class);

    $past = time() - (EmailVerificationToken::LIFETIME_HOURS * 3600) - 1;
    $token = $tokens->mint($user, now: $past);

    $this->postJson('/api/v1/auth/verify-email', ['token' => $token])
        ->assertStatus(410)
        ->assertJsonPath('errors.0.code', 'auth.email.verification_expired');

    $user->refresh();
    expect($user->email_verified_at)->toBeNull();
});

// -----------------------------------------------------------------------------
// Invalid token: bad signature / malformed / unknown user — same code.
// -----------------------------------------------------------------------------

it('returns 400 with auth.email.verification_invalid for a tampered signature', function (): void {
    $user = User::factory()->unverified()->createOne();
    $token = app(EmailVerificationToken::class)->mint($user);

    [$payload, $signature] = explode('.', $token);
    // Deterministic first-char swap: the prior `($signature === 'a' ? 'b' : 'a'.substr(...))`
    // shape compared the whole signature to the literal 'a' (never true) and then prepended
    // 'a', which silently no-op'd whenever the signature's first byte already was 'a' (~1/64
    // base64 flake → 204 instead of 400). Check the FIRST CHARACTER and flip it.
    $tampered = $payload.'.'.($signature[0] === 'a' ? 'b' : 'a').substr($signature, 1);

    $this->postJson('/api/v1/auth/verify-email', ['token' => $tampered])
        ->assertStatus(400)
        ->assertJsonPath('errors.0.code', 'auth.email.verification_invalid');
});

it('returns 400 with auth.email.verification_invalid for a malformed token', function (): void {
    $this->postJson('/api/v1/auth/verify-email', ['token' => 'not-a-real-token'])
        ->assertStatus(400)
        ->assertJsonPath('errors.0.code', 'auth.email.verification_invalid');
});

it('returns 400 with auth.email.verification_invalid for a token referencing an unknown user', function (): void {
    $user = User::factory()->unverified()->createOne();
    $token = app(EmailVerificationToken::class)->mint($user);

    $user->withAuditReason('test cleanup')->forceDelete();

    $this->postJson('/api/v1/auth/verify-email', ['token' => $token])
        ->assertStatus(400)
        ->assertJsonPath('errors.0.code', 'auth.email.verification_invalid');
});

it('returns 400 with auth.email.verification_invalid when the email changed after the token was minted', function (): void {
    $user = User::factory()->unverified()->createOne(['email' => 'before@example.com']);
    $token = app(EmailVerificationToken::class)->mint($user);

    $user->forceFill(['email' => 'after@example.com'])->save();

    $this->postJson('/api/v1/auth/verify-email', ['token' => $token])
        ->assertStatus(400)
        ->assertJsonPath('errors.0.code', 'auth.email.verification_invalid');
});

it('rejects requests missing the token field with 422', function (): void {
    $this->postJson('/api/v1/auth/verify-email', [])
        ->assertEnvelopeValidationErrors(['token']);
});
