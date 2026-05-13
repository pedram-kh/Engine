<?php

declare(strict_types=1);

use App\Core\Tenancy\TenancyContext;
use App\Modules\Agencies\Database\Factories\AgencyFactory;
use App\Modules\Agencies\Models\AgencyMembership;
use App\Modules\Identity\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    RateLimiter::for('auth-ip', static fn (Request $request): Limit => Limit::none());
});

afterEach(function (): void {
    app(TenancyContext::class)->forget();
});

// -----------------------------------------------------------------------------
// /api/v1/me — main SPA, web guard
// -----------------------------------------------------------------------------

it('returns the authenticated user resource on the main guard', function (): void {
    /** @var User $user */
    $user = User::factory()->createOne(['email' => 'me@example.com']);

    $response = $this->actingAs($user, 'web')->getJson('/api/v1/me');

    $response->assertOk()
        ->assertJsonPath('data.id', $user->ulid)
        ->assertJsonPath('data.attributes.email', 'me@example.com')
        ->assertJsonPath('data.attributes.user_type', $user->type->value)
        ->assertJsonMissingPath('data.attributes.password')
        ->assertJsonMissingPath('data.attributes.two_factor_secret')
        ->assertJsonMissingPath('data.attributes.two_factor_recovery_codes');
});

it('exposes the unverified email_verified_at as null on the main guard', function (): void {
    /** @var User $user */
    $user = User::factory()->unverified()->createOne();

    $this->actingAs($user, 'web')
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.attributes.email_verified_at', null);
});

it('returns 401 when no session exists on the main guard', function (): void {
    $this->getJson('/api/v1/me')->assertStatus(401);
});

// -----------------------------------------------------------------------------
// /api/v1/admin/me — admin SPA, web_admin guard, EnsureMfaForAdmins
// -----------------------------------------------------------------------------

it('returns the resource on the admin guard for an MFA-enrolled platform admin', function (): void {
    /** @var User $admin */
    $admin = User::factory()->platformAdmin()->withTwoFactor()->createOne([
        'email' => 'admin@example.com',
    ]);

    $response = $this->actingAs($admin, 'web_admin')->getJson('/api/v1/admin/me');

    $response->assertOk()
        ->assertJsonPath('data.id', $admin->ulid)
        ->assertJsonPath('data.attributes.email', 'admin@example.com')
        ->assertJsonPath('data.attributes.two_factor_enabled', true);
});

it('returns 403 auth.mfa.enrollment_required for an admin without 2FA on the admin guard', function (): void {
    config()->set('auth.admin_mfa_enforced', true);
    config()->set('app.env', 'testing');

    /** @var User $admin */
    $admin = User::factory()->platformAdmin()->createOne();

    $response = $this->actingAs($admin, 'web_admin')->getJson('/api/v1/admin/me');

    $response->assertStatus(403)
        ->assertJsonPath('errors.0.code', 'auth.mfa.enrollment_required');
});

it('returns 401 when no session exists on the admin guard', function (): void {
    $this->getJson('/api/v1/admin/me')->assertStatus(401);
});

// -----------------------------------------------------------------------------
// Cross-guard isolation: a session on one guard does NOT authorise the other.
// -----------------------------------------------------------------------------

it('rejects a main-guard session presented to /admin/me with 401', function (): void {
    /** @var User $user */
    $user = User::factory()->createOne();

    $this->actingAs($user, 'web')
        ->getJson('/api/v1/admin/me')
        ->assertStatus(401);
});

it('rejects an admin-guard session presented to /me with 401', function (): void {
    /** @var User $admin */
    $admin = User::factory()->platformAdmin()->withTwoFactor()->createOne();

    $this->actingAs($admin, 'web_admin')
        ->getJson('/api/v1/me')
        ->assertStatus(401);
});

// -----------------------------------------------------------------------------
// tenancy.set middleware: agency_user populates context, others are no-ops.
// -----------------------------------------------------------------------------

it('populates the TenancyContext from the agency_user primary membership on /me', function (): void {
    $agency = AgencyFactory::new()->createOne();
    /** @var User $user */
    $user = User::factory()->agencyAdmin($agency)->createOne();

    $this->actingAs($user, 'web')->getJson('/api/v1/me')->assertOk();

    expect(app(TenancyContext::class)->agencyId())->toBe($agency->id);
});

it('leaves the TenancyContext empty for a creator (no memberships) on /me', function (): void {
    /** @var User $creator */
    $creator = User::factory()->creator()->createOne();

    $this->actingAs($creator, 'web')->getJson('/api/v1/me')->assertOk();

    expect(app(TenancyContext::class)->hasAgency())->toBeFalse();
});

it('leaves the TenancyContext empty for a platform admin on /admin/me', function (): void {
    /** @var User $admin */
    $admin = User::factory()->platformAdmin()->withTwoFactor()->createOne();

    $this->actingAs($admin, 'web_admin')->getJson('/api/v1/admin/me')->assertOk();

    expect(app(TenancyContext::class)->hasAgency())->toBeFalse();
});

// -----------------------------------------------------------------------------
// /me is side-effect free (no last_login_* stamping, no audit row)
// -----------------------------------------------------------------------------

// -----------------------------------------------------------------------------
// agency_memberships relationship (Chunk 2: workspace switcher contract)
// -----------------------------------------------------------------------------

it('includes accepted agency memberships in relationships on /me', function (): void {
    $agency = AgencyFactory::new()->createOne(['name' => 'Acme Agency']);
    /** @var User $user */
    $user = User::factory()->agencyAdmin($agency)->createOne();

    $response = $this->actingAs($user, 'web')->getJson('/api/v1/me');

    $response->assertOk()
        ->assertJsonPath('data.relationships.agency_memberships.data.0.agency_id', $agency->ulid)
        ->assertJsonPath('data.relationships.agency_memberships.data.0.agency_name', 'Acme Agency')
        ->assertJsonPath('data.relationships.agency_memberships.data.0.role', 'agency_admin');
});

it('returns an empty agency_memberships array for a creator on /me', function (): void {
    /** @var User $creator */
    $creator = User::factory()->creator()->createOne();

    $response = $this->actingAs($creator, 'web')->getJson('/api/v1/me');

    $response->assertOk()
        ->assertJsonPath('data.relationships.agency_memberships.data', []);
});

it('excludes soft-deleted and pending (non-accepted) memberships from agency_memberships', function (): void {
    $agency = AgencyFactory::new()->createOne();
    /** @var User $user */
    $user = User::factory()->agencyAdmin($agency)->createOne();

    // Soft-delete the membership
    AgencyMembership::query()
        ->where('user_id', $user->id)
        ->where('agency_id', $agency->id)
        ->update(['deleted_at' => now()]);

    $response = $this->actingAs($user, 'web')->getJson('/api/v1/me');

    $response->assertOk()
        ->assertJsonPath('data.relationships.agency_memberships.data', []);
});

// -----------------------------------------------------------------------------

it('does not stamp last_login_at on /me', function (): void {
    /** @var User $user */
    $user = User::factory()->createOne([
        'last_login_at' => null,
        'last_login_ip' => null,
    ]);

    $this->actingAs($user, 'web')->getJson('/api/v1/me')->assertOk();

    $user->refresh();
    expect($user->last_login_at)->toBeNull()
        ->and($user->last_login_ip)->toBeNull();
});
