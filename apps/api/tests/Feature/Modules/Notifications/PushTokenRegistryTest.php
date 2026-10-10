<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Models\UserPushToken;
use App\Modules\Notifications\Push\PushPlatform;
use App\Modules\Notifications\Push\PushTokenRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| AH-102 C1.3 — user_push_tokens, UserPushToken, PushTokenRegistry
|--------------------------------------------------------------------------
|
| 1. Cascade canary — the FK to personal_access_tokens is ENFORCED under the
|    test database (SQLite) for model deletes, mass deletes and the real
|    logout route, before any other test here leans on it.
| 2. register() — upsert-by-token (moves ownership), unique(PAT)
|    (replace-on-rotation), re-register clears invalidation.
| 3. liveTokensFor() — §5.34: ONE positive and a negative per rule, each
|    eligible in every respect except the one under test.
| 4. invalidate() / revokeAllForUser() — and the over-reach negatives.
*/

function pushRegistry(): PushTokenRegistry
{
    return app(PushTokenRegistry::class);
}

/** A bearer for $user exactly as the creator app login mints it. */
function pushBearer(User $user, string $platform = 'android'): PersonalAccessToken
{
    $accessToken = $user->createToken('creator-app:'.$platform, ['*'])->accessToken;
    assert($accessToken instanceof PersonalAccessToken);

    return $accessToken;
}

/** @return list<string> live token VALUES for the user, in id order */
function pushLiveValues(User $user): array
{
    return array_values(pushRegistry()->liveTokensFor($user)->map(fn (UserPushToken $t): string => $t->token)->all());
}

// =============================================================================
// 1. Cascade canary
// =============================================================================

it('CANARY: deleting the bearer through the model removes its push token (FK cascade is enforced)', function (): void {
    $pushToken = UserPushToken::factory()->create();

    expect(DB::select('PRAGMA foreign_keys')[0]->foreign_keys ?? null)->toBe(1, 'SQLite FK enforcement must be ON');

    PersonalAccessToken::query()->findOrFail($pushToken->personal_access_token_id)->delete();

    expect(UserPushToken::query()->count())->toBe(0);
});

it('CANARY: a MASS bearer delete (no model events — password reset / suspension) removes the push tokens', function (): void {
    $user = User::factory()->creator()->createOne();
    $survivor = UserPushToken::factory()->create();

    UserPushToken::factory()->count(2)->create(['user_id' => $user->id]);
    expect(UserPushToken::query()->where('user_id', $user->id)->count())->toBe(2);

    $user->tokens()->delete();

    expect(UserPushToken::query()->where('user_id', $user->id)->count())->toBe(0)
        ->and(UserPushToken::query()->whereKey($survivor->id)->exists())->toBeTrue('another user\'s token survives');
});

it('CANARY: the real logout route revokes THIS device\'s push token and only this one', function (): void {
    $user = User::factory()->creator()->createOne();
    $phone = $user->createToken('creator-app:android', ['*']);
    $tablet = $user->createToken('creator-app:ios', ['*']);

    pushRegistry()->register($user, $phone->accessToken, PushPlatform::Android, 'phone-token');
    pushRegistry()->register($user, $tablet->accessToken, PushPlatform::Ios, 'tablet-token');

    app('auth')->forgetGuards();
    $this->withHeaders(['Origin' => 'capacitor://localhost', 'Authorization' => 'Bearer '.$phone->plainTextToken])
        ->postJson('/api/v1/auth/logout')
        ->assertStatus(204);

    expect(UserPushToken::query()->pluck('token')->all())->toBe(['tablet-token']);
});

// =============================================================================
// 2. register()
// =============================================================================

it('register creates a row bound to the bearer with platform, version and last_seen_at', function (): void {
    $user = User::factory()->creator()->createOne();
    $bearer = pushBearer($user);

    $row = pushRegistry()->register($user, $bearer, PushPlatform::Ios, 'tok-1', '2.3.4');

    expect($row->user_id)->toBe($user->id)
        ->and($row->personal_access_token_id)->toBe($bearer->id)
        ->and($row->platform)->toBe(PushPlatform::Ios)
        ->and($row->token)->toBe('tok-1')
        ->and($row->app_version)->toBe('2.3.4')
        ->and($row->last_seen_at)->not->toBeNull()
        ->and($row->invalidated_at)->toBeNull();
});

it('register is idempotent for the same (bearer, token): one row, last_seen_at refreshed', function (): void {
    $user = User::factory()->creator()->createOne();
    $bearer = pushBearer($user);

    $this->travelTo(now()->subHour());
    $first = pushRegistry()->register($user, $bearer, PushPlatform::Android, 'tok-1');
    $this->travelBack();
    $second = pushRegistry()->register($user, $bearer, PushPlatform::Android, 'tok-1', '1.1.0');

    expect(UserPushToken::query()->count())->toBe(1)
        ->and($second->id)->toBe($first->id)
        ->and($second->app_version)->toBe('1.1.0')
        ->and($first->last_seen_at)->not->toBeNull()
        ->and($second->last_seen_at)->not->toBeNull();
    expect($second->last_seen_at?->gt($first->last_seen_at ?? now()))->toBeTrue();
});

it('unique(PAT): an FCM token ROTATION on the same login replaces the stored token (still one row)', function (): void {
    $user = User::factory()->creator()->createOne();
    $bearer = pushBearer($user);

    $first = pushRegistry()->register($user, $bearer, PushPlatform::Android, 'old-token');
    $second = pushRegistry()->register($user, $bearer, PushPlatform::Android, 'new-token');

    expect(UserPushToken::query()->count())->toBe(1)
        ->and($second->id)->toBe($first->id)
        ->and(UserPushToken::query()->sole()->token)->toBe('new-token');
});

it('multi-device: two bearers for one user hold two rows', function (): void {
    $user = User::factory()->creator()->createOne();

    pushRegistry()->register($user, pushBearer($user, 'android'), PushPlatform::Android, 'a-token');
    pushRegistry()->register($user, pushBearer($user, 'ios'), PushPlatform::Ios, 'i-token');

    expect(pushLiveValues($user))->toBe(['a-token', 'i-token']);
});

it('upsert-by-token: the same device token under ANOTHER account MOVES the row (shared phone)', function (): void {
    $alice = User::factory()->creator()->createOne();
    $bob = User::factory()->creator()->createOne();

    pushRegistry()->register($alice, pushBearer($alice), PushPlatform::Android, 'shared-handset');
    pushRegistry()->register($bob, pushBearer($bob), PushPlatform::Android, 'shared-handset');

    expect(UserPushToken::query()->count())->toBe(1)
        ->and(pushLiveValues($alice))->toBe([], 'Alice no longer addressable at that handset')
        ->and(pushLiveValues($bob))->toBe(['shared-handset']);
});

it('upsert-by-token over-reach negative: moving one token leaves the owner\'s OTHER device alone', function (): void {
    $alice = User::factory()->creator()->createOne();
    $bob = User::factory()->creator()->createOne();

    pushRegistry()->register($alice, pushBearer($alice, 'android'), PushPlatform::Android, 'alice-phone');
    pushRegistry()->register($alice, pushBearer($alice, 'ios'), PushPlatform::Ios, 'alice-tablet');
    pushRegistry()->register($bob, pushBearer($bob), PushPlatform::Android, 'alice-phone');

    expect(pushLiveValues($alice))->toBe(['alice-tablet'])
        ->and(pushLiveValues($bob))->toBe(['alice-phone']);
});

it('re-registering an invalidated token clears the invalidation (the client just proved it is alive)', function (): void {
    $user = User::factory()->creator()->createOne();
    $bearer = pushBearer($user);

    $row = pushRegistry()->register($user, $bearer, PushPlatform::Android, 'tok-1');
    pushRegistry()->invalidate($row, 'unregistered');
    expect(pushLiveValues($user))->toBe([]);

    pushRegistry()->register($user, $bearer, PushPlatform::Android, 'tok-1');

    $fresh = UserPushToken::query()->sole();
    expect(pushLiveValues($user))->toBe(['tok-1'])
        ->and($fresh->invalidated_at)->toBeNull()
        ->and($fresh->invalidation_reason)->toBeNull();
});

it('the schema itself forbids two rows with one token or one bearer (the constraints are real, not just the upsert)', function (): void {
    $a = UserPushToken::factory()->create(['token' => 'dup']);

    expect(fn () => UserPushToken::factory()->create(['token' => 'dup']))->toThrow(QueryException::class)
        ->and(fn () => UserPushToken::factory()->create([
            'user_id' => $a->user_id,
            'personal_access_token_id' => $a->personal_access_token_id,
        ]))->toThrow(QueryException::class);
});

// =============================================================================
// 3. liveTokensFor() — the §5.34 set
// =============================================================================

it('liveTokensFor returns a live token (the positive control for every negative below)', function (): void {
    $user = User::factory()->creator()->createOne();
    pushRegistry()->register($user, pushBearer($user), PushPlatform::Android, 'live-token');

    expect(pushLiveValues($user))->toBe(['live-token']);
});

it('liveTokensFor excludes an invalidated token', function (): void {
    $user = User::factory()->creator()->createOne();
    UserPushToken::factory()->create(['user_id' => $user->id, 'token' => 'ok']);
    UserPushToken::factory()->invalidated()->create(['user_id' => $user->id, 'token' => 'dead']);

    expect(pushLiveValues($user))->toBe(['ok']);
});

it('liveTokensFor excludes a token whose bearer is expired by created_at + sanctum.expiration', function (): void {
    config(['sanctum.expiration' => 60]);
    $user = User::factory()->creator()->createOne();

    $fresh = UserPushToken::factory()->create(['user_id' => $user->id, 'token' => 'fresh-bearer']);
    $stale = UserPushToken::factory()->create(['user_id' => $user->id, 'token' => 'stale-bearer']);
    PersonalAccessToken::query()->whereKey($stale->personal_access_token_id)->update(['created_at' => now()->subMinutes(61)]);
    PersonalAccessToken::query()->whereKey($fresh->personal_access_token_id)->update(['created_at' => now()->subMinutes(59)]);

    expect(pushLiveValues($user))->toBe(['fresh-bearer']);
});

it('liveTokensFor ignores created_at age when sanctum.expiration is disabled (0/null)', function (): void {
    config(['sanctum.expiration' => 0]);
    $user = User::factory()->creator()->createOne();
    $row = UserPushToken::factory()->create(['user_id' => $user->id, 'token' => 'ancient']);
    PersonalAccessToken::query()->whereKey($row->personal_access_token_id)->update(['created_at' => now()->subYears(5)]);

    expect(pushLiveValues($user))->toBe(['ancient']);
});

it('liveTokensFor excludes a token whose bearer has an explicit expires_at in the past — and keeps a future one', function (): void {
    config(['sanctum.expiration' => 0]);
    $user = User::factory()->creator()->createOne();
    $past = UserPushToken::factory()->create(['user_id' => $user->id, 'token' => 'past']);
    $future = UserPushToken::factory()->create(['user_id' => $user->id, 'token' => 'future']);
    PersonalAccessToken::query()->whereKey($past->personal_access_token_id)->update(['expires_at' => now()->subMinute()]);
    PersonalAccessToken::query()->whereKey($future->personal_access_token_id)->update(['expires_at' => now()->addDay()]);

    expect(pushLiveValues($user))->toBe(['future']);
});

it('liveTokensFor excludes the tokens of a soft-deleted user (soft delete does not fire the FK cascade)', function (): void {
    $gone = User::factory()->creator()->createOne();
    $stays = User::factory()->creator()->createOne();
    UserPushToken::factory()->create(['user_id' => $gone->id, 'token' => 'gone']);
    UserPushToken::factory()->create(['user_id' => $stays->id, 'token' => 'stays']);

    // Soft-delete directly: the model `deleted` event is audited and needs a reason this test does not care about.
    DB::table('users')->where('id', $gone->id)->update(['deleted_at' => now()]);

    expect(UserPushToken::query()->where('user_id', $gone->id)->count())->toBe(1, 'the cascade did NOT fire')
        ->and(pushLiveValues($gone))->toBe([])
        ->and(pushLiveValues($stays))->toBe(['stays']);
});

it('liveTokensFor excludes a token whose bearer was revoked (cascade) — and returns only the asked-for user\'s tokens', function (): void {
    $user = User::factory()->creator()->createOne();
    $other = User::factory()->creator()->createOne();
    $revoked = UserPushToken::factory()->create(['user_id' => $user->id, 'token' => 'revoked']);
    UserPushToken::factory()->create(['user_id' => $user->id, 'token' => 'kept']);
    UserPushToken::factory()->create(['user_id' => $other->id, 'token' => 'others']);

    PersonalAccessToken::query()->whereKey($revoked->personal_access_token_id)->delete();

    expect(pushLiveValues($user))->toBe(['kept']);
});

// =============================================================================
// 4. invalidate() / revokeAllForUser()
// =============================================================================

it('invalidate keeps the row for audit, records the reason, and removes it from the live set', function (): void {
    $user = User::factory()->creator()->createOne();
    $row = UserPushToken::factory()->create(['user_id' => $user->id, 'token' => 'bad']);
    UserPushToken::factory()->create(['user_id' => $user->id, 'token' => 'good']);

    pushRegistry()->invalidate($row, 'fcm_unregistered');

    $fresh = $row->fresh();
    expect($fresh)->not->toBeNull()
        ->and($fresh?->invalidated_at)->not->toBeNull()
        ->and($fresh?->invalidation_reason)->toBe('fcm_unregistered')
        ->and(pushLiveValues($user))->toBe(['good']);
});

it('revokeAllForUser deletes every token the user owns, returns the count, and touches nobody else', function (): void {
    $user = User::factory()->creator()->createOne();
    $other = User::factory()->creator()->createOne();
    UserPushToken::factory()->count(2)->create(['user_id' => $user->id]);
    UserPushToken::factory()->invalidated()->create(['user_id' => $user->id]);
    UserPushToken::factory()->create(['user_id' => $other->id, 'token' => 'others']);

    expect(pushRegistry()->revokeAllForUser($user))->toBe(3)
        ->and(UserPushToken::query()->pluck('token')->all())->toBe(['others'])
        ->and(pushRegistry()->revokeAllForUser($user))->toBe(0);
});

it('the model never serialises the device token', function (): void {
    $row = UserPushToken::factory()->create(['token' => 'secret-ish']);

    expect($row->toArray())->not->toHaveKey('token')
        ->and(json_encode($row))->not->toContain('secret-ish');
});
