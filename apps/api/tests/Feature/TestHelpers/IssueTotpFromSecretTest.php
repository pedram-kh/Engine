<?php

declare(strict_types=1);

use App\Modules\Identity\Services\TwoFactorService;
use App\TestHelpers\Http\Middleware\VerifyTestHelperToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| /api/v1/_test/totp/secret — in-flight TOTP minting (chunk 7.1 spec #19)
|--------------------------------------------------------------------------
|
| Pins the contract for the new endpoint that accepts a base32 secret
| directly and returns a current TOTP code. Companion to IssueTotpTest
| which exercises the post-confirm (`users.two_factor_secret`) path.
|
| The chunk-5 isolation invariant is checked by the source-inspection
| assertion at the bottom: like IssueTotpController, this controller
| reaches Google2FA exclusively via TwoFactorService.
*/

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withHeader(VerifyTestHelperToken::HEADER, (string) config('test_helpers.token'));
});

// -----------------------------------------------------------------------------
// Happy path — code derives from the supplied secret and verifies
// -----------------------------------------------------------------------------

it('returns a 6-digit TOTP code that verifies against the supplied secret', function (): void {
    /** @var TwoFactorService $twoFactor */
    $twoFactor = app(TwoFactorService::class);
    $secret = $twoFactor->generateSecret();

    $response = $this->postJson('/api/v1/_test/totp/secret', ['secret' => $secret]);

    $response->assertOk();
    $code = $response->json('data.code');
    expect($code)->toBeString();
    /** @var string $code */
    expect(preg_match('/^\d{6}$/', $code))->toBe(1);
    expect($twoFactor->verifyTotp($secret, $code))->toBeTrue();
});

it('trims surrounding whitespace before deriving the code', function (): void {
    /** @var TwoFactorService $twoFactor */
    $twoFactor = app(TwoFactorService::class);
    $secret = $twoFactor->generateSecret();

    $response = $this->postJson('/api/v1/_test/totp/secret', [
        'secret' => '  '.$secret.'  ',
    ]);

    $response->assertOk();
    $code = $response->json('data.code');
    expect($twoFactor->verifyTotp($secret, $code))->toBeTrue();
});

// -----------------------------------------------------------------------------
// Failure modes
// -----------------------------------------------------------------------------

it('returns 422 when the secret field is missing', function (): void {
    $response = $this->postJson('/api/v1/_test/totp/secret', []);

    $response->assertStatus(422);
    expect($response->json('error'))->toContain('secret');
});

it('returns 422 when the secret field is an empty string', function (): void {
    $this->postJson('/api/v1/_test/totp/secret', ['secret' => ''])->assertStatus(422);
});

it('returns 422 when the secret field is whitespace only', function (): void {
    $this->postJson('/api/v1/_test/totp/secret', ['secret' => '   '])->assertStatus(422);
});

it('returns 422 when the secret field is not a string', function (): void {
    $this->postJson('/api/v1/_test/totp/secret', ['secret' => 12345])->assertStatus(422);
});

it('returns 422 with a debuggable error message when the secret is not valid base32', function (): void {
    $response = $this->postJson('/api/v1/_test/totp/secret', [
        'secret' => 'this-is-not-base32!!!1',
    ]);

    $response->assertStatus(422);
    expect($response->json('error'))->toContain('base32');
});

// -----------------------------------------------------------------------------
// Gating contract — env layer only
// -----------------------------------------------------------------------------
//
// The header-missing / wrong-token cases for the _test/* surface are
// exercised once centrally in `GatingTest.php` against a
// representative endpoint (`/_test/clock/reset`). Adding the same
// cases per-endpoint is over-coverage and trips on the
// PHPUnit `defaultHeaders` merge semantics (`withHeaders([])` does
// not clear a header set in the file's `beforeEach`). The env-layer
// case below is per-endpoint because it exercises a different
// branch (provider-level gateOpen vs middleware-level token check).

it('returns 404 when env is production even with a correct token', function (): void {
    config()->set('app.env', 'production');

    /** @var TwoFactorService $twoFactor */
    $twoFactor = app(TwoFactorService::class);
    $secret = $twoFactor->generateSecret();

    $this->withHeader(VerifyTestHelperToken::HEADER, (string) config('test_helpers.token'))
        ->postJson('/api/v1/_test/totp/secret', ['secret' => $secret])
        ->assertStatus(404);
});

// -----------------------------------------------------------------------------
// Source-inspection: chunk-5 Google2FA isolation invariant preserved.
// -----------------------------------------------------------------------------

it('routes the TOTP call through TwoFactorService (no direct PragmaRX usage in TestHelpers)', function (): void {
    $controller = (string) file_get_contents(
        base_path('app/TestHelpers/Http/Controllers/IssueTotpFromSecretController.php'),
    );

    expect(str_contains($controller, 'PragmaRX\\Google2FA'))->toBeFalse(
        'Test helpers must reach Google2FA via TwoFactorService — chunk 5 isolation invariant.',
    );
    expect(str_contains($controller, 'TwoFactorService'))->toBeTrue();
});
