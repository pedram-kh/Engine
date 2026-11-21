<?php

declare(strict_types=1);

use App\Core\Tenancy\TenancyContext;
use App\Modules\Agencies\Database\Factories\AgencyMembershipFactory;
use App\Modules\Agencies\Enums\AgencyRole;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Models\WavePersona;
use App\Modules\Strategy\Models\WavePersonaAngle;
use App\Modules\Strategy\Models\WaveUsp;
use App\Modules\Strategy\Policies\WaveStrategyPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\StrategyFixtures as S;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * PL-2.1 S6 roles (R1 to R6) and tenancy (T1). The USP and persona API specs cover the per-endpoint role cases; this
 * file covers who is let in at all, the policy matrix and the BelongsToAgency scope of the three models.
 */

it('R6: a creator, a platform admin and a guest are refused on every strategy route', function (): void {
    $w = S::world();
    $creator = User::factory()->creator()->createOne();
    $platform = User::factory()->create(['type' => UserType::PlatformAdmin, 'two_factor_confirmed_at' => now()]);

    foreach (S::routeTable() as [$method, $suffix]) {
        foreach ([$creator, $platform] as $who) {
            $res = S::call($who, $method, S::u($w, $suffix), ['title' => 'x']);
            expect($res->status())->toBeIn([403, 404], "{$method} {$suffix} must not be open to a {$who->type->value}");
        }
        app(TenancyContext::class)->forget();
        auth()->guard('web')->logout();
        $this->json($method, S::u($w, $suffix), ['title' => 'x'])->assertUnauthorized();
    }
    expect(WaveUsp::query()->count())->toBe(0);
});

it('R1 to R5: policy unit: every action for admin, manager and staff; nothing without a context or in another agency', function (): void {
    $w = S::world();
    $policy = new WaveStrategyPolicy;
    $context = app(TenancyContext::class);

    $context->forget();
    expect($policy->view($w['admin']))->toBeFalse()->and($policy->deleteInUse($w['admin']))->toBeFalse();

    $context->setAgencyId($w['agency']->id);
    foreach (['admin', 'manager'] as $role) {
        expect([$policy->view($w[$role]), $policy->create($w[$role]), $policy->update($w[$role]), $policy->delete($w[$role]), $policy->deleteInUse($w[$role])])
            ->toBe([true, true, true, true, true]);
    }
    expect([$policy->view($w['staff']), $policy->create($w['staff']), $policy->update($w['staff']), $policy->delete($w['staff']), $policy->deleteInUse($w['staff'])])
        ->toBe([true, true, true, true, false])
        ->and($policy->view($w['otherAdmin']))->toBeFalse()->and($policy->deleteInUse($w['otherAdmin']))->toBeFalse();
    $context->forget();
});

it('R3: the role is judged in the route agency: staff in A and admin in B is staff in A (cannot delete an in-use USP)', function (): void {
    $w = S::world();
    $mixed = User::factory()->agencyStaff($w['agency'])->createOne();
    AgencyMembershipFactory::new()->state([
        'agency_id' => $w['otherAgency']->id, 'user_id' => $mixed->id, 'role' => AgencyRole::AgencyAdmin, 'accepted_at' => now(),
    ])->create();
    $usp = WaveUsp::factory()->create(['wave_id' => $w['wave']->id, 'agency_id' => $w['agency']->id]);
    $persona = WavePersona::factory()->create(['wave_id' => $w['wave']->id, 'agency_id' => $w['agency']->id]);
    WavePersonaAngle::factory()->create(['persona_id' => $persona->id, 'usp_id' => $usp->id, 'wave_id' => $w['wave']->id, 'agency_id' => $w['agency']->id]);

    S::call($mixed, 'DELETE', S::u($w, "usps/{$usp->ulid}?confirm_count=1"))->assertForbidden();
    expect(WaveUsp::query()->count())->toBe(1);
});

it('T1: the BelongsToAgency scope: under agency B the three models never return agency A rows', function (): void {
    $w = S::world();
    $usp = WaveUsp::factory()->create(['wave_id' => $w['wave']->id, 'agency_id' => $w['agency']->id]);
    $persona = WavePersona::factory()->create(['wave_id' => $w['wave']->id, 'agency_id' => $w['agency']->id]);
    WavePersonaAngle::factory()->create(['persona_id' => $persona->id, 'usp_id' => $usp->id, 'wave_id' => $w['wave']->id, 'agency_id' => $w['agency']->id]);

    $context = app(TenancyContext::class);
    $context->setAgencyId($w['otherAgency']->id);
    expect([WaveUsp::query()->count(), WavePersona::query()->count(), WavePersonaAngle::query()->count()])->toBe([0, 0, 0]);

    $context->setAgencyId($w['agency']->id);
    expect([WaveUsp::query()->count(), WavePersona::query()->count(), WavePersonaAngle::query()->count()])->toBe([1, 1, 1]);
    $context->forget();
});

it('T1: writes made under agency A put agency A on every row (agency_id comes from the wave, never the request)', function (): void {
    $w = S::world();
    $usp = S::call($w['admin'], 'POST', S::u($w, 'usps'), ['title' => 'U', 'agency_id' => $w['otherAgency']->id, 'wave_id' => 999])->assertCreated()->json('data.id');
    $p = S::call($w['admin'], 'POST', S::u($w, 'personas'), ['market' => $w['mk']['SK'], 'title' => 'T', 'name' => 'N', 'agency_id' => $w['otherAgency']->id, 'slot' => 8])->assertCreated()->json('data.id');
    S::call($w['admin'], 'POST', S::u($w, "personas/{$p}/angles"), ['usp' => $usp, 'explanation' => 'x', 'agency_id' => $w['otherAgency']->id, 'origin' => 'ai'])->assertCreated();

    $owners = [
        WaveUsp::withoutGlobalScopes()->get(['agency_id', 'wave_id'])->toArray(),
        WavePersona::withoutGlobalScopes()->get(['agency_id', 'wave_id'])->toArray(),
        WavePersonaAngle::withoutGlobalScopes()->get(['agency_id', 'wave_id'])->toArray(),
    ];
    foreach ($owners as $rows) {
        expect($rows)->toBe([['agency_id' => $w['agency']->id, 'wave_id' => $w['wave']->id]]);
    }
    expect(WavePersona::query()->firstOrFail()->slot)->toBe(1)->and(WavePersonaAngle::query()->firstOrFail()->origin->value)->toBe('manual');
});
