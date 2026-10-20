<?php

declare(strict_types=1);

use App\Modules\Identity\Events\LoginFailed;
use App\Modules\Identity\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| AH-104a Stage 1 — an erased account cannot sign in
|--------------------------------------------------------------------------
|
| The guard is ONE edit, in AuthService::evaluateGates(), right after the user
| is looked up and BEFORE the password is checked. Both doors share that graph
| (the SPA's /auth/login and the native /auth/token), so both are pinned here.
|
| Each negative is eligible in every respect except the marker: the password is
| CORRECT. The property that matters: no session, no token, and a response
| indistinguishable from an unknown email.
*/

const ER_PASSWORD = 'password-12chars';

beforeEach(function (): void {
    RateLimiter::for('auth-ip', static fn (Request $request): Limit => Limit::none());
    RateLimiter::for('auth-login-email', static fn (Request $request): Limit => Limit::none());
    RateLimiter::for('auth-password', static fn (Request $request): Limit => Limit::none());
    Cache::flush();
});

function erasedLoginBody(string $email): array
{
    return ['email' => $email, 'password' => ER_PASSWORD];
}

it('refuses the SPA login of an erased account that presents the correct password', function (): void {
    User::factory()->creator()->erased()->create(['email' => 'gone@example.com']);

    $this->postJson('/api/v1/auth/login', erasedLoginBody('gone@example.com'))
        ->assertStatus(401)
        ->assertJsonPath('errors.0.code', 'auth.invalid_credentials');

    $this->assertGuest('web');
});

it('refuses the native token door for an erased account and mints no token', function (): void {
    User::factory()->creator()->erased()->create(['email' => 'gone@example.com']);

    $this->withHeader('Origin', 'capacitor://localhost')
        ->postJson('/api/v1/auth/token', erasedLoginBody('gone@example.com') + ['platform' => 'ios'])
        ->assertStatus(401)
        ->assertJsonPath('errors.0.code', 'auth.invalid_credentials');

    expect(PersonalAccessToken::query()->count())->toBe(0);
});

it('answers an erased account exactly as it answers an unknown email', function (): void {
    User::factory()->creator()->erased()->create(['email' => 'gone@example.com']);

    $erased = $this->postJson('/api/v1/auth/login', erasedLoginBody('gone@example.com'));
    $unknown = $this->postJson('/api/v1/auth/login', erasedLoginBody('never-existed@example.com'));

    // Both must be a real 401 first: two 500s would also be "equal" and prove nothing.
    expect($unknown->status())->toBe(401)
        ->and($erased->status())->toBe($unknown->status())
        ->and($erased->json('errors.0.code'))->toBe($unknown->json('errors.0.code'))
        ->and($erased->json('errors.0.title'))->toBe($unknown->json('errors.0.title'));
});

it('records the refusal under the same audit reason as an unknown email, and never reveals the marker', function (): void {
    User::factory()->creator()->erased()->create(['email' => 'gone@example.com']);

    Event::fake([LoginFailed::class]);

    $this->postJson('/api/v1/auth/login', erasedLoginBody('gone@example.com'))->assertStatus(401);

    Event::assertDispatched(
        LoginFailed::class,
        static fn (LoginFailed $e): bool => $e->reason === 'unknown_email' && $e->user === null,
    );
});

it('still lets a non-erased creator in on the same door (the control)', function (): void {
    User::factory()->creator()->create(['email' => 'here@example.com']);

    $this->withHeader('Origin', 'capacitor://localhost')
        ->postJson('/api/v1/auth/token', erasedLoginBody('here@example.com') + ['platform' => 'ios'])
        ->assertOk();

    expect(PersonalAccessToken::query()->count())->toBe(1);
});
