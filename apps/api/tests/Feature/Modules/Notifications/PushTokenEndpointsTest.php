<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Models\UserPushToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| AH-102 C1.4 — POST/DELETE /api/v1/me/push-tokens (creator_app bearer ONLY)
|--------------------------------------------------------------------------
|
| Both polarities on the guard: a creator bearer works; a cookie session, an
| agency/admin bearer, a suspended creator, and no credentials do not.
| Validation negatives, own-token-only delete, the throttle, and the pin that
| these two are the ONLY bare `auth:creator_app` routes in the tree.
*/

beforeEach(function (): void {
    Cache::flush();
});

/** @return array{0: User, 1: string, 2: PersonalAccessToken} */
function ptCreator(string $platform = 'android'): array
{
    $user = User::factory()->creator()->createOne();
    $new = $user->createToken('creator-app:'.$platform, ['*']);
    $pat = $new->accessToken;
    assert($pat instanceof PersonalAccessToken);

    return [$user, $new->plainTextToken, $pat];
}

function ptAs(mixed $test, string $plain): mixed
{
    app('auth')->forgetGuards(); // a real request is a fresh app (RequestGuard memoises the user)

    return $test->withHeaders(['Origin' => 'capacitor://localhost', 'Authorization' => 'Bearer '.$plain]);
}

/**
 * The field names the house 422 envelope points at (`errors[].source.pointer`
 * ends in the field name).
 *
 * @param  TestResponse<JsonResponse>  $response
 * @return list<string>
 */
function ptErrorFields(TestResponse $response): array
{
    /** @var list<array{source?: array{pointer?: string}}> $errors */
    $errors = $response->json('errors') ?? [];

    return array_values(array_map(
        fn (array $e): string => basename((string) ($e['source']['pointer'] ?? '')),
        $errors,
    ));
}

/** @return array<string, mixed> */
function ptBody(array $overrides = []): array
{
    return array_merge(['platform' => 'android', 'token' => 'fcm-device-token-1', 'app_version' => '1.0.0'], $overrides);
}

// =============================================================================
// POST — register
// =============================================================================

it('POST registers the device under the calling bearer → 204 and a row bound to that user and that PAT', function (): void {
    [$user, $plain, $pat] = ptCreator();

    ptAs($this, $plain)->postJson('/api/v1/me/push-tokens', ptBody())->assertNoContent();

    $row = UserPushToken::query()->sole();
    expect($row->user_id)->toBe($user->id)
        ->and($row->personal_access_token_id)->toBe($pat->id)
        ->and($row->token)->toBe('fcm-device-token-1')
        ->and($row->platform->value)->toBe('android')
        ->and($row->app_version)->toBe('1.0.0');
});

it('POST is an idempotent refresh: the same body twice is one row; a rotated token replaces it', function (): void {
    [, $plain] = ptCreator();

    ptAs($this, $plain)->postJson('/api/v1/me/push-tokens', ptBody())->assertNoContent();
    ptAs($this, $plain)->postJson('/api/v1/me/push-tokens', ptBody())->assertNoContent();
    expect(UserPushToken::query()->count())->toBe(1);

    ptAs($this, $plain)->postJson('/api/v1/me/push-tokens', ptBody(['token' => 'rotated']))->assertNoContent();
    expect(UserPushToken::query()->sole()->token)->toBe('rotated');
});

it('POST ignores any user id in the body: the owner is always the authenticated creator', function (): void {
    [$user, $plain] = ptCreator();
    $victim = User::factory()->creator()->createOne();

    ptAs($this, $plain)->postJson('/api/v1/me/push-tokens', ptBody(['user_id' => $victim->id, 'user' => $victim->ulid]))->assertNoContent();

    expect(UserPushToken::query()->sole()->user_id)->toBe($user->id);
});

it('POST works for ios too, and does NOT depend on the push_notifications_enabled flag (registration is ungated, Q12)', function (): void {
    config(['features.push_notifications_enabled' => false]);
    [, $plain] = ptCreator('ios');

    ptAs($this, $plain)->postJson('/api/v1/me/push-tokens', ptBody(['platform' => 'ios']))->assertNoContent();

    expect(UserPushToken::query()->sole()->platform->value)->toBe('ios');
});

it('POST an already-registered handset under a second account moves it (shared phone), end to end', function (): void {
    [, $alicePlain] = ptCreator();
    [$bob, $bobPlain] = ptCreator();

    ptAs($this, $alicePlain)->postJson('/api/v1/me/push-tokens', ptBody())->assertNoContent();
    ptAs($this, $bobPlain)->postJson('/api/v1/me/push-tokens', ptBody())->assertNoContent();

    expect(UserPushToken::query()->sole()->user_id)->toBe($bob->id);
});

// ---- guard negatives -------------------------------------------------------

it('401s an unauthenticated request', function (): void {
    $this->postJson('/api/v1/me/push-tokens', ptBody())->assertStatus(401);
    $this->deleteJson('/api/v1/me/push-tokens', ['token' => 'x'])->assertStatus(401);
});

it('401s a creator COOKIE session — the bearer-only guard has nothing to bind a device to', function (): void {
    $creator = User::factory()->creator()->createOne();

    $this->actingAs($creator, 'web')->postJson('/api/v1/me/push-tokens', ptBody())->assertStatus(401);
    app('auth')->forgetGuards();
    $this->actingAs($creator, 'web')->deleteJson('/api/v1/me/push-tokens', ['token' => 'x'])->assertStatus(401);

    expect(UserPushToken::query()->count())->toBe(0);
});

it('401s an AGENCY user\'s bearer and a PLATFORM ADMIN\'s bearer (the guard accepts creators only)', function (): void {
    foreach ([User::factory()->agencyAdmin()->createOne(), User::factory()->platformAdmin()->createOne()] as $outsider) {
        $plain = $outsider->createToken('creator-app:android', ['*'])->plainTextToken;

        ptAs($this, $plain)->postJson('/api/v1/me/push-tokens', ptBody())->assertStatus(401);
    }

    expect(UserPushToken::query()->count())->toBe(0);
});

it('401s a web_admin SESSION', function (): void {
    $admin = User::factory()->platformAdmin()->createOne();

    $this->actingAs($admin, 'web_admin')->postJson('/api/v1/me/push-tokens', ptBody())->assertStatus(401);
});

it('401s a revoked bearer', function (): void {
    [, $plain, $pat] = ptCreator();
    $pat->delete();

    ptAs($this, $plain)->postJson('/api/v1/me/push-tokens', ptBody())->assertStatus(401);
});

// ---- validation negatives --------------------------------------------------

it('422s each invalid POST body, naming the field, and writes nothing', function (array $body, string $field): void {
    [, $plain] = ptCreator();

    $response = ptAs($this, $plain)->postJson('/api/v1/me/push-tokens', $body)->assertStatus(422);

    expect(ptErrorFields($response))->toContain($field);

    expect(UserPushToken::query()->count())->toBe(0);
})->with([
    'missing platform' => [['token' => 't'], 'platform'],
    'unknown platform' => [['platform' => 'web', 'token' => 't'], 'platform'],
    'missing token' => [['platform' => 'android'], 'token'],
    'empty token' => [['platform' => 'android', 'token' => ''], 'token'],
    'token over 512' => [['platform' => 'android', 'token' => str_repeat('a', 513)], 'token'],
    'non-string token' => [['platform' => 'android', 'token' => ['x']], 'token'],
    'app_version over 32' => [['platform' => 'android', 'token' => 't', 'app_version' => str_repeat('1', 33)], 'app_version'],
]);

it('accepts a 512-char token (the boundary) and a null app_version', function (): void {
    [, $plain] = ptCreator();

    ptAs($this, $plain)->postJson('/api/v1/me/push-tokens', ptBody(['token' => str_repeat('a', 512), 'app_version' => null]))->assertNoContent();

    expect(UserPushToken::query()->sole()->app_version)->toBeNull();
});

// =============================================================================
// DELETE — forget own token
// =============================================================================

it('DELETE removes the caller\'s own token → 204', function (): void {
    [, $plain] = ptCreator();
    ptAs($this, $plain)->postJson('/api/v1/me/push-tokens', ptBody())->assertNoContent();

    ptAs($this, $plain)->deleteJson('/api/v1/me/push-tokens', ['token' => 'fcm-device-token-1'])->assertNoContent();

    expect(UserPushToken::query()->count())->toBe(0);
});

it('DELETE of SOMEONE ELSE\'s token is a 204 no-op (same answer as a miss — no oracle) and the row survives', function (): void {
    [, $alicePlain] = ptCreator();
    [, $bobPlain] = ptCreator();
    ptAs($this, $alicePlain)->postJson('/api/v1/me/push-tokens', ptBody(['token' => 'alices']))->assertNoContent();

    ptAs($this, $bobPlain)->deleteJson('/api/v1/me/push-tokens', ['token' => 'alices'])->assertNoContent();
    ptAs($this, $bobPlain)->deleteJson('/api/v1/me/push-tokens', ['token' => 'never-registered'])->assertNoContent();

    expect(UserPushToken::query()->pluck('token')->all())->toBe(['alices']);
});

it('DELETE of an unknown token is a 204, and deletes only the named token (the caller\'s other device survives)', function (): void {
    $user = User::factory()->creator()->createOne();
    $phone = $user->createToken('creator-app:android', ['*']);
    $tablet = $user->createToken('creator-app:ios', ['*']);
    ptAs($this, $phone->plainTextToken)->postJson('/api/v1/me/push-tokens', ptBody(['token' => 'phone']))->assertNoContent();
    ptAs($this, $tablet->plainTextToken)->postJson('/api/v1/me/push-tokens', ptBody(['platform' => 'ios', 'token' => 'tablet']))->assertNoContent();

    ptAs($this, $phone->plainTextToken)->deleteJson('/api/v1/me/push-tokens', ['token' => 'phone'])->assertNoContent();

    expect(UserPushToken::query()->pluck('token')->all())->toBe(['tablet']);
});

it('422s a DELETE without a token', function (): void {
    [, $plain] = ptCreator();

    $response = ptAs($this, $plain)->deleteJson('/api/v1/me/push-tokens', [])->assertStatus(422);

    expect(ptErrorFields($response))->toContain('token');
});

// =============================================================================
// Throttle
// =============================================================================

it('throttles at 30/min: the 31st request in a minute is 429, the 30th is not', function (): void {
    [, $plain] = ptCreator();

    for ($i = 1; $i <= 30; $i++) {
        ptAs($this, $plain)->postJson('/api/v1/me/push-tokens', ptBody(['token' => "t-$i"]))->assertNoContent();
    }

    ptAs($this, $plain)->postJson('/api/v1/me/push-tokens', ptBody(['token' => 't-31']))->assertStatus(429);
});

// =============================================================================
// Route pins
// =============================================================================

it('PIN: exactly these two routes — and no others — carry bare auth:creator_app, with tenancy.set and throttle:30,1', function (): void {
    $bare = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $middleware = array_values(array_filter($route->gatherMiddleware(), 'is_string'));

        if (! in_array('auth:creator_app', $middleware, true)) {
            continue;
        }

        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $bare[$method.' '.$route->uri()] = $middleware;
        }
    }

    ksort($bare);

    expect(array_keys($bare))->toBe(['DELETE api/v1/me/push-tokens', 'POST api/v1/me/push-tokens']);

    foreach ($bare as $middleware) {
        expect($middleware)->toContain('tenancy.set')
            ->and($middleware)->toContain('throttle:30,1')
            ->and($middleware)->not->toContain('auth:web')
            ->and($middleware)->not->toContain('auth:web,creator_app');
    }
});
