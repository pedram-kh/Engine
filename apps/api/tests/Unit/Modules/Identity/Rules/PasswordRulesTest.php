<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\PwnedPasswordsClientContract;
use App\Modules\Identity\Rules\PasswordIsNotBreached;
use App\Modules\Identity\Rules\StrongPassword;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

uses(TestCase::class);

// -----------------------------------------------------------------------------
// StrongPassword — driven through the Validator so we exercise the rule in
// the same code path Form Requests use.
// -----------------------------------------------------------------------------

it('rejects passwords shorter than 12 characters', function (): void {
    $validator = Validator::make(['password' => 'short'], ['password' => [new StrongPassword]]);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('password'))->toContain('12');
});

it('rejects passwords longer than 128 characters', function (): void {
    $validator = Validator::make(
        ['password' => str_repeat('a', 129)],
        ['password' => [new StrongPassword]],
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('password'))->toContain('128');
});

it('rejects non-string values', function (): void {
    $validator = Validator::make(['password' => 12345], ['password' => [new StrongPassword]]);

    expect($validator->fails())->toBeTrue();
});

it('accepts a 12-character password', function (): void {
    $validator = Validator::make(['password' => '123456789012'], ['password' => [new StrongPassword]]);

    expect($validator->fails())->toBeFalse();
});

it('accepts a 128-character password (upper boundary)', function (): void {
    $validator = Validator::make(
        ['password' => str_repeat('a', 128)],
        ['password' => [new StrongPassword]],
    );

    expect($validator->fails())->toBeFalse();
});

// -----------------------------------------------------------------------------
// PasswordIsNotBreached — same pattern, with HIBP client stubs.
// -----------------------------------------------------------------------------

it('passes when the HIBP client returns 0 hits', function (): void {
    $client = new class implements PwnedPasswordsClientContract
    {
        public function breachCount(string $plaintextPassword): int
        {
            return 0;
        }
    };

    $validator = Validator::make(
        ['password' => 'safe-passphrase-1234'],
        ['password' => [new PasswordIsNotBreached($client)]],
    );

    expect($validator->fails())->toBeFalse();
});

it('fails when the HIBP client returns any positive count', function (): void {
    $client = new class implements PwnedPasswordsClientContract
    {
        public function breachCount(string $plaintextPassword): int
        {
            return 7;
        }
    };

    $validator = Validator::make(
        ['password' => 'definitely-breached'],
        ['password' => [new PasswordIsNotBreached($client)]],
    );

    expect($validator->fails())->toBeTrue();
});

it('skips the check for empty / non-string values (other rules cover those)', function (): void {
    $client = new class implements PwnedPasswordsClientContract
    {
        public int $calls = 0;

        public function breachCount(string $plaintextPassword): int
        {
            $this->calls++;

            return 0;
        }
    };

    Validator::make(
        ['password' => ''],
        ['password' => [new PasswordIsNotBreached($client)]],
    )->fails();

    Validator::make(
        ['password' => 12345],
        ['password' => [new PasswordIsNotBreached($client)]],
    )->fails();

    expect($client->calls)->toBe(0);
});
