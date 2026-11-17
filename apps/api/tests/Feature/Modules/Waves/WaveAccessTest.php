<?php

declare(strict_types=1);

use App\Core\Tenancy\TenancyContext;
use App\Modules\Agencies\Database\Factories\AgencyMembershipFactory;
use App\Modules\Agencies\Enums\AgencyRole;
use App\Modules\Brands\Models\Brand;
use App\Modules\Identity\Models\User;
use App\Modules\Waves\Models\Wave;
use App\Modules\Waves\Policies\WavePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\WaveFixtures;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * PL-1.1 — tenancy (B2) and roles (B7) for waves.
 */

it('B2: agency B cannot read, create or edit agency A\'s waves, on either path, and nothing reveals existence', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();
    $wave = Wave::factory()->forBrand($w['agency']->id, $w['brand']->id)->create();
    // One request per process in production; in a test the context singleton would leak from one request into the
    // next and make a 404 pass for the wrong reason, so it is forgotten before every call.
    $call = function (string $method, string $url, array $body = []) use ($w): TestResponse {
        app(TenancyContext::class)->forget();

        return $this->actingAs($w['otherAdmin'], 'web')->json($method, $url, $body);
    };

    // A's path: B is not a member, so the tenancy middleware answers 404.
    $call('GET', WaveFixtures::wavesUrl($w['agency'], $w['brand']))->assertNotFound();
    $call('POST', WaveFixtures::wavesUrl($w['agency'], $w['brand']), WaveFixtures::body())->assertNotFound();
    $call('PATCH', WaveFixtures::wavesUrl($w['agency'], $w['brand'], $wave->ulid), ['name' => 'Hijack'])->assertNotFound();

    // B's own path with A's brand ulid, and A's wave ulid: the controller's own ownership check must answer 404.
    $call('GET', WaveFixtures::wavesUrl($w['otherAgency'], $w['brand']))->assertNotFound();
    $call('POST', WaveFixtures::wavesUrl($w['otherAgency'], $w['brand']), WaveFixtures::body())->assertNotFound();
    $call('GET', WaveFixtures::wavesUrl($w['otherAgency'], $w['brand'], 'campaign-options'))->assertNotFound();
    $call('GET', WaveFixtures::wavesUrl($w['otherAgency'], $w['otherBrand'], $wave->ulid))->assertNotFound();
    $call('PATCH', WaveFixtures::wavesUrl($w['otherAgency'], $w['otherBrand'], $wave->ulid), ['name' => 'Hijack'])->assertNotFound();

    expect(Wave::withoutGlobalScopes()->count())->toBe(1)
        ->and(Wave::withoutGlobalScopes()->findOrFail($wave->id)->name)->toBe($wave->name);
});

it('B2: a wave of brand 2 requested under brand 1 of the same agency is 404', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();
    $brandTwo = Brand::factory()->forAgency($w['agency']->id)->createOne();
    $wave = Wave::factory()->forBrand($w['agency']->id, $brandTwo->id)->create();

    $this->actingAs($w['admin'], 'web')->getJson(WaveFixtures::wavesUrl($w['agency'], $w['brand'], $wave->ulid))->assertNotFound();
    $this->actingAs($w['admin'], 'web')->patchJson(WaveFixtures::wavesUrl($w['agency'], $w['brand'], $wave->ulid), ['name' => 'X'])->assertNotFound();
    $this->actingAs($w['admin'], 'web')->getJson(WaveFixtures::wavesUrl($w['agency'], $w['brand']))->assertOk()->assertJsonPath('meta.total', 0);
});

it('B2: a soft-deleted wave is a 404', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();
    $wave = Wave::factory()->forBrand($w['agency']->id, $w['brand']->id)->create();
    $wave->delete();

    $this->actingAs($w['admin'], 'web')->getJson(WaveFixtures::wavesUrl($w['agency'], $w['brand'], $wave->ulid))->assertNotFound();
});

it('B2: the BelongsToAgency scope: a query under agency B\'s context never returns agency A\'s waves', function (): void {
    $w = WaveFixtures::world();
    Wave::factory()->forBrand($w['agency']->id, $w['brand']->id)->create();

    $context = app(TenancyContext::class);
    $context->setAgencyId($w['otherAgency']->id);

    expect(Wave::query()->count())->toBe(0);

    $context->setAgencyId($w['agency']->id);
    expect(Wave::query()->count())->toBe(1);
    $context->forget();
});

it('B7: admin and manager create and edit; staff may not, but can list, show and read campaign options', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();
    $wave = Wave::factory()->forBrand($w['agency']->id, $w['brand']->id)->create();
    $url = fn (string $s = ''): string => WaveFixtures::wavesUrl($w['agency'], $w['brand'], $s);

    foreach (['admin', 'manager'] as $role) {
        $this->actingAs($w[$role], 'web')->postJson($url(), WaveFixtures::body(['name' => "By {$role}"]))->assertCreated();
        $this->actingAs($w[$role], 'web')->patchJson($url($wave->ulid), ['name' => "Edited by {$role}"])->assertOk();
    }

    $staff = $this->actingAs($w['staff'], 'web');
    $staff->postJson($url(), WaveFixtures::body())->assertForbidden();
    $staff->patchJson($url($wave->ulid), ['name' => 'Nope'])->assertForbidden();
    $staff->getJson($url())->assertOk();
    $staff->getJson($url($wave->ulid))->assertOk();
    $staff->getJson($url('campaign-options'))->assertOk();

    expect(Wave::query()->count())->toBe(3)
        ->and($wave->fresh()?->name)->toBe('Edited by manager');
});

it('B7: the policy judges the role in the route agency: staff in A and admin in B is staff in A', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();
    $mixed = User::factory()->agencyStaff($w['agency'])->createOne();
    // Same user, admin in the other agency.
    AgencyMembershipFactory::new()->state([
        'agency_id' => $w['otherAgency']->id,
        'user_id' => $mixed->id,
        'role' => AgencyRole::AgencyAdmin,
        'accepted_at' => now(),
    ])->create();

    $this->actingAs($mixed, 'web')->postJson(WaveFixtures::wavesUrl($w['agency'], $w['brand']), WaveFixtures::body())->assertForbidden();
    // One request per process in production; in a test the context singleton would leak into the next request.
    app(TenancyContext::class)->forget();
    $this->actingAs($mixed, 'web')->postJson(WaveFixtures::wavesUrl($w['otherAgency'], $w['otherBrand']), WaveFixtures::body())->assertCreated();
});

it('B7: policy unit: every action for admin, manager and staff, and no access without a context', function (): void {
    $w = WaveFixtures::world();
    $policy = new WavePolicy;
    $wave = Wave::factory()->forBrand($w['agency']->id, $w['brand']->id)->create();
    $context = app(TenancyContext::class);

    $context->forget();
    expect($policy->viewAny($w['admin']))->toBeFalse()
        ->and($policy->create($w['admin']))->toBeFalse();

    $context->setAgencyId($w['agency']->id);
    expect([$policy->viewAny($w['admin']), $policy->view($w['admin'], $wave), $policy->create($w['admin']), $policy->update($w['admin'], $wave)])->toBe([true, true, true, true])
        ->and([$policy->viewAny($w['manager']), $policy->view($w['manager'], $wave), $policy->create($w['manager']), $policy->update($w['manager'], $wave)])->toBe([true, true, true, true])
        ->and([$policy->viewAny($w['staff']), $policy->view($w['staff'], $wave), $policy->create($w['staff']), $policy->update($w['staff'], $wave)])->toBe([true, true, false, false])
        ->and($policy->viewAny($w['otherAdmin']))->toBeFalse();
    $context->forget();
});
