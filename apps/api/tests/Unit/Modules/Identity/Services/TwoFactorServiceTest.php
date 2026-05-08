<?php

declare(strict_types=1);

use App\Modules\Identity\Services\TwoFactorService;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

uses(TestCase::class);

it('generates a base32 secret of the expected length', function (): void {
    $service = app(TwoFactorService::class);

    $secret = $service->generateSecret();

    expect($secret)->toBeString()
        ->and(strlen($secret))->toBe(32)
        ->and(preg_match('/^[A-Z2-7]+$/', $secret))->toBe(1);
});

it('builds an otpauth URL containing issuer, account, and the supplied secret', function (): void {
    $service = app(TwoFactorService::class);
    $secret = $service->generateSecret();

    $url = $service->otpauthUrl('Catalyst', 'pedro@example.com', $secret);

    expect($url)->toStartWith('otpauth://totp/Catalyst:')
        ->and($url)->toContain('pedro%40example.com')
        ->and($url)->toContain('secret='.$secret)
        ->and($url)->toContain('issuer=Catalyst');
});

it('renders an SVG QR code for a given otpauth URL', function (): void {
    $service = app(TwoFactorService::class);
    $secret = $service->generateSecret();
    $url = $service->otpauthUrl('Catalyst', 'pedro@example.com', $secret);

    $svg = $service->qrCodeSvg($url);

    expect($svg)->toBeString()
        ->and($svg)->toStartWith('<?xml');
    expect(str_contains($svg, '<svg') || str_contains($svg, '<rect'))->toBeTrue();
});

it('verifies the current TOTP code generated for a known secret', function (): void {
    $service = app(TwoFactorService::class);
    $google = app(Google2FA::class);

    $secret = $service->generateSecret();
    $code = $google->getCurrentOtp($secret);

    expect($service->verifyTotp($secret, $code))->toBeTrue();
});

it('rejects a TOTP code that is not exactly 6 digits without consulting the library', function (): void {
    $service = app(TwoFactorService::class);
    $secret = $service->generateSecret();

    expect($service->verifyTotp($secret, 'abcdef'))->toBeFalse()
        ->and($service->verifyTotp($secret, '12345'))->toBeFalse()
        ->and($service->verifyTotp($secret, '1234567'))->toBeFalse()
        ->and($service->verifyTotp($secret, ''))->toBeFalse()
        ->and($service->verifyTotp($secret, '1234 56'))->toBeFalse();
});

it('rejects a 6-digit code that does not match the secret', function (): void {
    $service = app(TwoFactorService::class);
    $secret = $service->generateSecret();

    expect($service->verifyTotp($secret, '000000'))->toBeFalse();
});

it('returns false (not a 500) when the underlying library throws on a malformed secret', function (): void {
    $service = app(TwoFactorService::class);

    expect($service->verifyTotp('not-base32!', '123456'))->toBeFalse();
});

it('generates 10 unique formatted recovery codes per call', function (): void {
    $service = app(TwoFactorService::class);

    $codes = $service->generateRecoveryCodes();

    expect($codes)->toBeArray()
        ->and(count($codes))->toBe(10)
        ->and(count(array_unique($codes)))->toBe(10);

    foreach ($codes as $code) {
        expect(preg_match('/^[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}$/', $code))->toBe(1);
    }
});

it('hashes a recovery code with bcrypt and verifies the hash with checkRecoveryCode()', function (): void {
    $service = app(TwoFactorService::class);
    $code = $service->generateRecoveryCodes()[0];

    $hash = $service->hashRecoveryCode($code);

    expect(str_starts_with($hash, '$2y$'))->toBeTrue()
        ->and($service->checkRecoveryCode($code, $hash))->toBeTrue()
        ->and($service->checkRecoveryCode('wrong-code-zzzz-zzzz', $hash))->toBeFalse();
});

it('looksLikeTotpCode flags 6-digit numerics and rejects everything else', function (): void {
    $service = app(TwoFactorService::class);

    expect($service->looksLikeTotpCode('123456'))->toBeTrue()
        ->and($service->looksLikeTotpCode(' 123456 '))->toBeTrue()
        ->and($service->looksLikeTotpCode('1234567'))->toBeFalse()
        ->and($service->looksLikeTotpCode('abc123'))->toBeFalse()
        ->and($service->looksLikeTotpCode('aaaa-bbbb-cccc-dddd'))->toBeFalse()
        ->and($service->looksLikeTotpCode(''))->toBeFalse();
});
