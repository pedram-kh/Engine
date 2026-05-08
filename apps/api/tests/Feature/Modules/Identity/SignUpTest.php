<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Contracts\PwnedPasswordsClientContract;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Events\EmailVerificationSent;
use App\Modules\Identity\Events\UserSignedUp;
use App\Modules\Identity\Mail\VerifyEmailMail;
use App\Modules\Identity\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    RateLimiter::for('auth-ip', static fn (Request $request): Limit => Limit::none());

    // Default to "not breached" so password rules pass; specific tests
    // override this binding to assert HIBP wiring.
    app()->bind(PwnedPasswordsClientContract::class, fn () => new class implements PwnedPasswordsClientContract
    {
        public function breachCount(string $plaintextPassword): int
        {
            return 0;
        }
    });
});

const VALID_PAYLOAD = [
    'name' => 'Pedro Costa',
    'email' => 'pedro@example.com',
    'password' => 'a-strong-passphrase-1234',
    'password_confirmation' => 'a-strong-passphrase-1234',
    'preferred_language' => 'pt',
];

// -----------------------------------------------------------------------------
// Strict: exactly one row in `users`, zero rows in any other domain table.
// -----------------------------------------------------------------------------

it('creates exactly one users row and touches no other domain table', function (): void {
    Mail::fake();

    $beforeUsers = DB::table('users')->count();
    $beforeAdminProfiles = DB::table('admin_profiles')->count();
    $beforeAgencyUsers = DB::table('agency_users')->count();
    $beforeAgencies = DB::table('agencies')->count();

    $this->postJson('/api/v1/auth/sign-up', VALID_PAYLOAD)
        ->assertStatus(201);

    expect(DB::table('users')->count())->toBe($beforeUsers + 1)
        ->and(DB::table('admin_profiles')->count())->toBe($beforeAdminProfiles)
        ->and(DB::table('agency_users')->count())->toBe($beforeAgencyUsers)
        ->and(DB::table('agencies')->count())->toBe($beforeAgencies);
});

// -----------------------------------------------------------------------------
// Response shape + secure persistence.
// -----------------------------------------------------------------------------

it('returns 201 with the public UserResource projection', function (): void {
    Mail::fake();

    $response = $this->postJson('/api/v1/auth/sign-up', VALID_PAYLOAD);

    $response->assertStatus(201)
        ->assertJsonPath('data.type', 'user')
        ->assertJsonPath('data.attributes.email', 'pedro@example.com')
        ->assertJsonPath('data.attributes.name', 'Pedro Costa')
        ->assertJsonPath('data.attributes.user_type', UserType::Creator->value)
        ->assertJsonPath('data.attributes.preferred_language', 'pt')
        ->assertJsonPath('data.attributes.email_verified_at', null)
        ->assertJsonMissingPath('data.attributes.password');
});

it('hashes the password with Argon2id (chunk 3 hashing config still in force)', function (): void {
    Mail::fake();

    $this->postJson('/api/v1/auth/sign-up', VALID_PAYLOAD)->assertStatus(201);

    $user = User::query()->where('email', 'pedro@example.com')->firstOrFail();

    expect(str_starts_with($user->password, '$argon2id$'))->toBeTrue();
});

it('normalises email to lower-case and stores email_verified_at as null', function (): void {
    Mail::fake();

    $this->postJson('/api/v1/auth/sign-up', [
        ...VALID_PAYLOAD,
        'email' => '  Pedro@Example.COM  ',
    ])->assertStatus(201);

    $user = User::query()->where('email', 'pedro@example.com')->firstOrFail();

    expect($user->email)->toBe('pedro@example.com')
        ->and($user->email_verified_at)->toBeNull();
});

it('defaults preferred_language to en when omitted', function (): void {
    Mail::fake();

    $payload = VALID_PAYLOAD;
    unset($payload['preferred_language']);

    $this->postJson('/api/v1/auth/sign-up', $payload)->assertStatus(201);

    expect(User::query()->where('email', 'pedro@example.com')->value('preferred_language'))->toBe('en');
});

// -----------------------------------------------------------------------------
// Validation: HIBP, length, dup email, weak format.
// -----------------------------------------------------------------------------

it('rejects a breached password (HIBP) — same rule as password reset', function (): void {
    Mail::fake();

    app()->bind(PwnedPasswordsClientContract::class, fn () => new class implements PwnedPasswordsClientContract
    {
        public function breachCount(string $plaintextPassword): int
        {
            return 17;
        }
    });

    $this->postJson('/api/v1/auth/sign-up', VALID_PAYLOAD)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['password']);

    expect(User::query()->where('email', 'pedro@example.com')->exists())->toBeFalse();
});

it('rejects a too-short password — same StrongPassword rule as reset', function (): void {
    Mail::fake();

    $this->postJson('/api/v1/auth/sign-up', [
        ...VALID_PAYLOAD,
        'password' => 'shortpw',
        'password_confirmation' => 'shortpw',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['password']);
});

it('rejects unconfirmed password', function (): void {
    Mail::fake();

    $this->postJson('/api/v1/auth/sign-up', [
        ...VALID_PAYLOAD,
        'password_confirmation' => 'different-passphrase-12',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['password']);
});

it('rejects an already-registered email', function (): void {
    Mail::fake();

    User::factory()->createOne(['email' => 'pedro@example.com']);

    $this->postJson('/api/v1/auth/sign-up', VALID_PAYLOAD)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

it('rejects a duplicate email regardless of case (Form Request normalises)', function (): void {
    Mail::fake();

    User::factory()->createOne(['email' => 'pedro@example.com']);

    $this->postJson('/api/v1/auth/sign-up', [
        ...VALID_PAYLOAD,
        'email' => 'PEDRO@example.com',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

it('rejects a malformed email', function (): void {
    Mail::fake();

    $this->postJson('/api/v1/auth/sign-up', [
        ...VALID_PAYLOAD,
        'email' => 'not-an-email',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

it('rejects an unsupported preferred_language', function (): void {
    Mail::fake();

    $this->postJson('/api/v1/auth/sign-up', [
        ...VALID_PAYLOAD,
        'preferred_language' => 'fr',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['preferred_language']);
});

// -----------------------------------------------------------------------------
// No session created on sign-up (priority 7).
// -----------------------------------------------------------------------------

it('does NOT create a session on sign-up (no Set-Cookie session, guest after)', function (): void {
    Mail::fake();

    $this->postJson('/api/v1/auth/sign-up', VALID_PAYLOAD)->assertStatus(201);

    $this->assertGuest('web');
    $this->assertGuest('web_admin');
});

it('a follow-up authenticated request still fails until login is performed', function (): void {
    Mail::fake();

    $this->postJson('/api/v1/auth/sign-up', VALID_PAYLOAD)->assertStatus(201);

    $this->postJson('/api/v1/auth/logout')->assertStatus(401);
});

// -----------------------------------------------------------------------------
// Mail + events.
// -----------------------------------------------------------------------------

it('queues the localized VerifyEmailMail to the new user', function (): void {
    Mail::fake();

    $this->postJson('/api/v1/auth/sign-up', VALID_PAYLOAD)->assertStatus(201);

    Mail::assertQueued(VerifyEmailMail::class, function (VerifyEmailMail $mail): bool {
        return $mail->hasTo('pedro@example.com')
            && $mail->locale === 'pt'
            && str_contains($mail->verifyUrl, 'token=');
    });
});

it('dispatches UserSignedUp and EmailVerificationSent events', function (): void {
    Mail::fake();
    Event::fake([UserSignedUp::class, EmailVerificationSent::class]);

    $this->postJson('/api/v1/auth/sign-up', VALID_PAYLOAD)->assertStatus(201);

    Event::assertDispatched(UserSignedUp::class);
    Event::assertDispatched(EmailVerificationSent::class);
});

// -----------------------------------------------------------------------------
// Audit verbs (priority 6).
// -----------------------------------------------------------------------------

it('writes auth.signup AND auth.email.verification_sent audit rows', function (): void {
    Mail::fake();

    $this->postJson('/api/v1/auth/sign-up', VALID_PAYLOAD)->assertStatus(201);

    $user = User::query()->where('email', 'pedro@example.com')->firstOrFail();

    $signup = AuditLog::query()->where('action', AuditAction::AuthSignedUp->value)->latest('id')->firstOrFail();
    $sent = AuditLog::query()->where('action', AuditAction::AuthEmailVerificationSent->value)->latest('id')->firstOrFail();

    expect($signup->actor_id)->toBe($user->id)
        ->and($signup->subject_id)->toBe($user->id)
        ->and($sent->actor_id)->toBe($user->id)
        ->and($sent->subject_id)->toBe($user->id);
});
