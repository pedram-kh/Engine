<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\PwnedPasswordsClientContract;
use App\Modules\Identity\Mail\ResetPasswordMail;
use App\Modules\Identity\Mail\VerifyEmailMail;
use App\Modules\Identity\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    RateLimiter::for('auth-ip', static fn (Request $r): Limit => Limit::none());
    RateLimiter::for('auth-password', static fn (Request $r): Limit => Limit::none());

    app()->bind(PwnedPasswordsClientContract::class, fn () => new class implements PwnedPasswordsClientContract
    {
        public function breachCount(string $plaintextPassword): int
        {
            return 0;
        }
    });
});

/**
 * Helper — render a mailable's subject + HTML body inside a given locale.
 *
 * Mailable::locale() defers the actual locale switch to send-time via
 * Translator::withLocale(); to test envelope/render directly we set the
 * application locale explicitly around each call.
 *
 * @param  VerifyEmailMail|ResetPasswordMail  $mail
 * @return array{subject: string, body: string}
 */
function renderMailableLocale($mail, string $locale): array
{
    $previous = App::getLocale();
    App::setLocale($locale);

    try {
        return [
            'subject' => $mail->envelope()->subject ?? '',
            'body' => (string) $mail->render(),
        ];
    } finally {
        App::setLocale($previous);
    }
}

// -----------------------------------------------------------------------------
// VerifyEmailMail — assert subjects and bodies actually differ per locale.
// -----------------------------------------------------------------------------

it('renders VerifyEmailMail subjects in en/pt/it that are all distinct strings', function (): void {
    $user = User::factory()->unverified()->createOne();
    $mail = new VerifyEmailMail(user: $user, verifyUrl: 'https://example.test/verify?token=x', expiresInHours: 24);

    $en = renderMailableLocale($mail, 'en')['subject'];
    $pt = renderMailableLocale($mail, 'pt')['subject'];
    $it = renderMailableLocale($mail, 'it')['subject'];

    expect($en)->not()->toBe($pt)
        ->and($en)->not()->toBe($it)
        ->and($pt)->not()->toBe($it)
        ->and($en)->toContain('Verify')
        ->and($pt)->toContain('Confirme')
        ->and($it)->toContain('Verifica');
});

it('renders VerifyEmailMail body content that differs per locale (not just metadata)', function (): void {
    $user = User::factory()->unverified()->createOne();
    $mail = new VerifyEmailMail(user: $user, verifyUrl: 'https://example.test/verify?token=x', expiresInHours: 24);

    $en = renderMailableLocale($mail, 'en')['body'];
    $pt = renderMailableLocale($mail, 'pt')['body'];
    $it = renderMailableLocale($mail, 'it')['body'];

    expect($en)->toContain('Verify email address')
        ->and($pt)->toContain('Confirmar endereço de e-mail')
        ->and($it)->toContain('Verifica indirizzo email');

    expect($en)->not()->toBe($pt);
    expect($pt)->not()->toBe($it);
});

it('queues VerifyEmailMail with the user preferred_language as the locale', function (): void {
    Mail::fake();

    $payload = [
        'name' => 'Maria',
        'email' => 'maria@example.com',
        'password' => 'a-strong-passphrase-1234',
        'password_confirmation' => 'a-strong-passphrase-1234',
        'preferred_language' => 'pt',
    ];

    $this->postJson('/api/v1/auth/sign-up', $payload)->assertStatus(201);

    Mail::assertQueued(VerifyEmailMail::class, fn (VerifyEmailMail $m) => $m->locale === 'pt');
});

// -----------------------------------------------------------------------------
// ResetPasswordMail — chunk 3 mailable also actually localizes (regression).
// -----------------------------------------------------------------------------

it('renders ResetPasswordMail subjects in en/pt/it that are all distinct strings', function (): void {
    $user = User::factory()->createOne();
    $mail = new ResetPasswordMail(user: $user, resetUrl: 'https://example.test/reset?token=x', expiresInMinutes: 60);

    $en = renderMailableLocale($mail, 'en')['subject'];
    $pt = renderMailableLocale($mail, 'pt')['subject'];
    $it = renderMailableLocale($mail, 'it')['subject'];

    expect($en)->toContain('Reset')
        ->and($pt)->toContain('Redefinir')
        ->and($it)->toContain('Reimposta');

    expect($en)->not()->toBe($pt);
    expect($pt)->not()->toBe($it);
});

it('renders ResetPasswordMail body content that differs per locale', function (): void {
    $user = User::factory()->createOne();
    $mail = new ResetPasswordMail(user: $user, resetUrl: 'https://example.test/reset?token=x', expiresInMinutes: 60);

    $en = renderMailableLocale($mail, 'en')['body'];
    $pt = renderMailableLocale($mail, 'pt')['body'];
    $it = renderMailableLocale($mail, 'it')['body'];

    expect($en)->toContain('Reset password')
        ->and($pt)->toContain('Redefinir palavra-passe')
        ->and($it)->toContain('Reimposta password');
});
