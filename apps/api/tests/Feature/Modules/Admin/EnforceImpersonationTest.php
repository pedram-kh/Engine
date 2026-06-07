<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Exceptions\ImpersonationException;
use App\Modules\Identity\Models\ImpersonationSession;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\ImpersonationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Sprint 13 (D-10) — the impersonation security spot-check. Each block is a
 * distinct, testable assertion the independent review can read off:
 *
 *   - TTL enforced server-side, break-revert proven (§5.35)
 *   - the four hard-blocks refused at the API (403/no-op), not UI-hidden
 *   - dual-audit is QUERYABLE by the impersonator column
 *   - no-escalation, three ways
 */
function impAdmin(): User
{
    return User::factory()->create([
        'type' => UserType::PlatformAdmin,
        'two_factor_confirmed_at' => now(),
    ]);
}

function impTarget(): User
{
    return User::factory()->create([
        'type' => UserType::AgencyUser,
        'two_factor_confirmed_at' => now(),
    ]);
}

/**
 * Persist an impersonation row directly (bypassing the hand-off) so the
 * enforcement tests can pin an arbitrary TTL / ended state.
 *
 * @param  array<string, mixed>  $overrides
 */
function impSession(User $admin, User $target, array $overrides = []): ImpersonationSession
{
    $now = Carbon::now();

    return ImpersonationSession::query()->create(array_merge([
        'admin_user_id' => $admin->getKey(),
        'impersonated_user_id' => $target->getKey(),
        'reason' => 'Investigating a reported issue.',
        'token_hash' => null,
        'expires_at' => $now->copy()->addMinutes(30),
        'started_at' => $now,
        'claimed_at' => $now,
        'ended_at' => null,
        'ip' => '127.0.0.1',
        'created_at' => $now,
    ], $overrides));
}

beforeEach(function (): void {
    // Ephemeral routes in the `api` group: a benign read, a log-writer (for
    // the dual-audit assertion), and stand-ins for the two hard-blocked
    // surfaces that are coming-soon this sprint (so the route-NAME block can
    // be proven now). Real surfaces (2FA disable, contract accept) are hit at
    // their real route names.
    Route::middleware(['api', 'auth:web'])->group(function (): void {
        Route::get('api/v1/_test/benign', fn () => response()->json(['ok' => true]))
            ->name('_test.benign');

        Route::post('api/v1/_test/write-audit', function () {
            /** @var User $user */
            $user = auth()->guard('web')->user();
            Audit::log(action: AuditAction::CreatorUpdated, actor: $user, subject: $user);

            return response()->json(['ok' => true]);
        })->name('_test.write_audit');

        Route::post('api/v1/_test/password', fn () => response()->json(['ok' => true]))
            ->name('auth.password.update');

        Route::post('api/v1/_test/release', fn () => response()->json(['ok' => true]))
            ->name('payments.release');
    });
});

it('lets a LIVE impersonated session through a benign request', function (): void {
    $admin = impAdmin();
    $target = impTarget();
    $session = impSession($admin, $target);

    $response = $this->actingAs($target, 'web')
        ->withSession([ImpersonationService::SESSION_KEY => $session->ulid])
        ->getJson('/api/v1/_test/benign');

    expect($response->status())->toBe(200);
});

// ─── TTL — server-authoritative, break-revert seam (§5.35) ───────────────────

it('REJECTS an expired impersonation, ends the row, and shreds the session (TTL break-revert)', function (): void {
    $admin = impAdmin();
    $target = impTarget();
    $session = impSession($admin, $target, [
        'started_at' => Carbon::now()->subMinutes(45),
        'expires_at' => Carbon::now()->subMinutes(15),
    ]);

    $response = $this->actingAs($target, 'web')
        ->withSession([ImpersonationService::SESSION_KEY => $session->ulid])
        ->getJson('/api/v1/_test/benign');

    // The expired session is refused server-side — an advisory frontend TTL
    // would let this 200. Removing the isExpired() branch in the middleware
    // flips this assertion red: that is the break-revert proof the check is
    // load-bearing.
    expect($response->status())->toBe(401);
    expect($response->json('errors.0.code'))->toBe('admin.impersonation.expired');

    // The row is terminated (not left dangling) and the end is audited.
    $session->refresh();
    expect($session->ended_at)->not->toBeNull();
    expect(AuditLog::query()->where('action', AuditAction::AdminImpersonationEnded->value)->exists())
        ->toBeTrue();
});

it('REJECTS an orphaned (already-ended) impersonation marker', function (): void {
    $admin = impAdmin();
    $target = impTarget();
    $session = impSession($admin, $target, ['ended_at' => Carbon::now()->subMinute()]);

    $response = $this->actingAs($target, 'web')
        ->withSession([ImpersonationService::SESSION_KEY => $session->ulid])
        ->getJson('/api/v1/_test/benign');

    expect($response->status())->toBe(401);
    expect($response->json('errors.0.code'))->toBe('admin.impersonation.session_invalid');
});

// ─── The four hard-blocks — refused at the API (403/no-op), not UI-hidden ────

it('hard-blocks #1 password change while impersonating', function (): void {
    $admin = impAdmin();
    $target = impTarget();
    $session = impSession($admin, $target);

    $response = $this->actingAs($target, 'web')
        ->withSession([ImpersonationService::SESSION_KEY => $session->ulid])
        ->postJson('/api/v1/_test/password');

    expect($response->status())->toBe(403);
    expect($response->json('errors.0.code'))->toBe('admin.impersonation.action_blocked');
});

it('hard-blocks #2 two-factor disable while impersonating', function (): void {
    $admin = impAdmin();
    $target = impTarget();
    $session = impSession($admin, $target);

    $response = $this->actingAs($target, 'web')
        ->withSession([ImpersonationService::SESSION_KEY => $session->ulid])
        ->postJson('/api/v1/auth/2fa/disable');

    expect($response->status())->toBe(403);
    expect($response->json('errors.0.code'))->toBe('admin.impersonation.action_blocked');
});

it('hard-blocks #3 contract signing while impersonating', function (): void {
    $admin = impAdmin();
    $target = impTarget();
    $session = impSession($admin, $target);

    $response = $this->actingAs($target, 'web')
        ->withSession([ImpersonationService::SESSION_KEY => $session->ulid])
        ->postJson('/api/v1/creators/me/wizard/contract/click-through-accept');

    expect($response->status())->toBe(403);
    expect($response->json('errors.0.code'))->toBe('admin.impersonation.action_blocked');
});

it('hard-blocks #4 payment release while impersonating', function (): void {
    $admin = impAdmin();
    $target = impTarget();
    $session = impSession($admin, $target);

    $response = $this->actingAs($target, 'web')
        ->withSession([ImpersonationService::SESSION_KEY => $session->ulid])
        ->postJson('/api/v1/_test/release');

    expect($response->status())->toBe(403);
    expect($response->json('errors.0.code'))->toBe('admin.impersonation.action_blocked');
});

it('does NOT block the same hard-block routes when NOT impersonating', function (): void {
    $target = impTarget();

    // No impersonation session in play — the middleware is a pure pass-through,
    // so the request reaches the route (200 from the ephemeral stand-in).
    $response = $this->actingAs($target, 'web')->postJson('/api/v1/_test/password');

    expect($response->status())->toBe(200);
});

// ─── Dual-audit — queryable by the impersonator column (Q3) ──────────────────

it('writes dual-audit (actor = impersonated, impersonator = admin) and is queryable by impersonator', function (): void {
    $admin = impAdmin();
    $target = impTarget();
    $session = impSession($admin, $target);

    $response = $this->actingAs($target, 'web')
        ->withSession([ImpersonationService::SESSION_KEY => $session->ulid])
        ->postJson('/api/v1/_test/write-audit');

    expect($response->status())->toBe(200);

    $log = AuditLog::query()
        ->where('action', AuditAction::CreatorUpdated->value)
        ->firstOrFail();

    // Dual-audit: the truthful actor is the impersonated user; the admin is
    // recorded as the impersonator behind the keyboard.
    expect($log->actor_id)->toBe($target->id);
    expect($log->impersonator_user_id)->toBe($admin->id);

    // The whole point of the first-class column (Q3): "every action taken by
    // impersonator Y" is a queryable fact, not a JSON-metadata scan.
    $byImpersonator = AuditLog::query()
        ->where('impersonator_user_id', $admin->id)
        ->get();
    expect($byImpersonator)->toHaveCount(1);
    expect($byImpersonator->first()?->actor_id)->toBe($target->id);
});

// ─── No-escalation, three ways ───────────────────────────────────────────────

it('no-escalation: an impersonated web session cannot reach /admin/* (two-cookie isolation)', function (): void {
    $admin = impAdmin();
    $target = impTarget();
    $session = impSession($admin, $target);

    // The impersonated session lives on the `web` guard; the admin SPA needs
    // `web_admin`. Hitting an admin endpoint with the impersonated session is
    // unauthenticated against that guard.
    $response = $this->actingAs($target, 'web')
        ->withSession([ImpersonationService::SESSION_KEY => $session->ulid])
        ->getJson('/api/v1/admin/agencies');

    expect($response->status())->toBe(401);
});

it('no-escalation: the impersonated session cannot mutate credentials (2FA/password hard-blocked)', function (): void {
    $admin = impAdmin();
    $target = impTarget();
    $session = impSession($admin, $target);

    // Credential-mutation surfaces are refused (covered by the hard-blocks),
    // so the impersonator can never lock the real user out or hijack login.
    $twofa = $this->actingAs($target, 'web')
        ->withSession([ImpersonationService::SESSION_KEY => $session->ulid])
        ->postJson('/api/v1/auth/2fa/disable');
    $password = $this->actingAs($target, 'web')
        ->withSession([ImpersonationService::SESSION_KEY => $session->ulid])
        ->postJson('/api/v1/_test/password');

    expect($twofa->status())->toBe(403);
    expect($password->status())->toBe(403);
});

it('no-escalation: start refuses a second active session (no nesting)', function (): void {
    $admin = impAdmin();
    $first = impTarget();
    $second = impTarget();
    impSession($admin, $first);

    $service = app(ImpersonationService::class);

    expect(fn () => $service->start($admin, $second, 'Trying to nest a second impersonation.', null))
        ->toThrow(ImpersonationException::class);
});

// ─── Banner hydration — /auth/impersonation/status ───────────────────────────

it('status reports active + expiry for a live impersonated session', function (): void {
    $admin = impAdmin();
    $target = impTarget();
    $session = impSession($admin, $target);

    $response = $this->actingAs($target, 'web')
        ->withSession([ImpersonationService::SESSION_KEY => $session->ulid])
        ->getJson('/api/v1/auth/impersonation/status');

    expect($response->status())->toBe(200);
    expect($response->json('data.active'))->toBeTrue();
    expect($response->json('data.expires_at'))->toBeString();
});

it('status reports inactive for a normal (non-impersonated) session', function (): void {
    $target = impTarget();

    $response = $this->actingAs($target, 'web')->getJson('/api/v1/auth/impersonation/status');

    expect($response->status())->toBe(200);
    expect($response->json('data.active'))->toBeFalse();
});

it('uses a real ULID for the seeded session (sanity)', function (): void {
    $admin = impAdmin();
    $target = impTarget();
    $session = impSession($admin, $target);

    expect(Str::isUlid($session->ulid))->toBeTrue();
});
