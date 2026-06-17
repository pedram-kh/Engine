<?php

declare(strict_types=1);

use App\Core\Tenancy\TenancyContext;
use App\Modules\Agencies\Database\Factories\AgencyFactory;
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
// PATCH /api/v1/me — main SPA, web guard
// -----------------------------------------------------------------------------

it('persists preferred_language and returns the updated resource on the main guard', function (): void {
    /** @var User $user */
    $user = User::factory()->createOne(['preferred_language' => 'en']);

    $response = $this->actingAs($user, 'web')
        ->patchJson('/api/v1/me', ['preferred_language' => 'pt']);

    $response->assertOk()
        ->assertJsonPath('data.id', $user->ulid)
        ->assertJsonPath('data.attributes.preferred_language', 'pt');

    expect($user->fresh()?->preferred_language)->toBe('pt');
});

it('rejects an EU locale we do not render (fr) with a 422 envelope', function (): void {
    /** @var User $user */
    $user = User::factory()->createOne(['preferred_language' => 'en']);

    $this->actingAs($user, 'web')
        ->patchJson('/api/v1/me', ['preferred_language' => 'fr'])
        ->assertEnvelopeValidationErrors(['preferred_language']);

    expect($user->fresh()?->preferred_language)->toBe('en');
});

it('rejects an unknown locale code with a 422 envelope', function (): void {
    /** @var User $user */
    $user = User::factory()->createOne(['preferred_language' => 'en']);

    $this->actingAs($user, 'web')
        ->patchJson('/api/v1/me', ['preferred_language' => 'xx'])
        ->assertEnvelopeValidationErrors(['preferred_language']);
});

it('rejects a missing preferred_language with a 422 envelope', function (): void {
    /** @var User $user */
    $user = User::factory()->createOne();

    $this->actingAs($user, 'web')
        ->patchJson('/api/v1/me', [])
        ->assertEnvelopeValidationErrors(['preferred_language']);
});

it('is locale-only — other fields in the body are ignored', function (): void {
    /** @var User $user */
    $user = User::factory()->createOne([
        'name' => 'Original Name',
        'email' => 'original@example.com',
        'preferred_language' => 'en',
    ]);

    $this->actingAs($user, 'web')
        ->patchJson('/api/v1/me', [
            'preferred_language' => 'it',
            'name' => 'Hacked Name',
            'email' => 'hacked@example.com',
        ])
        ->assertOk();

    $fresh = $user->fresh();
    assert($fresh !== null);
    expect($fresh->preferred_language)->toBe('it')
        ->and($fresh->name)->toBe('Original Name')
        ->and($fresh->email)->toBe('original@example.com');
});

it('returns 401 when no session exists on the main guard', function (): void {
    $this->patchJson('/api/v1/me', ['preferred_language' => 'pt'])->assertStatus(401);
});

// -----------------------------------------------------------------------------
// No-context: the route works without any agency/tenant context.
// -----------------------------------------------------------------------------

it('updates preferred_language for a creator with no agency context', function (): void {
    /** @var User $creator */
    $creator = User::factory()->creator()->createOne(['preferred_language' => 'en']);

    $this->actingAs($creator, 'web')
        ->patchJson('/api/v1/me', ['preferred_language' => 'pt'])
        ->assertOk()
        ->assertJsonPath('data.attributes.preferred_language', 'pt');

    expect($creator->fresh()?->preferred_language)->toBe('pt')
        ->and(app(TenancyContext::class)->hasAgency())->toBeFalse();
});

it('updates preferred_language for an agency user without requiring tenant scoping', function (): void {
    $agency = AgencyFactory::new()->createOne();
    /** @var User $user */
    $user = User::factory()->agencyAdmin($agency)->createOne(['preferred_language' => 'en']);

    $this->actingAs($user, 'web')
        ->patchJson('/api/v1/me', ['preferred_language' => 'it'])
        ->assertOk();

    expect($user->fresh()?->preferred_language)->toBe('it');
});

// -----------------------------------------------------------------------------
// PATCH /api/v1/admin/me — admin SPA, web_admin guard, EnsureMfaForAdmins
// -----------------------------------------------------------------------------

it('persists preferred_language on the admin guard for an MFA-enrolled admin', function (): void {
    /** @var User $admin */
    $admin = User::factory()->platformAdmin()->withTwoFactor()->createOne([
        'preferred_language' => 'en',
    ]);

    $this->actingAs($admin, 'web_admin')
        ->patchJson('/api/v1/admin/me', ['preferred_language' => 'pt'])
        ->assertOk()
        ->assertJsonPath('data.attributes.preferred_language', 'pt');

    expect($admin->fresh()?->preferred_language)->toBe('pt')
        ->and(app(TenancyContext::class)->hasAgency())->toBeFalse();
});

it('returns 403 auth.mfa.enrollment_required for an admin without 2FA on PATCH /admin/me', function (): void {
    config()->set('auth.admin_mfa_enforced', true);
    config()->set('app.env', 'testing');

    /** @var User $admin */
    $admin = User::factory()->platformAdmin()->createOne();

    $this->actingAs($admin, 'web_admin')
        ->patchJson('/api/v1/admin/me', ['preferred_language' => 'pt'])
        ->assertStatus(403)
        ->assertJsonPath('errors.0.code', 'auth.mfa.enrollment_required');
});

it('returns 401 when no session exists on the admin guard', function (): void {
    $this->patchJson('/api/v1/admin/me', ['preferred_language' => 'pt'])->assertStatus(401);
});

// -----------------------------------------------------------------------------
// Cross-guard isolation: a session on one guard does NOT authorise the other.
// -----------------------------------------------------------------------------

it('rejects a main-guard session presented to PATCH /admin/me with 401', function (): void {
    /** @var User $user */
    $user = User::factory()->createOne();

    $this->actingAs($user, 'web')
        ->patchJson('/api/v1/admin/me', ['preferred_language' => 'pt'])
        ->assertStatus(401);
});
