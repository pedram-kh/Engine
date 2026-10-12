<?php

declare(strict_types=1);

use App\Modules\Agencies\Database\Factories\AgencyFactory;
use App\Modules\Audit\Database\Factories\AuditLogFactory;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Models\UserPushToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| AH-102b C2.2 — admin creator-app usage
|--------------------------------------------------------------------------
|
|   GET /api/v1/admin/creator-app/users
|   GET /api/v1/admin/creator-app/users/{creator}/sign-ins
|
| Derived views: nothing is written. The state matrix (Active / Signed out /
| Never) is tested with its NEGATIVES — each wrong input that must NOT flip
| a state has its own case.
*/

function cauAdmin(): User
{
    return User::factory()->create([
        'type' => UserType::PlatformAdmin,
        'two_factor_confirmed_at' => now(),
    ]);
}

function cauCreator(string $name = 'Ada Lovelace'): Creator
{
    return CreatorFactory::new()->createOne(['display_name' => $name]);
}

function cauUser(Creator $creator): User
{
    return User::query()->findOrFail($creator->user_id);
}

/**
 * A creator-app sign-in (personal access token). `$age` backdates created_at;
 * `$lastUsed` / `$expiresAt` set those columns.
 */
function cauSignIn(Creator $creator, string $name = 'creator-app:ios', ?Carbon $createdAt = null, ?Carbon $lastUsed = null, ?Carbon $expiresAt = null): PersonalAccessToken
{
    $pat = cauUser($creator)->createToken($name, ['*'])->accessToken;
    assert($pat instanceof PersonalAccessToken);

    $overrides = array_filter(['created_at' => $createdAt, 'last_used_at' => $lastUsed, 'expires_at' => $expiresAt]);
    if ($overrides !== []) {
        PersonalAccessToken::query()->whereKey($pat->getKey())->update($overrides);
    }

    return $pat;
}

function cauAudit(Creator $creator, string $guard = 'creator_app', ?Carbon $at = null): void
{
    AuditLogFactory::new()->create([
        'action' => AuditAction::AuthLoginSucceeded,
        'actor_type' => 'user',
        'actor_id' => $creator->user_id,
        'metadata' => ['guard' => $guard, 'mfa' => false],
        'created_at' => $at ?? now(),
    ]);
}

/** @param  array<string, mixed>  $overrides */
function cauPush(Creator $creator, PersonalAccessToken $pat, array $overrides = []): UserPushToken
{
    return UserPushToken::factory()->createOne(array_merge([
        'user_id' => $creator->user_id,
        'personal_access_token_id' => $pat->getKey(),
    ], $overrides));
}

function cauUrl(string $query = ''): string
{
    return '/api/v1/admin/creator-app/users'.($query !== '' ? '?'.$query : '');
}

/**
 * @param  TestResponse<JsonResponse>  $response
 * @return array<string, array<string, mixed>> attributes keyed by creator ULID
 */
function cauByCreator(TestResponse $response): array
{
    $out = [];
    foreach ($response->json('data') as $row) {
        $out[$row['id']] = $row['attributes'];
    }

    return $out;
}

// ─── Auth negative set (both routes) ─────────────────────────────────────

dataset('creator_app_routes', [
    'list' => [fn (): string => cauUrl()],
    'sign-ins' => [fn (): string => cauUrl().'/'.cauCreator('Route Probe')->ulid.'/sign-ins'],
]);

it('401s an unauthenticated request', function (Closure $url): void {
    expect($this->getJson($url())->status())->toBe(401);
})->with('creator_app_routes');

it('403s a creator and an agency user acting on the admin guard', function (Closure $url, UserType $type): void {
    $user = User::factory()->create(['type' => $type, 'two_factor_confirmed_at' => now()]);

    expect($this->actingAs($user, 'web_admin')->getJson($url())->status())->toBe(403);
})->with('creator_app_routes')->with([UserType::Creator, UserType::AgencyUser]);

it('blocks an admin who has not enrolled 2FA (EnsureMfaForAdmins)', function (Closure $url): void {
    config()->set('auth.admin_mfa_enforced', true);
    $admin = User::factory()->create(['type' => UserType::PlatformAdmin, 'two_factor_confirmed_at' => null]);

    $response = $this->actingAs($admin, 'web_admin')->getJson($url());

    expect($response->status())->toBe(403)
        ->and($response->json('errors.0.code'))->toBe('auth.mfa.enrollment_required');
})->with('creator_app_routes');

it('403s (not 404s) a non-admin probing an unknown creator ULID — no existence oracle', function (): void {
    $user = User::factory()->create(['type' => UserType::AgencyUser, 'two_factor_confirmed_at' => now()]);

    expect($this->actingAs($user, 'web_admin')->getJson(cauUrl().'/01HZZZZZZZZZZZZZZZZZZZZZZZ/sign-ins')->status())->toBe(403);
});

// ─── State derivation matrix ─────────────────────────────────────────────

it('Active: a live creator-app sign-in', function (): void {
    $creator = cauCreator();
    cauSignIn($creator);

    $row = cauByCreator($this->actingAs(cauAdmin(), 'web_admin')->getJson(cauUrl()))[$creator->ulid];

    expect($row['app_status'])->toBe('active')
        ->and($row['live_sign_ins'])->toBe(1);
});

it('Signed out: no live sign-in but an app login was audited', function (): void {
    $creator = cauCreator();
    cauAudit($creator, at: now()->subDays(3));

    $row = cauByCreator($this->actingAs(cauAdmin(), 'web_admin')->getJson(cauUrl()))[$creator->ulid];

    expect($row['app_status'])->toBe('signed_out')
        ->and($row['live_sign_ins'])->toBe(0)
        ->and($row['last_app_sign_in_at'])->not->toBeNull();
});

it('Never: neither a live sign-in nor an audited app login', function (): void {
    $creator = cauCreator();

    $row = cauByCreator($this->actingAs(cauAdmin(), 'web_admin')->getJson(cauUrl()))[$creator->ulid];

    expect($row)->toMatchArray(['app_status' => 'never', 'live_sign_ins' => 0, 'platforms' => [], 'last_seen_at' => null, 'last_app_sign_in_at' => null, 'push_state' => 'none']);
});

it('NEGATIVE: a PAT expired by expires_at is not Active — Signed out with an audit row, Never without', function (): void {
    $withAudit = cauCreator('With audit');
    $without = cauCreator('Without audit');
    cauSignIn($withAudit, expiresAt: now()->subMinute());
    cauSignIn($without, expiresAt: now()->subMinute());
    cauAudit($withAudit);

    $rows = cauByCreator($this->actingAs(cauAdmin(), 'web_admin')->getJson(cauUrl()));

    expect($rows[$withAudit->ulid]['app_status'])->toBe('signed_out')
        ->and($rows[$without->ulid]['app_status'])->toBe('never')
        ->and($rows[$without->ulid]['live_sign_ins'])->toBe(0);
});

it('NEGATIVE: a PAT expired by age (sanctum.expiration) is not Active; one inside the window is', function (): void {
    config(['sanctum.expiration' => 60]);
    $old = cauCreator('Old');
    $fresh = cauCreator('Fresh');
    cauSignIn($old, createdAt: now()->subMinutes(61));
    cauSignIn($fresh, createdAt: now()->subMinutes(59));

    $rows = cauByCreator($this->actingAs(cauAdmin(), 'web_admin')->getJson(cauUrl()));

    expect($rows[$old->ulid]['app_status'])->toBe('never')
        ->and($rows[$fresh->ulid]['app_status'])->toBe('active');
});

it('NEGATIVE: a soft-deleted user (or creator) is not listed at all', function (): void {
    $deletedUser = cauCreator('Deleted user');
    $deletedCreator = cauCreator('Deleted creator');
    $kept = cauCreator('Kept');
    cauSignIn($deletedUser);
    DB::table('users')->where('id', $deletedUser->user_id)->update(['deleted_at' => now()]);
    DB::table('creators')->where('id', $deletedCreator->id)->update(['deleted_at' => now()]);

    $rows = cauByCreator($this->actingAs(cauAdmin(), 'web_admin')->getJson(cauUrl()));

    expect(array_keys($rows))->toBe([$kept->ulid]);
});

it('NEGATIVE: a WEB sign-in audit row does not make a creator "Signed out"', function (): void {
    $creator = cauCreator();
    cauAudit($creator, guard: 'web');

    $row = cauByCreator($this->actingAs(cauAdmin(), 'web_admin')->getJson(cauUrl()))[$creator->ulid];

    expect($row['app_status'])->toBe('never')
        ->and($row['last_app_sign_in_at'])->toBeNull();
});

it('NEGATIVE: another user\'s app login does not leak onto this creator, and non-login audit actions do not count', function (): void {
    $a = cauCreator('A');
    $b = cauCreator('B');
    cauAudit($a);
    AuditLogFactory::new()->create([
        'action' => AuditAction::AuthLogout,
        'actor_type' => 'user',
        'actor_id' => $b->user_id,
        'metadata' => ['guard' => 'creator_app'],
    ]);

    $rows = cauByCreator($this->actingAs(cauAdmin(), 'web_admin')->getJson(cauUrl()));

    expect($rows[$a->ulid]['app_status'])->toBe('signed_out')
        ->and($rows[$b->ulid]['app_status'])->toBe('never');
});

it('NEGATIVE: a non-creator-app token (name) does not count as a sign-in', function (string $name): void {
    $creator = cauCreator();
    cauSignIn($creator, $name);

    $row = cauByCreator($this->actingAs(cauAdmin(), 'web_admin')->getJson(cauUrl()))[$creator->ulid];

    expect($row['app_status'])->toBe('never')
        ->and($row['live_sign_ins'])->toBe(0);
})->with(['admin token' => 'admin-cli', 'lookalike' => 'my-creator-app:ios', 'no prefix' => 'ios']);

it('counts live sign-ins and reports platforms from the name suffix; last_seen is the max over LIVE tokens only', function (): void {
    config(['sanctum.expiration' => 600]);
    $creator = cauCreator();
    cauSignIn($creator, 'creator-app:ios', lastUsed: now()->subHours(5)->startOfSecond());
    cauSignIn($creator, 'creator-app:android', lastUsed: now()->subHours(2)->startOfSecond());
    // An EXPIRED token seen more recently than either live one must not win.
    cauSignIn($creator, 'creator-app:android', lastUsed: now()->subMinute()->startOfSecond(), expiresAt: now()->subSeconds(30));

    $row = cauByCreator($this->actingAs(cauAdmin(), 'web_admin')->getJson(cauUrl()))[$creator->ulid];

    expect($row['live_sign_ins'])->toBe(2)
        ->and($row['platforms'])->toBe(['ios', 'android'])
        ->and(Carbon::parse($row['last_seen_at'])->equalTo(now()->subHours(2)->startOfSecond()))->toBeTrue();
});

// ─── Push state ──────────────────────────────────────────────────────────

it('push_state: registered, dead (+ latest reason), none; app_version from the live rows', function (): void {
    $reg = cauCreator('Registered');
    $dead = cauCreator('Dead');
    $none = cauCreator('None');
    cauPush($reg, cauSignIn($reg), ['app_version' => '2.4.1']);
    $deadPat = cauSignIn($dead);
    cauPush($dead, $deadPat, ['app_version' => '1.9.0', 'invalidated_at' => now(), 'invalidation_reason' => 'provider_unregistered']);
    cauSignIn($none);

    $rows = cauByCreator($this->actingAs(cauAdmin(), 'web_admin')->getJson(cauUrl()));

    expect($rows[$reg->ulid])->toMatchArray(['push_state' => 'registered', 'app_version' => '2.4.1', 'push_invalidation_reason' => null])
        ->and($rows[$dead->ulid])->toMatchArray(['push_state' => 'dead', 'app_version' => '1.9.0', 'push_invalidation_reason' => 'provider_unregistered'])
        ->and($rows[$none->ulid])->toMatchArray(['push_state' => 'none', 'app_version' => null])
        ->and($rows[$reg->ulid]['last_push_registered_at'])->not->toBeNull();
});

it('NEGATIVE: a push row on a NON-live sign-in is ignored (state none, no app_version)', function (): void {
    $creator = cauCreator();
    $expired = cauSignIn($creator, expiresAt: now()->subMinute());
    cauPush($creator, $expired, ['app_version' => '0.0.1']);

    $row = cauByCreator($this->actingAs(cauAdmin(), 'web_admin')->getJson(cauUrl()))[$creator->ulid];

    expect($row)->toMatchArray(['push_state' => 'none', 'app_version' => null, 'last_push_registered_at' => null]);
});

it('one registered device wins over a dead one for the same creator', function (): void {
    $creator = cauCreator();
    cauPush($creator, cauSignIn($creator), ['invalidated_at' => now(), 'invalidation_reason' => 'provider_unregistered']);
    cauPush($creator, cauSignIn($creator, 'creator-app:android'));

    $row = cauByCreator($this->actingAs(cauAdmin(), 'web_admin')->getJson(cauUrl()))[$creator->ulid];

    expect($row['push_state'])->toBe('registered')
        ->and($row['push_invalidation_reason'])->toBeNull();
});

// ─── Filters, search, order, pagination ──────────────────────────────────

it('filters by app_status, platform and push — exactly, and unknown values give an empty page', function (): void {
    $active = cauCreator('Active ios');
    cauPush($active, cauSignIn($active, 'creator-app:ios'));
    $android = cauCreator('Active android');
    cauSignIn($android, 'creator-app:android');
    $signedOut = cauCreator('Signed out');
    cauAudit($signedOut);
    $never = cauCreator('Never');
    $deadOne = cauCreator('Dead push');
    cauPush($deadOne, cauSignIn($deadOne, 'creator-app:ios'), ['invalidated_at' => now(), 'invalidation_reason' => 'x']);

    $ids = fn (string $q): array => array_keys(cauByCreator($this->actingAs(cauAdmin(), 'web_admin')->getJson(cauUrl($q))));

    expect($ids('app_status=active'))->toEqualCanonicalizing([$active->ulid, $android->ulid, $deadOne->ulid])
        ->and($ids('app_status=signed_out'))->toBe([$signedOut->ulid])
        ->and($ids('app_status=never'))->toBe([$never->ulid])
        ->and($ids('platform=android'))->toBe([$android->ulid])
        ->and($ids('platform=ios'))->toEqualCanonicalizing([$active->ulid, $deadOne->ulid])
        ->and($ids('push=registered'))->toBe([$active->ulid])
        ->and($ids('push=dead'))->toBe([$deadOne->ulid])
        ->and($ids('push=none'))->toEqualCanonicalizing([$android->ulid, $signedOut->ulid, $never->ulid])
        ->and($ids('app_status=active&platform=ios&push=registered'))->toBe([$active->ulid])
        ->and($ids('app_status=bogus'))->toBe([])
        ->and($ids('platform=windows'))->toBe([])
        ->and($ids('push=maybe'))->toBe([]);
});

it('searches email and display name, case-insensitively', function (): void {
    $ada = cauCreator('Ada Lovelace');
    $bob = cauCreator('Bob Builder');
    DB::table('users')->where('id', $bob->user_id)->update(['email' => 'zed.unique@example.test']);

    $ids = fn (string $q): array => array_keys(cauByCreator($this->actingAs(cauAdmin(), 'web_admin')->getJson(cauUrl($q))));

    expect($ids('search=lovelace'))->toBe([$ada->ulid])
        ->and($ids('search=ZED.UNIQUE'))->toBe([$bob->ulid])
        ->and($ids('search=nobody-matches-this'))->toBe([]);
});

it('orders by last seen desc, then last app sign-in desc, then id desc, nulls last', function (): void {
    config(['sanctum.expiration' => 100000]);
    $seenRecent = cauCreator('Seen recent');
    cauSignIn($seenRecent, lastUsed: now()->subHour()->startOfSecond());
    $seenOld = cauCreator('Seen old');
    cauSignIn($seenOld, lastUsed: now()->subDays(2)->startOfSecond());
    $audited2d = cauCreator('Audited 2d');
    cauAudit($audited2d, at: now()->subDays(2));
    $audited9d = cauCreator('Audited 9d');
    cauAudit($audited9d, at: now()->subDays(9));
    $neverA = cauCreator('Never A');
    $neverB = cauCreator('Never B');

    $response = $this->actingAs(cauAdmin(), 'web_admin')->getJson(cauUrl());

    expect(array_keys(cauByCreator($response)))->toBe([
        $seenRecent->ulid, $seenOld->ulid, $audited2d->ulid, $audited9d->ulid, $neverB->ulid, $neverA->ulid,
    ]);
});

it('paginates with total / page / per_page / last_page and does not repeat or drop a creator across pages', function (): void {
    $made = [];
    for ($i = 0; $i < 5; $i++) {
        $made[] = cauCreator('C'.$i)->ulid;
    }
    $admin = cauAdmin();

    $seen = [];
    foreach ([1, 2, 3] as $page) {
        $response = $this->actingAs($admin, 'web_admin')->getJson(cauUrl("per_page=2&page={$page}"));
        $seen = array_merge($seen, array_keys(cauByCreator($response)));
    }

    expect($seen)->toEqualCanonicalizing($made)
        ->and(array_unique($seen))->toHaveCount(5)
        ->and($response->json('meta'))->toBe(['total' => 5, 'page' => 3, 'per_page' => 2, 'last_page' => 3]);
});

it('exposes an exact allow-listed attribute set', function (): void {
    $creator = cauCreator();
    cauSignIn($creator);

    $data = $this->actingAs(cauAdmin(), 'web_admin')->getJson(cauUrl())->json('data.0');

    expect($data['type'])->toBe('creator_app_users')
        ->and(array_keys($data['attributes']))->toBe([
            'creator', 'app_status', 'live_sign_ins', 'platforms', 'last_seen_at', 'last_app_sign_in_at',
            'app_version', 'push_state', 'push_invalidation_reason', 'last_push_registered_at',
        ])
        ->and(array_keys($data['attributes']['creator']))->toBe(['id', 'display_name', 'email']);
});

it('resolves a page without N+1: 12 creators cost the same queries as 1', function (): void {
    $admin = cauAdmin();
    $count = function () use ($admin): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin, 'web_admin')->getJson(cauUrl('per_page=50'))->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    $first = cauCreator();
    cauPush($first, cauSignIn($first));
    $one = $count();
    for ($i = 0; $i < 11; $i++) {
        $c = cauCreator('N'.$i);
        cauPush($c, cauSignIn($c));
    }

    expect($count())->toBe($one);
});

// ─── Sign-ins drawer ─────────────────────────────────────────────────────

function cauSignInsUrl(Creator $creator): string
{
    return cauUrl().'/'.$creator->ulid.'/sign-ins';
}

it('sign-ins: newest first, per-row state live/expired, platform, push, and "no app_version" is null', function (): void {
    $creator = cauCreator();
    $expired = cauSignIn($creator, 'creator-app:android', createdAt: now()->subDays(3), expiresAt: now()->subDay());
    cauSignIn($creator, 'creator-app:ios', createdAt: now()->subDays(2));
    $liveWithPush = cauSignIn($creator, 'creator-app:android', createdAt: now()->subDay());
    cauPush($creator, $liveWithPush, ['app_version' => '3.1.0']);
    cauPush($creator, $expired, ['app_version' => '2.0.0']);

    $response = $this->actingAs(cauAdmin(), 'web_admin')->getJson(cauSignInsUrl($creator));

    expect($response->status())->toBe(200)
        ->and($response->json('meta'))->toBe(['total' => 3, 'limit' => 50])
        ->and(array_column($response->json('data'), 'state'))->toBe(['live', 'live', 'expired'])
        ->and(array_column($response->json('data'), 'platform'))->toBe(['android', 'ios', 'android'])
        ->and(array_column($response->json('data'), 'push_state'))->toBe(['registered', 'none', 'none'])
        // the sign-in that never registered for push → null (the SPA shows "—")
        ->and($response->json('data.1.app_version'))->toBeNull()
        ->and($response->json('data.0.app_version'))->toBe('3.1.0')
        // history is kept for the expired sign-in even though it can't receive a push now
        ->and($response->json('data.2.app_version'))->toBe('2.0.0')
        ->and(array_keys($response->json('data.0')))->toBe([
            'platform', 'signed_in_at', 'last_seen_at', 'state', 'expires_at', 'app_version', 'push_state', 'push_registered_at',
        ]);
});

it('sign-ins: a dead device reads dead', function (): void {
    $creator = cauCreator();
    cauPush($creator, cauSignIn($creator), ['invalidated_at' => now(), 'invalidation_reason' => 'provider_unregistered']);

    expect($this->actingAs(cauAdmin(), 'web_admin')->getJson(cauSignInsUrl($creator))->json('data.0.push_state'))->toBe('dead');
});

it('sign-ins: capped at 50 rows with meta.total carrying the real count', function (): void {
    $creator = cauCreator();
    $user = cauUser($creator);
    $rows = [];
    for ($i = 0; $i < 55; $i++) {
        $rows[] = [
            'tokenable_type' => User::class, 'tokenable_id' => $user->id, 'name' => 'creator-app:ios',
            'token' => hash('sha256', 'cap-'.$i), 'abilities' => '["*"]', 'created_at' => now()->subMinutes($i), 'updated_at' => now(),
        ];
    }
    DB::table('personal_access_tokens')->insert($rows);

    $response = $this->actingAs(cauAdmin(), 'web_admin')->getJson(cauSignInsUrl($creator));

    expect($response->json('data'))->toHaveCount(50)
        ->and($response->json('meta'))->toBe(['total' => 55, 'limit' => 50]);
});

it('sign-ins: lists only THIS creator\'s creator-app tokens', function (): void {
    $mine = cauCreator('Mine');
    $other = cauCreator('Other');
    cauSignIn($mine);
    cauSignIn($other);
    cauSignIn($other, 'creator-app:android');
    cauSignIn($mine, 'admin-cli');

    expect($this->actingAs(cauAdmin(), 'web_admin')->getJson(cauSignInsUrl($mine))->json('meta.total'))->toBe(1);
});

it('sign-ins: 404 for an unknown ULID, a ULID of another resource type, a soft-deleted creator and a soft-deleted user', function (): void {
    $admin = cauAdmin();
    $agency = AgencyFactory::new()->createOne();
    $deletedCreator = cauCreator('Gone creator');
    DB::table('creators')->where('id', $deletedCreator->id)->update(['deleted_at' => now()]);
    $deletedUser = cauCreator('Gone user');
    DB::table('users')->where('id', $deletedUser->user_id)->update(['deleted_at' => now()]);

    foreach (['01HZZZZZZZZZZZZZZZZZZZZZZZ', $agency->ulid, $admin->ulid ?? 'x', $deletedCreator->ulid, $deletedUser->ulid] as $ulid) {
        expect($this->actingAs($admin, 'web_admin')->getJson(cauUrl().'/'.$ulid.'/sign-ins')->status())->toBe(404);
    }
});

// ─── Privacy ─────────────────────────────────────────────────────────────

it('PRIVACY: no token, hash, PAT id, FCM token, IP or user-agent anywhere in either response', function (): void {
    $creator = cauCreator();
    $pat = cauSignIn($creator);
    $plain = (string) cauUser($creator)->createToken('creator-app:android', ['*'])->plainTextToken;
    cauPush($creator, $pat, ['token' => 'fcm-SECRET-DEVICE-TOKEN-'.str_repeat('y', 100)]);
    AuditLogFactory::new()->create([
        'action' => AuditAction::AuthLoginSucceeded,
        'actor_type' => 'user',
        'actor_id' => $creator->user_id,
        'metadata' => ['guard' => 'creator_app'],
        'ip' => '203.0.113.99',
        'user_agent' => 'SECRET-UA/9.9',
    ]);
    $hash = (string) PersonalAccessToken::query()->whereKey($pat->getKey())->value('token');

    $admin = cauAdmin();
    $bodies = [
        $this->actingAs($admin, 'web_admin')->getJson(cauUrl())->getContent(),
        $this->actingAs($admin, 'web_admin')->getJson(cauUrl().'/'.$creator->ulid.'/sign-ins')->getContent(),
    ];

    $forbiddenKeys = ['token', 'token_hash', 'plainTextToken', 'ip', 'user_agent', 'last_login_ip', 'personal_access_token_id', 'tokenable_id'];
    $walk = function (mixed $node) use (&$walk, $forbiddenKeys): void {
        if (! is_array($node)) {
            return;
        }
        foreach ($node as $key => $value) {
            expect($forbiddenKeys)->not->toContain($key);
            $walk($value);
        }
    };

    foreach ($bodies as $body) {
        foreach (['SECRET-DEVICE-TOKEN', 'SECRET-UA', '203.0.113.99', $plain, $hash, explode('|', $plain)[1] ?? $plain] as $secret) {
            expect($body)->not->toContain($secret);
        }
        $walk(json_decode($body, true));
    }
});

it('sign-ins carries NO row id (P-7): no id key and no PAT reference', function (): void {
    $creator = cauCreator();
    cauSignIn($creator);

    $response = $this->actingAs(cauAdmin(), 'web_admin')->getJson(cauSignInsUrl($creator));

    expect($response->json('data.0'))->not->toHaveKey('id')
        ->and($response->json('data.0'))->not->toHaveKey('personal_access_token_id');
});

it('is read-only: both endpoints write nothing (no audit rows, no token touch)', function (): void {
    $creator = cauCreator();
    $pat = cauSignIn($creator);
    $admin = cauAdmin();
    $auditBefore = DB::table('audit_logs')->count();
    $lastUsedBefore = PersonalAccessToken::query()->whereKey($pat->getKey())->value('last_used_at');

    $this->actingAs($admin, 'web_admin')->getJson(cauUrl())->assertOk();
    $this->actingAs($admin, 'web_admin')->getJson(cauUrl().'/'.$creator->ulid.'/sign-ins')->assertOk();

    expect(DB::table('audit_logs')->count())->toBe($auditBefore)
        ->and(PersonalAccessToken::query()->whereKey($pat->getKey())->value('last_used_at'))->toBe($lastUsedBefore);
});
