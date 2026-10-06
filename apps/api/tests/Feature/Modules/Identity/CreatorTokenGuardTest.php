<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| AH-099 S1 — CreatorTokenGuard (the `creator_app` guard)
|--------------------------------------------------------------------------
|
| Exercised through an ephemeral route mounted EXACTLY as the creator-
| reachable routes are mounted after S3: `auth:web,creator_app`. Bearer
| requests carry the app's non-stateful origin, as the Capacitor shell's do.
|
*/

beforeEach(function (): void {
    Route::middleware(['api', 'auth:web,creator_app'])->get('api/v1/_test/whoami', function (Request $request) {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'id' => $user->ulid,
            'default_guard' => auth()->getDefaultDriver(),
            'has_access_token' => $user->currentAccessToken() !== null,
        ]);
    });
});

function appBearer(User $user, string $name = 'creator-app:ios'): string
{
    return $user->createToken($name, ['*'])->plainTextToken;
}

function bearerGet(mixed $test, string $plain)
{
    // RequestGuard memoises the resolved user for the life of the app, and a
    // Pest test reuses one app across its requests. A real request is a fresh
    // app, so drop the memo to make each call behave like one.
    app('auth')->forgetGuards();

    return $test->withHeaders([
        'Origin' => 'capacitor://localhost',
        'Authorization' => 'Bearer '.$plain,
    ])->getJson('/api/v1/_test/whoami');
}

it('authenticates a valid creator token and resolves it under the creator_app guard', function (): void {
    $creator = User::factory()->creator()->createOne();

    $response = bearerGet($this, appBearer($creator));

    $response->assertOk()
        ->assertJsonPath('id', $creator->ulid)
        ->assertJsonPath('default_guard', 'creator_app')
        ->assertJsonPath('has_access_token', true);
});

it('stamps last_used_at on a bearer request (named: a write per request)', function (): void {
    $creator = User::factory()->creator()->createOne();
    $plain = appBearer($creator);

    expect(PersonalAccessToken::query()->firstOrFail()->last_used_at)->toBeNull();

    bearerGet($this, $plain)->assertOk();

    expect(PersonalAccessToken::query()->firstOrFail()->last_used_at)->not->toBeNull();
});

it('401s with no credentials, a garbage token, and a malformed token', function (): void {
    User::factory()->creator()->createOne();

    $this->withHeader('Origin', 'capacitor://localhost')
        ->getJson('/api/v1/_test/whoami')->assertStatus(401);

    bearerGet($this, '999|not-a-real-token')->assertStatus(401);
    bearerGet($this, 'no-pipe-garbage')->assertStatus(401);
});

it('401s once the token row is revoked', function (): void {
    $creator = User::factory()->creator()->createOne();
    $plain = appBearer($creator);

    bearerGet($this, $plain)->assertOk();

    $creator->tokens()->delete();

    bearerGet($this, $plain)->assertStatus(401);
});

it('enforces the global 90-day expiry ceiling at use time (time-travel)', function (): void {
    expect(config('sanctum.expiration'))->toBe(129600);

    Carbon::setTestNow('2026-01-01 12:00:00');
    $creator = User::factory()->creator()->createOne();
    $plain = appBearer($creator);

    Carbon::setTestNow('2026-03-31 11:59:00'); // 89d 23h 59m
    bearerGet($this, $plain)->assertOk();

    Carbon::setTestNow('2026-04-01 12:01:00'); // 90d + 1 min
    bearerGet($this, $plain)->assertStatus(401);
});

it('lowering the expiry config retroactively expires older tokens (the operator lever)', function (): void {
    Carbon::setTestNow('2026-01-01 12:00:00');
    $creator = User::factory()->creator()->createOne();
    $plain = appBearer($creator);

    Carbon::setTestNow('2026-01-11 12:00:00'); // 10 days old
    bearerGet($this, $plain)->assertOk();

    // bearerGet() rebuilds the guard per call, so it re-reads the lowered ceiling.
    config()->set('sanctum.expiration', 60 * 24 * 5); // 5 days

    bearerGet($this, $plain)->assertStatus(401);
});

// -----------------------------------------------------------------------------
// Use-time identity checks (Q2) — defence in depth behind D3's revocation
// -----------------------------------------------------------------------------

it('refuses a SUSPENDED creator even though the token row is still present (use-time backstop)', function (): void {
    $creator = User::factory()->creator()->createOne();
    $plain = appBearer($creator);

    bearerGet($this, $plain)->assertOk();

    // Suspend WITHOUT revoking — simulates a future suspend site that forgets D3.
    $creator->forceFill(['is_suspended' => true, 'suspended_at' => now()])->saveQuietly();
    expect(PersonalAccessToken::query()->count())->toBe(1);

    bearerGet($this, $plain)->assertStatus(401);
});

it('refuses a hand-minted token belonging to an AGENCY user', function (): void {
    $agencyUser = User::factory()->agencyAdmin()->createOne();

    bearerGet($this, appBearer($agencyUser))->assertStatus(401);
});

it('refuses a hand-minted token belonging to a PLATFORM ADMIN', function (): void {
    $admin = User::factory()->platformAdmin()->createOne();

    bearerGet($this, appBearer($admin))->assertStatus(401);
});

// -----------------------------------------------------------------------------
// The widening hazard — guard-level pins (the route-level pins land in S3)
// -----------------------------------------------------------------------------

it('401s a web_admin SESSION on a route mounted auth:web,creator_app (the hazard, pinned)', function (): void {
    $admin = User::factory()->platformAdmin()->createOne();

    $this->actingAs($admin, 'web_admin')
        ->getJson('/api/v1/_test/whoami')
        ->assertStatus(401);
});

it('serves a creator COOKIE session under the web guard with no access token attached (web untouched)', function (): void {
    $creator = User::factory()->creator()->createOne();

    $this->actingAs($creator, 'web')
        ->getJson('/api/v1/_test/whoami')
        ->assertOk()
        ->assertJsonPath('id', $creator->ulid)
        ->assertJsonPath('default_guard', 'web')
        ->assertJsonPath('has_access_token', false);
});
