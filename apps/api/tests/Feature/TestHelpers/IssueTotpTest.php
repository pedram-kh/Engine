<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\TwoFactorService;
use App\TestHelpers\Http\Middleware\VerifyTestHelperToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withHeader(VerifyTestHelperToken::HEADER, (string) config('test_helpers.token'));
});

it('returns a 6-digit TOTP code that verifies against the user secret', function (): void {
    /** @var User $user */
    $user = User::factory()->withTwoFactor()->createOne();

    $response = $this->postJson('/api/v1/_test/totp', ['user_id' => $user->id]);

    $response->assertOk();

    $code = $response->json('data.code');
    expect($code)->toBeString();

    /** @var string $code */
    expect(preg_match('/^\d{6}$/', $code))->toBe(1);

    /** @var TwoFactorService $twoFactor */
    $twoFactor = app(TwoFactorService::class);
    /** @var string $secret */
    $secret = $user->two_factor_secret;
    expect($twoFactor->verifyTotp($secret, $code))->toBeTrue();
});

it('returns 422 when neither user_id nor email is supplied', function (): void {
    $this->postJson('/api/v1/_test/totp', [])->assertStatus(422);
});

it('returns 422 when user_id is non-numeric', function (): void {
    $this->postJson('/api/v1/_test/totp', ['user_id' => 'banana'])->assertStatus(422);
});

it('returns 404 when no user matches the given id', function (): void {
    $this->postJson('/api/v1/_test/totp', ['user_id' => 999_999])->assertStatus(404);
});

it('returns a TOTP code when looked up by email (chunk-6.8 spec #19 path)', function (): void {
    /** @var User $user */
    $user = User::factory()->withTwoFactor()->createOne([
        'email' => 'jane@example.com',
    ]);

    $response = $this->postJson('/api/v1/_test/totp', ['email' => 'jane@example.com']);

    $response->assertOk();
    $code = $response->json('data.code');
    expect($code)->toBeString();
    /** @var string $code */
    expect(preg_match('/^\d{6}$/', $code))->toBe(1);

    /** @var TwoFactorService $twoFactor */
    $twoFactor = app(TwoFactorService::class);
    /** @var string $secret */
    $secret = $user->two_factor_secret;
    expect($twoFactor->verifyTotp($secret, $code))->toBeTrue();
});

it('lower-cases + trims the email before lookup', function (): void {
    User::factory()->withTwoFactor()->createOne([
        'email' => 'jane@example.com',
    ]);

    $this->postJson('/api/v1/_test/totp', ['email' => '  JANE@example.com  '])
        ->assertOk();
});

it('returns 404 when no user matches the given email', function (): void {
    $this->postJson('/api/v1/_test/totp', ['email' => 'noone@example.com'])
        ->assertStatus(404);
});

it('returns 422 when the user has no two_factor_secret yet', function (): void {
    /** @var User $user */
    $user = User::factory()->createOne();

    $this->postJson('/api/v1/_test/totp', ['user_id' => $user->id])
        ->assertStatus(422);
});

// -----------------------------------------------------------------------------
// Source-inspection: Google2FA isolation invariant must be preserved.
// -----------------------------------------------------------------------------

it('routes the TOTP call through TwoFactorService (no direct PragmaRX usage in TestHelpers)', function (): void {
    $controller = (string) file_get_contents(
        base_path('app/TestHelpers/Http/Controllers/IssueTotpController.php'),
    );

    expect(str_contains($controller, 'PragmaRX\\Google2FA'))->toBeFalse(
        'Test helpers must reach Google2FA via TwoFactorService — chunk 5 isolation invariant.',
    );
    expect(str_contains($controller, 'TwoFactorService'))->toBeTrue();
});
