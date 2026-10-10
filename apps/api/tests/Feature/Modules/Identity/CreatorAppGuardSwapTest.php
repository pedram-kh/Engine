<?php

declare(strict_types=1);

use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| AH-099 S3 — the guard swap and the widening hazard (review priority #1)
|--------------------------------------------------------------------------
|
| The creator-reachable mounts moved from `auth:web` to
| `auth:web,creator_app` (the M2.4 list: 13 mounts, 73 routes). The hazard a
| naive `auth:sanctum` swap would create — Sanctum's guard list is
| ['web','web_admin'] — is a platform-admin SESSION authenticating against
| creator endpoints. The mechanism that forecloses it is the dedicated
| bearer-only `creator_app` guard (see CreatorTokenGuard); these are its pins,
| BOTH polarities:
|
|   - a web_admin session on a swapped route  → 401   (the hazard, pinned)
|   - a creator cookie session                → works, under the SAME
|                                                `web` guard as before
|
| Plus the route-inspection trio: the swapped set is exactly the intended
| set; nothing agency/admin carries `creator_app`; nothing mounts
| `auth:sanctum`.
|
*/

/** URI shapes that MUST carry the creator guard (completeness) and that ONLY may (exclusivity). */
const CA_SWAPPED_URI_PATTERNS = [
    '#^api/v1/me$#',
    '#^api/v1/me/notifications(/.*)?$#',
    '#^api/v1/me/notification-preferences$#',
    '#^api/v1/creators/me(/.*)?$#',
    '#^api/v1/auth/logout$#',
    '#^api/v1/auth/2fa/(enable|confirm|disable|recovery-codes)$#',
];

/** @return list<array{key: string, uri: string, middleware: list<string>}> */
function caRoutes(): array
{
    $rows = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        $middleware = array_values(array_filter($route->gatherMiddleware(), 'is_string'));
        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $rows[] = [
                'key' => $method.' '.$route->uri(),
                'uri' => $route->uri(),
                'middleware' => $middleware,
            ];
        }
    }

    return $rows;
}

function caIsSwappedUri(string $uri): bool
{
    foreach (CA_SWAPPED_URI_PATTERNS as $pattern) {
        if (preg_match($pattern, $uri) === 1) {
            return true;
        }
    }

    return false;
}

function caCreatorWithRow(bool $verified = true): User
{
    $user = $verified ? User::factory()->creator()->createOne() : User::factory()->creator()->unverified()->createOne();
    CreatorFactory::new()->bootstrap()->createOne(['user_id' => $user->id]);

    return $user;
}

// =============================================================================
// Route inspection #1 — the swapped set is EXACTLY the intended set
// =============================================================================

it('every creator-reachable route carries auth:web,creator_app, and ONLY those do (swapped set = intended set)', function (): void {
    $missing = [];
    $overreach = [];
    $swapped = 0;

    foreach (caRoutes() as $row) {
        $has = in_array('auth:web,creator_app', $row['middleware'], true);
        $should = caIsSwappedUri($row['uri']);

        $has && $swapped++;
        $should && ! $has && $missing[] = $row['key'];
        $has && ! $should && $overreach[] = $row['key'];
    }

    expect($missing)->toBe([], 'creator-reachable routes still on auth:web only: '.implode(', ', $missing))
        ->and($overreach)->toBe([], 'routes carrying creator_app that are not creator-reachable: '.implode(', ', $overreach))
        ->and($swapped)->toBeGreaterThan(0);
});

it('the swapped routes no longer carry bare auth:web (it was REPLACED, not appended)', function (): void {
    $both = [];

    foreach (caRoutes() as $row) {
        if (in_array('auth:web,creator_app', $row['middleware'], true) && in_array('auth:web', $row['middleware'], true)) {
            $both[] = $row['key'];
        }
    }

    expect($both)->toBe([]);
});

// =============================================================================
// Route inspection #2 — the over-reach negative: agency / admin / impersonation
// surfaces keep auth:web (or auth:web_admin) UNTOUCHED
// =============================================================================

it('no agency-scoped, admin, or tenancy.agency route carries creator_app', function (): void {
    $offenders = [];

    foreach (caRoutes() as $row) {
        if (! in_array('auth:web,creator_app', $row['middleware'], true)) {
            continue;
        }

        $agencyOrAdmin = in_array('tenancy.agency', $row['middleware'], true)
            || in_array('tenancy', $row['middleware'], true)
            || str_starts_with($row['uri'], 'api/v1/admin/')
            || str_contains($row['uri'], 'agencies/{agency}')
            || preg_grep('/web_admin/', $row['middleware']) !== [];

        $agencyOrAdmin && $offenders[] = $row['key'];
    }

    expect($offenders)->toBe([], implode(', ', $offenders));
});

it('the deliberate stay-behind routes are still auth:web ONLY (session-only by design)', function (): void {
    $stayBehind = [
        // Impersonation hand-off is session-only by design (EnforceImpersonation).
        'GET api/v1/auth/impersonation/status',
        'POST api/v1/auth/impersonation/end',
        // Bulk-invite polling: agency-side only; no creator caller exists.
        'GET api/v1/jobs/{job}',
        // Agency invitation accept + bulk creator invitation: agency surfaces.
        'POST api/v1/agencies/{agency}/invitations/accept',
        'POST api/v1/agencies/{agency}/creators/invitations/bulk',
    ];

    $byKey = [];
    foreach (caRoutes() as $row) {
        $byKey[$row['key']] = $row['middleware'];
    }

    foreach ($stayBehind as $key) {
        expect($byKey)->toHaveKey($key);
        expect($byKey[$key])->toContain('auth:web')
            ->and($byKey[$key])->not->toContain('auth:web,creator_app');
    }
});

it('every agency-scoped route still mounts plain auth:web (agency/admin surfaces keep auth:web untouched)', function (): void {
    $checked = 0;
    $offenders = [];

    foreach (caRoutes() as $row) {
        if (in_array('tenancy.agency', $row['middleware'], true)) {
            $checked++;

            if (! in_array('auth:web', $row['middleware'], true) || in_array('auth:web,creator_app', $row['middleware'], true)) {
                $offenders[] = $row['key'].' ['.implode(',', $row['middleware']).']';
            }
        }
    }

    expect($offenders)->toBe([], implode("\n", $offenders))
        ->and($checked)->toBeGreaterThan(50);
});

// =============================================================================
// Route inspection #3 — no route anywhere mounts auth:sanctum
// =============================================================================

it('no route anywhere mounts auth:sanctum (Sanctum\'s session-widening guard is never mounted)', function (): void {
    $offenders = [];

    foreach (caRoutes() as $row) {
        foreach ($row['middleware'] as $mw) {
            if (preg_match('/^auth:.*sanctum/i', $mw) === 1) {
                $offenders[] = $row['key'].' ['.$mw.']';
            }
        }
    }

    expect($offenders)->toBe([], implode(', ', $offenders));
});

it('sanctum.guard is untouched — still [web, web_admin] (we foreclosed the hazard by NOT using it)', function (): void {
    expect(config('sanctum.guard'))->toBe(['web', 'web_admin']);
});

it('the bearer-only (bare auth:creator_app) routes are a closed allowlist, disjoint from the swapped set (AH-102)', function (): void {
    $allowlist = ['DELETE api/v1/me/push-tokens', 'POST api/v1/me/push-tokens'];

    $bare = [];
    foreach (caRoutes() as $row) {
        if (in_array('auth:creator_app', $row['middleware'], true)) {
            $bare[] = $row['key'];

            // Disjoint: a bare-bearer route is never ALSO on the session-or-bearer guard.
            expect($row['middleware'])->not->toContain('auth:web,creator_app')
                ->and($row['middleware'])->not->toContain('auth:web');
        }
    }

    sort($bare);

    expect($bare)->toBe($allowlist, 'a new bearer-only creator route must be added to this allowlist deliberately');
});

// =============================================================================
// The hazard, pinned — behavioural, both polarities
// =============================================================================

it('401s a web_admin SESSION on GET /me — under a naive auth:sanctum swap this returned 200 with the admin\'s own data', function (): void {
    $admin = User::factory()->platformAdmin()->createOne();

    $this->actingAs($admin, 'web_admin')->getJson('/api/v1/me')->assertStatus(401);
});

it('401s a web_admin SESSION across one route per swapped group', function (): void {
    $admin = User::factory()->platformAdmin()->createOne();

    foreach ([
        '/api/v1/me',                                // Identity
        '/api/v1/creators/me',                       // Creators
        '/api/v1/creators/me/messageable-agencies',  // Messaging
        '/api/v1/me/notifications',                  // Notifications
    ] as $uri) {
        app('auth')->forgetGuards();
        $this->actingAs($admin, 'web_admin')->getJson($uri)->assertStatus(401);
    }
});

it('a web_admin session cannot ride along with a creator bearer: the request is the CREATOR, never the admin', function (): void {
    $admin = User::factory()->platformAdmin()->createOne();
    $creator = User::factory()->creator()->createOne();
    $plain = $creator->createToken('creator-app:ios', ['*'])->plainTextToken;

    $this->actingAs($admin, 'web_admin')
        ->withHeaders(['Origin' => 'capacitor://localhost', 'Authorization' => 'Bearer '.$plain])
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.id', $creator->ulid);
});

it('a creator COOKIE session works on the swapped routes under the SAME web guard — no transient token, default guard unchanged', function (): void {
    $creator = caCreatorWithRow();

    $this->actingAs($creator, 'web')
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.id', $creator->ulid);

    // The identical guard resolution the web SPA has always had.
    expect(auth()->getDefaultDriver())->toBe('web');
    expect(auth()->user()?->currentAccessToken())->toBeNull();
});

it('an UNAUTHENTICATED request is still 401 on every swapped group (no guard accepts nothing)', function (): void {
    foreach ([
        '/api/v1/me',
        '/api/v1/creators/me',
        '/api/v1/creators/me/messageable-agencies',
        '/api/v1/me/notifications',
    ] as $uri) {
        $this->withHeader('Origin', 'capacitor://localhost')->getJson($uri)->assertStatus(401);
    }
});

// =============================================================================
// Bearer works on one route per swapped group (the new capability, dark today)
// =============================================================================

it('a creator bearer token authenticates one route per swapped group', function (): void {
    $creator = caCreatorWithRow();
    $plain = $creator->createToken('creator-app:ios', ['*'])->plainTextToken;

    foreach ([
        '/api/v1/me',                                // Identity
        '/api/v1/creators/me',                       // Creators
        '/api/v1/creators/me/messageable-agencies',  // Messaging
        '/api/v1/me/notifications',                  // Notifications
        '/api/v1/me/notification-preferences',       // Notifications (prefs)
    ] as $uri) {
        app('auth')->forgetGuards();
        $this->withHeaders(['Origin' => 'capacitor://localhost', 'Authorization' => 'Bearer '.$plain])
            ->getJson($uri)
            ->assertOk();
    }
});

it('the verified gate still holds on the token path (an unverified creator\'s token is refused with 403)', function (): void {
    $creator = caCreatorWithRow(verified: false);
    $plain = $creator->createToken('creator-app:ios', ['*'])->plainTextToken;

    $this->withHeaders(['Origin' => 'capacitor://localhost', 'Authorization' => 'Bearer '.$plain])
        ->getJson('/api/v1/creators/me')
        ->assertStatus(403);
});

it('a bearer token does NOT authenticate an agency-surface route (those mounts never listed creator_app)', function (): void {
    $creator = caCreatorWithRow();
    $plain = $creator->createToken('creator-app:ios', ['*'])->plainTextToken;

    // Impersonation status is a stay-behind route: session-only by design.
    $this->withHeaders(['Origin' => 'capacitor://localhost', 'Authorization' => 'Bearer '.$plain])
        ->getJson('/api/v1/auth/impersonation/status')
        ->assertStatus(401);
});
