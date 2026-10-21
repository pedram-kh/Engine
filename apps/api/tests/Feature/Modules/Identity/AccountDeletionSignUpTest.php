<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\PwnedPasswordsClientContract;
use App\Modules\Identity\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| AH-104b-del A5 — signing up with an address whose account is scheduled for deletion
|--------------------------------------------------------------------------
|
| The "email taken" answer is ONE static string for every taken address, and
| it already carries the way back ("sign in to restore it"). Before any
| credentials exist the sign-up form must not be able to tell a pending account
| from an ordinary one: same status, same body, same words.
*/

beforeEach(function (): void {
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
function suPayload(string $email): array
{
    return [
        'name' => 'Pedro',
        'last_name' => 'Costa',
        'email' => $email,
        'password' => 'a-strong-passphrase-1234',
        'password_confirmation' => 'a-strong-passphrase-1234',
        'preferred_language' => 'en',
    ];
}

const SU_STATIC_EN = 'An account with this email already exists. Sign in to use it, or reset your password. If you asked for this account to be deleted, signing in lets you restore it.';

it('answers a pending address byte-for-byte like an ordinary taken one', function (): void {
    User::factory()->creator()->createOne(['email' => 'plain@example.test']);
    User::factory()->creator()->createOne([
        'email' => 'pending@example.test',
        'deletion_requested_at' => now()->subDay(),
        'deletion_scheduled_for' => now()->addDays(13),
    ]);

    $plain = $this->postJson('/api/v1/auth/sign-up', suPayload('plain@example.test'));
    $pending = $this->postJson('/api/v1/auth/sign-up', suPayload('pending@example.test'));
    $pendingShouting = $this->postJson('/api/v1/auth/sign-up', suPayload('  PENDING@Example.TEST '));

    $plain->assertStatus(422);
    expect($pending->getStatusCode())->toBe($plain->getStatusCode())
        ->and($pendingShouting->getStatusCode())->toBe($plain->getStatusCode());

    // Same words everywhere the message can appear; the only per-request values are ids.
    $normalise = static fn (mixed $r): string => (string) preg_replace('/"(id|request_id|trace_id)":"[^"]*"/', '"$1":"*"', (string) $r->getContent());
    $swap = static fn (string $body, string $email): string => str_replace($email, 'EMAIL', $body);

    expect($swap($normalise($pending), 'pending@example.test'))->toBe($swap($normalise($plain), 'plain@example.test'))
        ->and($normalise($pendingShouting))->toBe($normalise($pending));
});

it('says one static thing, with the way back, for every taken address', function (): void {
    User::factory()->creator()->createOne(['email' => 'plain@example.test']);

    $response = $this->postJson('/api/v1/auth/sign-up', suPayload('plain@example.test'));

    $messages = [];
    $json = $response->json();
    array_walk_recursive($json, static function (mixed $v) use (&$messages): void {
        is_string($v) && $messages[] = $v;
    });

    expect($messages)->toContain(SU_STATIC_EN)
        ->and(trans('auth.signup.email_taken', [], 'en'))->toBe(SU_STATIC_EN);
});

it('has no state in the string: no placeholders, and every one of the 24 locales has its own non-empty copy', function (): void {
    $locales = array_map('basename', glob(lang_path('*'), GLOB_ONLYDIR) ?: []);
    expect($locales)->toHaveCount(24);

    foreach ($locales as $locale) {
        $text = trans('auth.signup.email_taken', [], $locale);
        expect($text)->toBeString();
        expect($text)->not->toBe('auth.signup.email_taken');
        expect($text)->not->toBe('');
        expect($text)->not->toContain(':');
        if ($locale !== 'en') {
            expect($text)->not->toBe(SU_STATIC_EN);
        }
    }
});
