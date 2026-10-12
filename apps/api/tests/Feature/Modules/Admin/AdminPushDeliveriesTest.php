<?php

declare(strict_types=1);

use App\Modules\Audit\Database\Factories\AuditLogFactory;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Database\Factories\NotificationFactory;
use App\Modules\Notifications\Models\PushDelivery;
use App\Modules\Notifications\Models\UserPushToken;
use App\Modules\Notifications\Push\PushOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| AH-102b C2.1 — admin push-delivery log
|--------------------------------------------------------------------------
|
|   GET /api/v1/admin/push-deliveries
|   GET /api/v1/admin/push-deliveries/summary?window=24h|7d|30d
*/

function pdAdmin(): User
{
    return User::factory()->create([
        'type' => UserType::PlatformAdmin,
        'two_factor_confirmed_at' => now(),
    ]);
}

function pdCreator(string $name = 'Ada Lovelace'): Creator
{
    return CreatorFactory::new()->createOne(['display_name' => $name]);
}

function pdUser(Creator $creator): User
{
    return User::query()->findOrFail($creator->user_id);
}

/** @param  array<string, mixed>  $overrides */
function pdRow(Creator $creator, array $overrides = []): PushDelivery
{
    return PushDelivery::factory()->create(array_merge(['user_id' => $creator->user_id], $overrides));
}

function pdUrl(string $query = '', string $path = ''): string
{
    return '/api/v1/admin/push-deliveries'.$path.($query !== '' ? '?'.$query : '');
}

/**
 * @param  TestResponse<JsonResponse>  $response
 * @return list<string> row ids in response order
 */
function pdIds(TestResponse $response): array
{
    return array_map('strval', array_values($response->json('data.*.id') ?? []));
}

// ─── Auth negative set ──────────────────────────────────────────────────

dataset('push_delivery_routes', [
    'list' => ['/api/v1/admin/push-deliveries'],
    'summary' => ['/api/v1/admin/push-deliveries/summary'],
]);

it('401s an unauthenticated request', function (string $url): void {
    expect($this->getJson($url)->status())->toBe(401);
})->with('push_delivery_routes');

it('403s a creator, an agency user, and a non-admin acting on the admin guard', function (string $url, UserType $type): void {
    $user = User::factory()->create(['type' => $type, 'two_factor_confirmed_at' => now()]);

    expect($this->actingAs($user, 'web_admin')->getJson($url)->status())->toBe(403);
})->with('push_delivery_routes')->with([UserType::Creator, UserType::AgencyUser]);

it('blocks an admin who has not enrolled 2FA (EnsureMfaForAdmins)', function (string $url): void {
    config()->set('auth.admin_mfa_enforced', true);
    $admin = User::factory()->create(['type' => UserType::PlatformAdmin, 'two_factor_confirmed_at' => null]);

    $response = $this->actingAs($admin, 'web_admin')->getJson($url);

    expect($response->status())->toBe(403)
        ->and($response->json('errors.0.code'))->toBe('auth.mfa.enrollment_required');
})->with('push_delivery_routes');

// ─── List ────────────────────────────────────────────────────────────────

it('lists newest first with an exact, allow-listed attribute set and string ids', function (): void {
    $creator = pdCreator();
    $older = pdRow($creator);
    $newer = pdRow($creator, ['outcome' => PushOutcome::ConfigError, 'code' => 'SENDER_ID_MISMATCH', 'http_status' => 403, 'detail' => 'nope', 'final' => true]);

    $response = $this->actingAs(pdAdmin(), 'web_admin')->getJson(pdUrl());

    expect($response->status())->toBe(200)
        ->and(pdIds($response))->toBe([(string) $newer->id, (string) $older->id])
        ->and($response->json('data.0.id'))->toBeString()
        ->and($response->json('data.0.type'))->toBe('push_deliveries')
        ->and(array_keys($response->json('data.0.attributes')))->toBe([
            'created_at', 'platform', 'notification_type', 'outcome', 'attempt', 'final',
            'code', 'http_status', 'detail', 'creator', 'in_app',
        ])
        ->and($response->json('data.0.attributes.outcome'))->toBe('config_error')
        ->and($response->json('data.0.attributes.code'))->toBe('SENDER_ID_MISMATCH')
        ->and($response->json('data.0.attributes.creator'))->toBe([
            'id' => $creator->ulid,
            'display_name' => 'Ada Lovelace',
            'email' => pdUser($creator)->email,
        ])
        ->and($response->json('meta'))->toHaveKeys(['per_page', 'next_cursor', 'prev_cursor', 'has_more']);
});

it('walks the whole set by cursor with no gaps and no duplicates, then stops', function (): void {
    $creator = pdCreator();
    $expected = [];
    for ($i = 0; $i < 7; $i++) {
        $expected[] = (string) pdRow($creator)->id;
    }
    $expected = array_reverse($expected);

    $admin = pdAdmin();
    $seen = [];
    $cursor = null;
    $pages = 0;
    do {
        $url = '/api/v1/admin/push-deliveries?per_page=3'.($cursor !== null ? '&cursor='.urlencode($cursor) : '');
        $response = $this->actingAs($admin, 'web_admin')->getJson($url);
        $seen = array_merge($seen, pdIds($response));
        $cursor = $response->json('meta.next_cursor');
        $pages++;
    } while ($cursor !== null && $pages < 10);

    expect($seen)->toBe($expected)
        ->and($pages)->toBe(3)
        ->and($response->json('meta.has_more'))->toBeFalse();
});

it('filters by each outcome alone', function (string $outcome): void {
    $creator = pdCreator();
    foreach (PushOutcome::cases() as $case) {
        pdRow($creator, ['outcome' => $case]);
    }

    $response = $this->actingAs(pdAdmin(), 'web_admin')->getJson(pdUrl('outcome='.$outcome));

    expect(array_unique($response->json('data.*.attributes.outcome')))->toBe([$outcome])
        ->and($response->json('data'))->toHaveCount(1);
})->with(array_map(static fn (PushOutcome $o): string => $o->value, PushOutcome::cases()));

it('failures preset = terminal non-delivered: includes a FINAL retryable, excludes a non-final retryable and delivered', function (): void {
    $creator = pdCreator();
    $delivered = pdRow($creator, ['outcome' => PushOutcome::Delivered]);
    $retryNonFinal = pdRow($creator, ['outcome' => PushOutcome::Retryable, 'final' => false]);
    $retryFinal = pdRow($creator, ['outcome' => PushOutcome::Retryable, 'final' => true]);
    $tokenInvalid = pdRow($creator, ['outcome' => PushOutcome::TokenInvalid]);
    $configError = pdRow($creator, ['outcome' => PushOutcome::ConfigError]);
    $rejected = pdRow($creator, ['outcome' => PushOutcome::Rejected]);

    $ids = pdIds($this->actingAs(pdAdmin(), 'web_admin')->getJson(pdUrl('outcome=failures')));

    expect($ids)->toEqualCanonicalizing(array_map('strval', [$retryFinal->id, $tokenInvalid->id, $configError->id, $rejected->id]))
        ->and($ids)->not->toContain((string) $delivered->id)
        ->and($ids)->not->toContain((string) $retryNonFinal->id);
});

it('filters by notification_type, creator ULID and date range, alone and combined', function (): void {
    $ada = pdCreator('Ada');
    $bob = pdCreator('Bob');
    $adaInvite = pdRow($ada, ['notification_type' => 'assignment.invited', 'created_at' => '2026-10-01 10:00:00']);
    $adaOther = pdRow($ada, ['notification_type' => 'assignment.accepted', 'created_at' => '2026-10-05 10:00:00']);
    $bobInvite = pdRow($bob, ['notification_type' => 'assignment.invited', 'created_at' => '2026-10-05 23:59:59']);

    expect(pdIds($this->actingAs(pdAdmin(), 'web_admin')->getJson(pdUrl('notification_type=assignment.invited'))))->toEqualCanonicalizing(array_map('strval', [$adaInvite->id, $bobInvite->id]))
        ->and(pdIds($this->actingAs(pdAdmin(), 'web_admin')->getJson(pdUrl('creator='.$ada->ulid))))->toEqualCanonicalizing(array_map('strval', [$adaInvite->id, $adaOther->id]))
        // inclusive day bounds: date_to=2026-10-05 includes a 23:59:59 row; date_from=2026-10-05 excludes the 10-01 row
        ->and(pdIds($this->actingAs(pdAdmin(), 'web_admin')->getJson(pdUrl('date_from=2026-10-05&date_to=2026-10-05'))))->toEqualCanonicalizing(array_map('strval', [$adaOther->id, $bobInvite->id]))
        ->and(pdIds($this->actingAs(pdAdmin(), 'web_admin')->getJson(pdUrl('date_to=2026-10-04'))))->toBe([(string) $adaInvite->id])
        ->and(pdIds($this->actingAs(pdAdmin(), 'web_admin')->getJson(pdUrl('creator='.$ada->ulid.'&notification_type=assignment.invited&date_from=2026-10-01&date_to=2026-10-02'))))->toBe([(string) $adaInvite->id]);
});

it('an unknown or malformed filter value is an EMPTY page, never everything and never a 500', function (string $query): void {
    $creator = pdCreator();
    pdRow($creator);
    pdRow($creator, ['outcome' => PushOutcome::TokenInvalid]);

    $response = $this->actingAs(pdAdmin(), 'web_admin')->getJson(pdUrl($query));

    expect($response->status())->toBe(200)
        ->and($response->json('data'))->toBe([]);
})->with([
    'outcome' => 'outcome=exploded',
    'type' => 'notification_type=not.a.type',
    'creator' => 'creator=01HZZZZZZZZZZZZZZZZZZZZZZZ',
    'date_from' => 'date_from=not-a-date',
    'date_to' => 'date_to=%00garbage',
]);

it('resolves in-app state: read, unread, none (null ulid) and none (notification deleted)', function (): void {
    $creator = pdCreator();
    $read = NotificationFactory::new()->read()->createOne(['recipient_user_id' => $creator->user_id]);
    $unread = NotificationFactory::new()->createOne(['recipient_user_id' => $creator->user_id]);
    $gone = NotificationFactory::new()->createOne(['recipient_user_id' => $creator->user_id]);

    $rowRead = pdRow($creator, ['notification_ulid' => $read->ulid]);
    $rowUnread = pdRow($creator, ['notification_ulid' => $unread->ulid]);
    $rowNull = pdRow($creator, ['notification_ulid' => null]);
    $rowGone = pdRow($creator, ['notification_ulid' => $gone->ulid]);
    $gone->delete();

    $response = $this->actingAs(pdAdmin(), 'web_admin')->getJson(pdUrl());
    $byId = [];
    foreach ($response->json('data') as $row) {
        $byId[$row['id']] = $row['attributes']['in_app'];
    }

    expect($byId[(string) $rowRead->id])->toBe('read')
        ->and($byId[(string) $rowUnread->id])->toBe('unread')
        ->and($byId[(string) $rowNull->id])->toBe('none')
        ->and($byId[(string) $rowGone->id])->toBe('none');
});

it('still reads history for a soft-deleted account (creator block resolved withTrashed)', function (): void {
    $creator = pdCreator('Deleted Dana');
    $email = pdUser($creator)->email;
    pdRow($creator);
    $creator->delete();
    // Raw update: User::delete() demands an audit reason; this is fixture setup, not the flow under test.
    DB::table('users')->where('id', $creator->user_id)->update(['deleted_at' => now()]);

    $response = $this->actingAs(pdAdmin(), 'web_admin')->getJson(pdUrl());

    expect($response->status())->toBe(200)
        ->and($response->json('data.0.attributes.creator'))->toBe([
            'id' => $creator->ulid,
            'display_name' => 'Deleted Dana',
            'email' => $email,
        ]);
});

it('resolves a page without N+1: a 30-creator page costs the same number of queries as a 1-row page', function (): void {
    $admin = pdAdmin();
    $count = function (string $url) use ($admin): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin, 'web_admin')->getJson($url)->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    $first = pdCreator();
    $note = NotificationFactory::new()->createOne(['recipient_user_id' => $first->user_id]);
    pdRow($first, ['notification_ulid' => $note->ulid]);
    $oneRow = $count('/api/v1/admin/push-deliveries?per_page=50');

    for ($i = 0; $i < 29; $i++) {
        $c = pdCreator('C'.$i);
        $n = NotificationFactory::new()->createOne(['recipient_user_id' => $c->user_id]);
        pdRow($c, ['notification_ulid' => $n->ulid]);
    }
    $manyRows = $count('/api/v1/admin/push-deliveries?per_page=50');

    expect($manyRows)->toBe($oneRow)
        ->and($oneRow)->toBeLessThanOrEqual(6);
});

it('PRIVACY: no secret and no forbidden key appears anywhere in either response', function (): void {
    $creator = pdCreator();
    $fcmToken = 'fcm-SECRET-DEVICE-TOKEN-'.str_repeat('x', 100);
    $user = pdUser($creator);
    $user->createToken('creator-app:android', ['*']);
    UserPushToken::factory()->create(['user_id' => $user->id, 'token' => $fcmToken]);
    $note = NotificationFactory::new()->createOne([
        'recipient_user_id' => $user->id,
        'data' => ['title' => 'SECRET-TITLE', 'body' => 'SECRET-BODY'],
    ]);
    AuditLogFactory::new()->create([
        'action' => AuditAction::AuthLoginSucceeded,
        'actor_id' => $user->id,
        'ip' => '203.0.113.77',
        'user_agent' => 'SECRET-UA/1.0',
    ]);
    pdRow($creator, ['notification_ulid' => $note->ulid, 'outcome' => PushOutcome::TokenInvalid, 'detail' => 'provider said no']);

    $admin = pdAdmin();
    $bodies = [
        $this->actingAs($admin, 'web_admin')->getJson('/api/v1/admin/push-deliveries')->getContent(),
        $this->actingAs($admin, 'web_admin')->getJson('/api/v1/admin/push-deliveries/summary')->getContent(),
    ];

    $forbiddenKeys = ['token', 'token_hash', 'ip', 'user_agent', 'message', 'body', 'title', 'password'];
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
        foreach (['SECRET-DEVICE-TOKEN', 'SECRET-TITLE', 'SECRET-BODY', 'SECRET-UA', '203.0.113.77', 'password'] as $secret) {
            expect($body)->not->toContain($secret);
        }
        $walk(json_decode($body, true));
    }
});

// ─── Summary ─────────────────────────────────────────────────────────────

it('summary: empty table → zeros and oldest_at null', function (): void {
    $response = $this->actingAs(pdAdmin(), 'web_admin')->getJson(pdUrl('', '/summary'));

    expect($response->status())->toBe(200)
        ->and($response->json('window'))->toBe('7d')
        ->and($response->json('counts'))->toBe([
            'delivered' => 0, 'token_invalid' => 0, 'retryable_final' => 0, 'config_error' => 0, 'rejected' => 0,
        ])
        ->and($response->json('table'))->toBe(['total_rows' => 0, 'oldest_at' => null]);
});

it('summary: counts TERMINAL attempts only (a 3-attempt retry then success is ONE delivered)', function (): void {
    $creator = pdCreator();
    pdRow($creator, ['outcome' => PushOutcome::Retryable, 'attempt' => 1, 'final' => false]);
    pdRow($creator, ['outcome' => PushOutcome::Retryable, 'attempt' => 2, 'final' => false]);
    pdRow($creator, ['outcome' => PushOutcome::Delivered, 'attempt' => 3, 'final' => true]);
    pdRow($creator, ['outcome' => PushOutcome::Retryable, 'attempt' => 3, 'final' => true]);
    pdRow($creator, ['outcome' => PushOutcome::ConfigError, 'final' => true]);
    pdRow($creator, ['outcome' => PushOutcome::Rejected]);
    pdRow($creator, ['outcome' => PushOutcome::TokenInvalid]);
    pdRow($creator, ['outcome' => PushOutcome::TokenInvalid]);

    $response = $this->actingAs(pdAdmin(), 'web_admin')->getJson(pdUrl('', '/summary'));

    expect($response->json('counts'))->toBe([
        'delivered' => 1, 'token_invalid' => 2, 'retryable_final' => 1, 'config_error' => 1, 'rejected' => 1,
    ])
        ->and($response->json('table.total_rows'))->toBe(8);
});

it('summary: the window boundary is exact — 1s inside counts, 1s outside does not', function (string $window, string $modify): void {
    Carbon::setTestNow(Carbon::parse('2026-10-12 12:00:00'));
    $creator = pdCreator();
    $since = Carbon::now()->modify($modify);
    pdRow($creator, ['created_at' => $since->copy()->addSecond()]);
    pdRow($creator, ['created_at' => $since->copy()->subSecond()]);

    $response = $this->actingAs(pdAdmin(), 'web_admin')->getJson(pdUrl('window='.$window, '/summary'));
    Carbon::setTestNow();

    expect($response->json('window'))->toBe($window)
        ->and($response->json('counts.delivered'))->toBe(1)
        ->and($response->json('table.total_rows'))->toBe(2)
        ->and($response->json('since'))->toBe($since->toIso8601String());
})->with([
    '24h' => ['24h', '-24 hours'],
    '7d' => ['7d', '-7 days'],
    '30d' => ['30d', '-30 days'],
]);

it('summary: the footer reports table depth and the oldest row independent of the window', function (): void {
    $creator = pdCreator();
    pdRow($creator, ['created_at' => now()->subDays(60)->startOfSecond()]);
    pdRow($creator);

    $response = $this->actingAs(pdAdmin(), 'web_admin')->getJson(pdUrl('window=24h', '/summary'));

    expect($response->json('counts.delivered'))->toBe(1)
        ->and($response->json('table.total_rows'))->toBe(2)
        ->and(Carbon::parse($response->json('table.oldest_at'))->toDateString())->toBe(now()->subDays(60)->toDateString());
});

it('summary: an invalid window is a 422', function (string $window): void {
    expect($this->actingAs(pdAdmin(), 'web_admin')->getJson(pdUrl('window='.$window, '/summary'))->status())->toBe(422);
})->with(['1h', '90d', 'week', '7D']);
