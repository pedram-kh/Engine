<?php

declare(strict_types=1);

use App\Modules\Agencies\Enums\AgencyRole;
use App\Modules\Agencies\Exceptions\InvitationAccountExistsException;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyUserInvitation;
use App\Modules\Agencies\Services\AgencyInvitationService;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Identity\Contracts\PwnedPasswordsClientContract;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

/**
 * AH-095 — invite-acceptance creates the account (the stranger path).
 *
 * Sibling of `InvitationTest.php`'s "Accept invitation" section, which
 * this file leaves completely untouched — the existing-account path
 * (`POST .../invitations/accept`) is byte-identical proof by construction:
 * neither its controller method, its service method, nor its route
 * changed for AH-095, so that suite passing unchanged IS the
 * byte-identical proof review priority #4 asks for.
 */
const AH095_STRONG_PASSWORD = 'Correct-Horse-Battery-Staple-42';

beforeEach(function (): void {
    RateLimiter::for('auth-ip', static fn (Request $request): Limit => Limit::none());

    app()->bind(PwnedPasswordsClientContract::class, fn () => new class implements PwnedPasswordsClientContract
    {
        public function breachCount(string $plaintextPassword): int
        {
            return 0;
        }
    });
});

/**
 * @return array{token: string, invitation: AgencyUserInvitation, agency: Agency}
 */
function provisionAgencyInvitation(array $overrides = []): array
{
    $agency = $overrides['agency'] ?? Agency::factory()->createOne();
    $token = Str::random(64);
    $inviter = User::factory()->agencyAdmin($agency)->createOne();

    $invitation = AgencyUserInvitation::factory()->create([
        'agency_id' => $agency->id,
        'email' => $overrides['email'] ?? 'stranger@example.com',
        'role' => $overrides['role'] ?? AgencyRole::AgencyManager,
        'token_hash' => hash('sha256', $token),
        'expires_at' => $overrides['expires_at'] ?? now()->addDays(7),
        'invited_by_user_id' => $inviter->id,
    ]);

    return ['token' => $token, 'invitation' => $invitation, 'agency' => $agency];
}

// ---------------------------------------------------------------------------
// Happy path — agency-typed + pre-verified assertions (review priority #6)
// ---------------------------------------------------------------------------

it('creates an agency-typed, pre-verified account, activates the membership, consumes the token, and signs the user in', function (): void {
    ['token' => $token, 'invitation' => $invitation, 'agency' => $agency] = provisionAgencyInvitation([
        'email' => 'newhire@example.com',
        'role' => AgencyRole::AgencyStaff,
    ]);

    $response = $this->postJson("/api/v1/agencies/{$agency->ulid}/invitations/create-account", [
        'token' => $token,
        'name' => 'New',
        'last_name' => 'Hire',
        'password' => AH095_STRONG_PASSWORD,
        'password_confirmation' => AH095_STRONG_PASSWORD,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.attributes.email', 'newhire@example.com')
        ->assertJsonPath('data.attributes.user_type', UserType::AgencyUser->value)
        ->assertJsonPath('data.attributes.name', 'New')
        ->assertJsonPath('data.attributes.last_name', 'Hire')
        ->assertJsonPath('data.attributes.email_verified_at', fn ($v) => $v !== null);

    $user = User::query()->where('email', 'newhire@example.com')->firstOrFail();

    expect($user->type)->toBe(UserType::AgencyUser)
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->hasTwoFactorEnabled())->toBeFalse(); // D4 — created un-enrolled.

    $this->assertDatabaseHas('agency_users', [
        'agency_id' => $agency->id,
        'user_id' => $user->id,
        'role' => AgencyRole::AgencyStaff->value,
    ]);

    $invitation->refresh();
    expect($invitation->accepted_at)->not->toBeNull()
        ->and($invitation->accepted_by_user_id)->toBe($user->id);

    // Session established directly by this endpoint — no second /me
    // round-trip required, mirroring login()'s setUser()-direct shape.
    $this->assertAuthenticatedAs($user, 'web');

    $this->assertDatabaseHas('audit_logs', [
        'action' => AuditAction::InvitationAcceptedWithNewAccount->value,
    ]);
});

// ---------------------------------------------------------------------------
// Review priority #1 — email immutability, foreclosed by construction
// ---------------------------------------------------------------------------

it('the created account email is always the invitation email — a client-submitted email field has no effect', function (): void {
    ['token' => $token, 'agency' => $agency] = provisionAgencyInvitation([
        'email' => 'authoritative@example.com',
    ]);

    $response = $this->postJson("/api/v1/agencies/{$agency->ulid}/invitations/create-account", [
        'token' => $token,
        'name' => 'Attacker',
        'last_name' => 'Attempt',
        'password' => AH095_STRONG_PASSWORD,
        'password_confirmation' => AH095_STRONG_PASSWORD,
        // Not a validated field on CreateAccountAndAcceptInvitationRequest
        // — there is no rule for it, so FormRequest::validated() drops it
        // before it ever reaches the service. This proves the absence,
        // not merely a check that would reject a mismatch.
        'email' => 'attacker-supplied@example.com',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.attributes.email', 'authoritative@example.com');

    expect(User::query()->where('email', 'attacker-supplied@example.com')->exists())->toBeFalse();
    expect(User::query()->where('email', 'authoritative@example.com')->exists())->toBeTrue();
});

// ---------------------------------------------------------------------------
// Guards — token expiry/tamper, revoked (n/a), already-accepted
// ---------------------------------------------------------------------------

it('returns 404 for an unknown token (tamper defence — the hash lookup misses)', function (): void {
    $agency = Agency::factory()->createOne();

    $this->postJson("/api/v1/agencies/{$agency->ulid}/invitations/create-account", [
        'token' => str_repeat('x', 64),
        'name' => 'X',
        'last_name' => 'Y',
        'password' => AH095_STRONG_PASSWORD,
        'password_confirmation' => AH095_STRONG_PASSWORD,
    ])->assertNotFound();
});

it('returns 410 for an expired invitation and emits expired-on-attempt audit log', function (): void {
    ['token' => $token, 'agency' => $agency] = provisionAgencyInvitation([
        'expires_at' => now()->subDay(),
    ]);

    $this->postJson("/api/v1/agencies/{$agency->ulid}/invitations/create-account", [
        'token' => $token,
        'name' => 'X',
        'last_name' => 'Y',
        'password' => AH095_STRONG_PASSWORD,
        'password_confirmation' => AH095_STRONG_PASSWORD,
    ])->assertStatus(410)
        ->assertJsonPath('errors.0.code', 'invitation.expired');

    $this->assertDatabaseHas('audit_logs', [
        'action' => AuditAction::InvitationExpiredOnAttempt->value,
    ]);
});

it('returns 409 for an already-accepted invitation', function (): void {
    ['token' => $token, 'agency' => $agency] = provisionAgencyInvitation();
    $accepted = AgencyUserInvitation::withoutGlobalScopes()
        ->where('token_hash', hash('sha256', $token))
        ->firstOrFail();
    $accepted->update(['accepted_at' => now(), 'accepted_by_user_id' => User::factory()->createOne()->id]);

    $this->postJson("/api/v1/agencies/{$agency->ulid}/invitations/create-account", [
        'token' => $token,
        'name' => 'X',
        'last_name' => 'Y',
        'password' => AH095_STRONG_PASSWORD,
        'password_confirmation' => AH095_STRONG_PASSWORD,
    ])->assertConflict()
        ->assertJsonPath('errors.0.code', 'invitation.already_accepted');
});

it('password rules are the platform standard set — a weak password is rejected', function (): void {
    ['token' => $token, 'agency' => $agency] = provisionAgencyInvitation();

    $this->postJson("/api/v1/agencies/{$agency->ulid}/invitations/create-account", [
        'token' => $token,
        'name' => 'X',
        'last_name' => 'Y',
        'password' => 'short',
        'password_confirmation' => 'short',
    ])->assertEnvelopeValidationErrors(['password']);
});

it('rejects a mismatched password confirmation', function (): void {
    ['token' => $token, 'agency' => $agency] = provisionAgencyInvitation();

    $this->postJson("/api/v1/agencies/{$agency->ulid}/invitations/create-account", [
        'token' => $token,
        'name' => 'X',
        'last_name' => 'Y',
        'password' => AH095_STRONG_PASSWORD,
        'password_confirmation' => 'does-not-match',
    ])->assertEnvelopeValidationErrors(['password']);
});

// ---------------------------------------------------------------------------
// D3 — collision race (both guard layers)
// ---------------------------------------------------------------------------

it('layer 1 (controller pre-check): returns 409 invitation.account_exists when an account already exists, creates nothing', function (): void {
    ['token' => $token, 'agency' => $agency] = provisionAgencyInvitation([
        'email' => 'already-registered@example.com',
    ]);
    User::factory()->createOne(['email' => 'already-registered@example.com']);

    $this->postJson("/api/v1/agencies/{$agency->ulid}/invitations/create-account", [
        'token' => $token,
        'name' => 'X',
        'last_name' => 'Y',
        'password' => AH095_STRONG_PASSWORD,
        'password_confirmation' => AH095_STRONG_PASSWORD,
    ])->assertConflict()
        ->assertJsonPath('errors.0.code', 'invitation.account_exists');

    // No membership created for the pre-existing user (the invitation's
    // inviter has their own agency_admin membership row already — this
    // asserts no ADDITIONAL row was created for the collision attempt).
    $this->assertDatabaseCount('agency_users', 1);
});

it('layer 2 (service re-check): the same account_exists guard fires when called directly, closing the page-load-to-submit window', function (): void {
    // Simulates the race: the controller's pre-check ran (hypothetically
    // clean) but by the time the service's own write begins, a row now
    // exists for this email — e.g. the invitee registered through a
    // second tab between page-load and this call. Calling the service
    // directly (bypassing the controller's already-tested pre-check)
    // isolates this second, independent layer.
    ['invitation' => $invitation] = provisionAgencyInvitation([
        'email' => 'race@example.com',
    ]);
    User::factory()->createOne(['email' => 'race@example.com']);

    $service = app(AgencyInvitationService::class);
    $request = Request::create('/');
    $this->startSession();
    $request->setLaravelSession(app('session')->driver());

    expect(fn () => $service->acceptWithNewAccount($invitation, [
        'name' => 'Race',
        'last_name' => 'Condition',
        'password' => AH095_STRONG_PASSWORD,
    ], $request))->toThrow(InvitationAccountExistsException::class);

    $invitation->refresh();
    expect($invitation->accepted_at)->toBeNull();
    // Only the inviter (from provisionAgencyInvitation) + the pre-existing
    // colliding row — no third user created by the aborted call.
    $this->assertDatabaseCount('users', 2);
});

// ---------------------------------------------------------------------------
// Review priority #5 — transaction rollback proof
// ---------------------------------------------------------------------------

it('rollback proof: a mid-transaction failure after the user+membership writes leaves zero rows', function (): void {
    ['invitation' => $invitation] = provisionAgencyInvitation([
        'email' => 'rollback@example.com',
    ]);

    // AuditLogger is final, so swap a generic throwing instance rather
    // than mock the concrete class — same technique as BoardResetTest's
    // atomicity anchor. `User` implements `Audited`, whose `created`
    // model-event listener resolves the (now-swapped) logger itself —
    // so this forces a failure at the FIRST write inside the
    // transaction (immediately after the User row is inserted, before
    // the membership create or the invitation stamp ever run), which is
    // the sharpest version of "no half-account": even the very first
    // row written does not survive.
    $throwingAudit = Mockery::mock();
    $throwingAudit->shouldReceive('log')->andThrow(new RuntimeException('forced mid-transaction failure'));
    Audit::swap($throwingAudit);

    $service = app(AgencyInvitationService::class);
    $request = Request::create('/');
    $this->startSession();
    $request->setLaravelSession(app('session')->driver());

    expect(fn () => $service->acceptWithNewAccount($invitation, [
        'name' => 'Half',
        'last_name' => 'Account',
        'password' => AH095_STRONG_PASSWORD,
    ], $request))->toThrow(TypeError::class);

    // No half-account: neither the user, nor the membership, nor the
    // invitation stamp survived the rollback. (`agency_users` count 1,
    // not 0 — the invitation's own inviter already has a membership row
    // from `provisionAgencyInvitation()`; the assertion is that NO
    // additional row was created for this attempt.)
    $this->assertDatabaseMissing('users', ['email' => 'rollback@example.com']);
    $this->assertDatabaseCount('agency_users', 1);

    $invitation->refresh();
    expect($invitation->accepted_at)->toBeNull()
        ->and($invitation->accepted_by_user_id)->toBeNull();
});

it('unauthenticated request succeeds without a prior session (this endpoint IS what creates one)', function (): void {
    ['token' => $token, 'agency' => $agency] = provisionAgencyInvitation([
        'email' => 'no-prior-session@example.com',
    ]);

    // No actingAs() anywhere in this test.
    $this->postJson("/api/v1/agencies/{$agency->ulid}/invitations/create-account", [
        'token' => $token,
        'name' => 'No',
        'last_name' => 'Session',
        'password' => AH095_STRONG_PASSWORD,
        'password_confirmation' => AH095_STRONG_PASSWORD,
    ])->assertOk();
});
