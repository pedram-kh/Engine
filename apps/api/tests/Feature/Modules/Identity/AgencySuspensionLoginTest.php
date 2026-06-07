<?php

declare(strict_types=1);

use App\Modules\Agencies\Database\Factories\AgencyFactory;
use App\Modules\Agencies\Database\Factories\AgencyMembershipFactory;
use App\Modules\Agencies\Enums\AgencyRole;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Events\LoginFailed;
use App\Modules\Identity\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Sprint 13 (D-3) — agency-suspension login enforcement.
 *
 * `is_active` has NEVER been read before, so there is no pre-existing
 * behaviour to lean on: these tests ARE the behaviour. The assertion the
 * focused review checks: a suspended agency's user gets NO session, and
 * the enforcement lands at the auth layer (AuthService::login), reusing
 * the suspended-style 423 envelope.
 *
 * The multi-agency case is deliberate (Q1): block on the PRIMARY (acting)
 * agency only — a user with a second, healthy agency is not locked out by
 * an unrelated suspension.
 */
const SUSPENSION_TEST_PASSWORD = 'password-12chars';

beforeEach(function (): void {
    RateLimiter::for('auth-ip', static fn (Request $request): Limit => Limit::none());
    RateLimiter::for('auth-login-email', static fn (Request $request): Limit => Limit::none());
    RateLimiter::for('auth-password', static fn (Request $request): Limit => Limit::none());
});

function agencyUser(string $email): User
{
    return User::factory()->create([
        'email' => $email,
        'type' => UserType::AgencyUser,
    ]);
}

function joinAgency(User $user, Agency $agency, AgencyRole $role = AgencyRole::AgencyAdmin): void
{
    AgencyMembershipFactory::new()
        ->forAgency($agency)
        ->create(['user_id' => $user->id, 'role' => $role]);
}

it('blocks a suspended agency user at login with no session', function (): void {
    $user = agencyUser('blocked@example.com');
    joinAgency($user, AgencyFactory::new()->suspended()->createOne());

    Event::fake([LoginFailed::class]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'blocked@example.com',
        'password' => SUSPENSION_TEST_PASSWORD,
    ]);

    $response->assertStatus(423)
        ->assertJsonPath('errors.0.code', 'auth.account_locked.suspended');

    $this->assertGuest('web');

    Event::assertDispatched(
        LoginFailed::class,
        static fn (LoginFailed $e): bool => $e->reason === 'agency_suspended',
    );
});

it('lets a user in a healthy agency log in normally', function (): void {
    $user = agencyUser('healthy@example.com');
    joinAgency($user, AgencyFactory::new()->createOne());

    $this->postJson('/api/v1/auth/login', [
        'email' => 'healthy@example.com',
        'password' => SUSPENSION_TEST_PASSWORD,
    ])->assertOk();

    $this->assertAuthenticated('web');
});

it('blocks when the PRIMARY agency is suspended even if a second agency is healthy', function (): void {
    $user = agencyUser('primary-suspended@example.com');
    // Membership rows take ascending ids; the first created is the primary
    // (acting) agency, mirroring SetTenancyContext's order-by-id.
    joinAgency($user, AgencyFactory::new()->suspended()->createOne());
    joinAgency($user, AgencyFactory::new()->createOne());

    $this->postJson('/api/v1/auth/login', [
        'email' => 'primary-suspended@example.com',
        'password' => SUSPENSION_TEST_PASSWORD,
    ])->assertStatus(423)
        ->assertJsonPath('errors.0.code', 'auth.account_locked.suspended');

    $this->assertGuest('web');
});

it('does NOT lock out a user whose primary agency is healthy but a secondary agency is suspended', function (): void {
    $user = agencyUser('secondary-suspended@example.com');
    // Primary (first id) healthy; an unrelated secondary agency suspended.
    joinAgency($user, AgencyFactory::new()->createOne());
    joinAgency($user, AgencyFactory::new()->suspended()->createOne());

    $this->postJson('/api/v1/auth/login', [
        'email' => 'secondary-suspended@example.com',
        'password' => SUSPENSION_TEST_PASSWORD,
    ])->assertOk();

    $this->assertAuthenticated('web');
});

it('does not block a user with no agency membership (the check is a structural no-op)', function (): void {
    // A creator has no agency membership, so primaryAgencyIsSuspended()
    // resolves to false and the new branch never fires — proving the gate
    // is scoped to agency users with an acting agency.
    User::factory()->create([
        'email' => 'creator-login@example.com',
        'type' => UserType::Creator,
    ]);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'creator-login@example.com',
        'password' => SUSPENSION_TEST_PASSWORD,
    ])->assertOk();

    $this->assertAuthenticated('web');
});
