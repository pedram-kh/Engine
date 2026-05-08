<?php

declare(strict_types=1);

use App\Core\Tenancy\SetTenancyContext;
use App\Core\Tenancy\TenancyContext;
use App\Modules\Agencies\Database\Factories\AgencyFactory;
use App\Modules\Agencies\Database\Factories\AgencyMembershipFactory;
use App\Modules\Agencies\Enums\AgencyRole;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

afterEach(function (): void {
    app(TenancyContext::class)->forget();
});

function setTenancyNext(): Closure
{
    return fn (): Response => new Response;
}

it('sets the tenancy context from the authenticated agency user primary membership', function (): void {
    $agency = AgencyFactory::new()->createOne();
    $user = User::factory()->createOne();
    AgencyMembershipFactory::new()->state([
        'agency_id' => $agency->id,
        'user_id' => $user->id,
        'role' => AgencyRole::AgencyAdmin,
        'accepted_at' => now(),
    ])->create();

    $request = Request::create('/api/v1/agencies/foo/brands', 'GET');
    $request->setUserResolver(fn () => $user);

    app(SetTenancyContext::class)->handle($request, setTenancyNext());

    expect(app(TenancyContext::class)->agencyId())->toBe($agency->id);
});

it('is a no-op for an unauthenticated request', function (): void {
    $request = Request::create('/api/v1/health', 'GET');

    app(SetTenancyContext::class)->handle($request, setTenancyNext());

    expect(app(TenancyContext::class)->hasAgency())->toBeFalse();
});

it('is a no-op for a creator (no agency memberships)', function (): void {
    $creator = User::factory()->creator()->createOne();
    $request = Request::create('/api/v1/me', 'GET');
    $request->setUserResolver(fn () => $creator);

    app(SetTenancyContext::class)->handle($request, setTenancyNext());

    expect(app(TenancyContext::class)->hasAgency())->toBeFalse();
});

it('is a no-op for a platform admin (cross-tenant by design)', function (): void {
    $admin = User::factory()->platformAdmin()->createOne();
    $request = Request::create('/api/v1/admin/agencies', 'GET');
    $request->setUserResolver(fn () => $admin);

    app(SetTenancyContext::class)->handle($request, setTenancyNext());

    expect(app(TenancyContext::class)->hasAgency())->toBeFalse();
});

it('picks the lowest-id membership when a user has multiple (Phase 1 invariant: one)', function (): void {
    $a1 = AgencyFactory::new()->createOne();
    $a2 = AgencyFactory::new()->createOne();
    $user = User::factory()->createOne();

    AgencyMembershipFactory::new()->state([
        'agency_id' => $a2->id,
        'user_id' => $user->id,
        'role' => AgencyRole::AgencyManager,
        'accepted_at' => now(),
    ])->create();
    AgencyMembershipFactory::new()->state([
        'agency_id' => $a1->id,
        'user_id' => $user->id,
        'role' => AgencyRole::AgencyAdmin,
        'accepted_at' => now(),
    ])->create();

    $request = Request::create('/api/v1/agencies/foo/brands', 'GET');
    $request->setUserResolver(fn () => $user);

    app(SetTenancyContext::class)->handle($request, setTenancyNext());

    // The first membership written wins (lowest id).
    expect(app(TenancyContext::class)->agencyId())->toBe($a2->id);
});
