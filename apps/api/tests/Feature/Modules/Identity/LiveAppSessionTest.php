<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\LiveAppSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| AH-102b C0.1 — LiveAppSession, the shared "is this sign-in live?" predicate
|--------------------------------------------------------------------------
|
| Extracted from PushTokenRegistry::liveQuery(). These tests drive the scope
| over PLAIN personal-access-token queries — no push token exists anywhere —
| which is exactly how the admin creator-app usage views will use it.
| §5.34: one positive control, then one negative per rule, each eligible in
| every respect except the one under test.
|
| The behaviour-identical proof for the push path is PushTokenRegistryTest
| passing UNEDITED; this file proves the scope on its own.
*/

/** A PAT for $user, optionally with its created_at / expires_at overridden. */
function liveSessionPat(User $user, string $name = 'creator-app:ios', ?Carbon $createdAt = null, ?Carbon $expiresAt = null): PersonalAccessToken
{
    $pat = $user->createToken($name, ['*'])->accessToken;
    assert($pat instanceof PersonalAccessToken);

    $overrides = array_filter([
        'created_at' => $createdAt,
        'expires_at' => $expiresAt,
    ]);
    if ($overrides !== []) {
        PersonalAccessToken::query()->whereKey($pat->getKey())->update($overrides);
    }

    return $pat;
}

/** @return list<int> the live PAT ids, via the shared scope over a joined Eloquent query */
function liveSessionIds(): array
{
    $query = PersonalAccessToken::query()
        ->join('users', function ($join): void {
            $join->on('users.id', '=', 'personal_access_tokens.tokenable_id')
                ->where('personal_access_tokens.tokenable_type', '=', (new User)->getMorphClass());
        });

    /** @var list<int> $ids */
    $ids = LiveAppSession::constrain($query)
        ->orderBy('personal_access_tokens.id')
        ->pluck('personal_access_tokens.id')
        ->map(fn ($id): int => (int) $id)
        ->all();

    return $ids;
}

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-12 12:00:00'));
    config(['sanctum.expiration' => 60]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('includes a live sign-in (the positive control for every negative below)', function (): void {
    $user = User::factory()->creator()->createOne();
    $pat = liveSessionPat($user);

    expect(liveSessionIds())->toBe([$pat->getKey()]);
});

it('excludes a PAT expired by created_at + sanctum.expiration, and keeps one inside the window', function (): void {
    $user = User::factory()->creator()->createOne();
    $stale = liveSessionPat($user, createdAt: now()->subMinutes(61));
    $fresh = liveSessionPat($user, createdAt: now()->subMinutes(59));

    expect(liveSessionIds())->toBe([$fresh->getKey()])
        ->and(liveSessionIds())->not->toContain($stale->getKey());
});

it('treats a PAT created exactly expiration minutes ago as expired (strict boundary)', function (): void {
    $user = User::factory()->creator()->createOne();
    $edge = liveSessionPat($user, createdAt: now()->subMinutes(60));
    $inside = liveSessionPat($user, createdAt: now()->subMinutes(60)->addSecond());

    expect(liveSessionIds())->toBe([$inside->getKey()])
        ->and(liveSessionIds())->not->toContain($edge->getKey());
});

it('ignores created_at age when sanctum.expiration is 0 or null', function (): void {
    $user = User::factory()->creator()->createOne();
    $ancient = liveSessionPat($user, createdAt: now()->subYears(5));

    config(['sanctum.expiration' => 0]);
    expect(liveSessionIds())->toBe([$ancient->getKey()]);

    config(['sanctum.expiration' => null]);
    expect(liveSessionIds())->toBe([$ancient->getKey()]);
});

it('excludes a PAT whose explicit expires_at has passed (now is excluded, one second ahead is included, null is included)', function (): void {
    config(['sanctum.expiration' => 0]);
    $user = User::factory()->creator()->createOne();
    $past = liveSessionPat($user, expiresAt: now()->subMinute());
    $atNow = liveSessionPat($user, expiresAt: now());
    $ahead = liveSessionPat($user, expiresAt: now()->addSecond());
    $never = liveSessionPat($user);

    expect(liveSessionIds())->toBe([$ahead->getKey(), $never->getKey()])
        ->and(liveSessionIds())->not->toContain($past->getKey())
        ->and(liveSessionIds())->not->toContain($atNow->getKey());
});

it('excludes the PATs of a soft-deleted user (soft delete does not fire the FK cascade) and keeps everyone else', function (): void {
    $gone = User::factory()->creator()->createOne();
    $stays = User::factory()->creator()->createOne();
    $gonePat = liveSessionPat($gone);
    $staysPat = liveSessionPat($stays);

    // Direct update: the model `deleted` event is audited and needs a reason this test does not care about.
    DB::table('users')->where('id', $gone->id)->update(['deleted_at' => now()]);

    expect(PersonalAccessToken::query()->whereKey($gonePat->getKey())->exists())->toBeTrue('the PAT row still exists')
        ->and(liveSessionIds())->toBe([$staysPat->getKey()]);
});

it('does not filter by token name — that is the caller\'s constraint, not the shared predicate\'s', function (): void {
    $user = User::factory()->creator()->createOne();
    $app = liveSessionPat($user, 'creator-app:android');
    $other = liveSessionPat($user, 'something-else');

    expect(liveSessionIds())->toBe([$app->getKey(), $other->getKey()]);
});

it('works over aliased tables and a plain query builder (the shape a correlated subquery needs)', function (): void {
    config(['sanctum.expiration' => 0]);
    $user = User::factory()->creator()->createOne();
    $live = liveSessionPat($user);
    $expired = liveSessionPat($user, expiresAt: now()->subMinute());

    $query = DB::table('personal_access_tokens as pat')
        ->join('users as u', 'u.id', '=', 'pat.tokenable_id');

    $ids = LiveAppSession::constrain($query, 'pat', 'u')->pluck('pat.id')->map(fn ($id): int => (int) $id)->all();

    expect($ids)->toBe([$live->getKey()])
        ->and($ids)->not->toContain($expired->getKey());
});
