<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\EmailVerificationToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('mints and decodes a valid token round-trip', function (): void {
    $user = User::factory()->createOne(['email' => 'rt@example.com']);
    $tokens = app(EmailVerificationToken::class);

    $token = $tokens->mint($user);
    $payload = $tokens->decode($token);

    expect($payload->valid)->toBeTrue()
        ->and($payload->userId)->toBe($user->id)
        ->and($payload->emailHash)->toBe(sha1('rt@example.com'))
        ->and($payload->isExpired())->toBeFalse();
});

it('produces a token with exactly two base64url segments separated by a dot', function (): void {
    $user = User::factory()->createOne();
    $token = app(EmailVerificationToken::class)->mint($user);

    expect(substr_count($token, '.'))->toBe(1)
        ->and(preg_match('/^[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+$/', $token))->toBe(1);
});

it('refuses a token whose payload has been tampered with', function (): void {
    $user = User::factory()->createOne();
    $tokens = app(EmailVerificationToken::class);

    $token = $tokens->mint($user);
    [$payload, $signature] = explode('.', $token);

    $tamperedPayload = $payload === 'a' ? 'b' : 'a'.substr($payload, 1);

    $result = $tokens->decode($tamperedPayload.'.'.$signature);

    expect($result->valid)->toBeFalse();
});

it('refuses a token whose signature has been tampered with', function (): void {
    $user = User::factory()->createOne();
    $tokens = app(EmailVerificationToken::class);

    $token = $tokens->mint($user);
    [$payload, $signature] = explode('.', $token);

    $tamperedSig = $signature === 'a' ? 'b' : 'a'.substr($signature, 1);

    expect($tokens->decode($payload.'.'.$tamperedSig)->valid)->toBeFalse();
});

it('refuses a token without two segments', function (): void {
    $tokens = app(EmailVerificationToken::class);

    expect($tokens->decode('only-one-segment')->valid)->toBeFalse()
        ->and($tokens->decode('three.segments.here')->valid)->toBeFalse()
        ->and($tokens->decode('')->valid)->toBeFalse();
});

it('marks the payload expired once the lifetime has elapsed', function (): void {
    $user = User::factory()->createOne();
    $tokens = app(EmailVerificationToken::class);

    $past = time() - (EmailVerificationToken::LIFETIME_HOURS * 3600) - 1;
    $token = $tokens->mint($user, now: $past);

    $payload = $tokens->decode($token);

    expect($payload->valid)->toBeTrue()
        ->and($payload->isExpired())->toBeTrue();
});

it('embeds an email_hash that detects post-mint email changes', function (): void {
    $user = User::factory()->createOne(['email' => 'before@example.com']);
    $tokens = app(EmailVerificationToken::class);

    $token = $tokens->mint($user);

    $user->forceFill(['email' => 'after@example.com'])->save();
    $payload = $tokens->decode($token);

    expect($payload->valid)->toBeTrue()
        ->and($payload->emailHash)->not()->toBe($tokens->hashEmail($user->email));
});

it('hashes emails case-insensitively and trim-insensitively', function (): void {
    $tokens = app(EmailVerificationToken::class);

    expect($tokens->hashEmail('User@Example.COM'))
        ->toBe($tokens->hashEmail('  user@example.com  '));
});

// -----------------------------------------------------------------------------
// Defensive branches — corrupt payloads that nonetheless carry a valid
// signature, so the post-signature validations fire.
// -----------------------------------------------------------------------------

/**
 * Forge a token whose signature is valid for an arbitrary base64url
 * payload string. Mirrors the production HMAC + key-resolution logic
 * so it stays in lock-step.
 */
function forgeToken(string $base64UrlEncodedPayload): string
{
    $key = (string) config('app.key');
    if (str_starts_with($key, 'base64:')) {
        $decoded = base64_decode(substr($key, 7), true);
        if ($decoded !== false) {
            $key = $decoded;
        }
    }

    $sig = hash_hmac('sha256', $base64UrlEncodedPayload, $key, binary: true);
    $sigEncoded = rtrim(strtr(base64_encode($sig), '+/', '-_'), '=');

    return $base64UrlEncodedPayload.'.'.$sigEncoded;
}

function base64UrlEncodeRaw(string $input): string
{
    return rtrim(strtr(base64_encode($input), '+/', '-_'), '=');
}

it('returns malformed when the base64url payload contains characters base64_decode rejects', function (): void {
    // Force an invalid base64 input that survives the signature check by
    // signing the bad string itself.
    $forged = forgeToken('not%%valid$$base64');

    expect(app(EmailVerificationToken::class)->decode($forged)->valid)->toBeFalse();
});

it('returns malformed when the decoded payload is not valid JSON', function (): void {
    $forged = forgeToken(base64UrlEncodeRaw('this is not json'));

    expect(app(EmailVerificationToken::class)->decode($forged)->valid)->toBeFalse();
});

it('returns malformed when the decoded JSON has wrong field types', function (): void {
    $badShape = (string) json_encode([
        'user_id' => 'not-an-int',
        'email_hash' => 12345,
        'expires_at' => 'not-an-int',
    ]);
    $forged = forgeToken(base64UrlEncodeRaw($badShape));

    expect(app(EmailVerificationToken::class)->decode($forged)->valid)->toBeFalse();
});

it('falls back gracefully when APP_KEY is set without the base64: prefix', function (): void {
    config(['app.key' => 'plain-non-base64-app-key-32-chars-long']);

    $user = User::factory()->createOne();
    $token = app(EmailVerificationToken::class)->mint($user);
    $payload = app(EmailVerificationToken::class)->decode($token);

    expect($payload->valid)->toBeTrue()
        ->and($payload->userId)->toBe($user->id);
});

it('decodes a payload whose encoded length is not divisible by 4 (padding branch)', function (): void {
    // Single-byte source → base64 yields 4 chars with 2 of them as `=`,
    // strip them and we hit the padding-restore branch on decode.
    $forged = forgeToken(base64UrlEncodeRaw('x'));

    // Doesn't matter that the payload isn't JSON — we just need to
    // exercise the padding branch in base64UrlDecode().
    expect(app(EmailVerificationToken::class)->decode($forged)->valid)->toBeFalse();
});
