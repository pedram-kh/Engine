<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\EmailVerificationToken;
use App\TestHelpers\Http\Middleware\VerifyTestHelperToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withHeader(VerifyTestHelperToken::HEADER, (string) config('test_helpers.token'));
});

it('mints a fresh verification token for an unverified user and returns the SPA URL', function (): void {
    $user = User::factory()->unverified()->createOne(['email' => 'jane@example.com']);

    $response = $this->getJson('/api/v1/_test/verification-token?email=jane@example.com');

    $response->assertOk()
        ->assertJsonStructure(['data' => ['token', 'verification_url']]);

    $token = $response->json('data.token');
    expect($token)->toBeString();

    /** @var string $token */
    $payload = app(EmailVerificationToken::class)->decode($token);
    expect($payload->valid)->toBeTrue()
        ->and($payload->userId)->toBe($user->id);
});

it('builds the verification URL against the configured frontend_main_url', function (): void {
    config()->set('app.frontend_main_url', 'https://app.example.test');
    $user = User::factory()->unverified()->createOne(['email' => 'pedro@example.com']);

    $response = $this->getJson('/api/v1/_test/verification-token?email=pedro@example.com');

    $url = $response->json('data.verification_url');
    expect($url)->toStartWith('https://app.example.test/auth/verify-email?token=');
    /** @var User $user */
    expect($user->id)->toBeInt();
});

it('lower-cases and trims the email query parameter', function (): void {
    User::factory()->unverified()->createOne(['email' => 'lc@example.com']);

    $this->getJson('/api/v1/_test/verification-token?email=%20%20LC%40Example.COM%20')
        ->assertOk();
});

it('returns 400 when the email query parameter is missing', function (): void {
    $this->getJson('/api/v1/_test/verification-token')
        ->assertStatus(400);
});

it('returns 404 when the email does not match a known user', function (): void {
    $this->getJson('/api/v1/_test/verification-token?email=nobody@example.com')
        ->assertStatus(404);
});

it('refuses without a valid X-Test-Helper-Token header', function (): void {
    User::factory()->unverified()->createOne(['email' => 'nope@example.com']);

    // Override the header set in beforeEach.
    $this->withHeader(VerifyTestHelperToken::HEADER, '')
        ->getJson('/api/v1/_test/verification-token?email=nope@example.com')
        ->assertStatus(404);
});
