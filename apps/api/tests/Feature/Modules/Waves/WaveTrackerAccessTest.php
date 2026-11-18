<?php

declare(strict_types=1);

use App\Modules\Brands\Models\Brand;
use App\Modules\Waves\Features\WavesEnabled;
use App\Modules\Waves\Models\Wave;
use App\Modules\Waves\Models\WaveStep;
use App\Modules\Waves\Steps\WaveStepKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Tests\Support\TrackerFixtures as T;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * PL-1.3 — F1 (flag), TN1 (tenancy) and R1 to R6 (roles) for the tracker routes.
 */

it('F1: every tracker route answers 404 with the flag off, and nothing is written', function (): void {
    $w = T::world(provisioned: false);
    Feature::deactivate(WavesEnabled::NAME);
    $step = WaveStep::factory()->create(['wave_id' => $w['wave']->id]);
    $before = WaveStep::query()->count();

    $routes = [
        ['GET', 'tracker', []],
        ['POST', 'steps', ['stream' => 'statics', 'name' => 'X']],
        ['PATCH', 'steps/'.$step->ulid, ['status' => 'done']],
        ['DELETE', 'steps/'.$step->ulid, []],
        ['DELETE', 'streams/statics', []],
        ['POST', 'streams/statics', []],
    ];
    foreach ($routes as [$method, $suffix, $body]) {
        T::call($w['admin'], $method, T::url($w['agency'], $w['brand'], $w['wave'], $suffix), $body)->assertNotFound();
    }

    expect(WaveStep::query()->count())->toBe($before)
        ->and(Wave::query()->findOrFail($w['wave']->id)->steps_provisioned_at)->toBeNull();

    // The existing list stays reachable only behind the flag too, and carries no progress while off.
    T::call($w['admin'], 'GET', T::url($w['agency'], $w['brand'], $w['wave']))->assertNotFound();
});

it('F1: the tracker routes in the route table are exactly the six this file exercises', function (): void {
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(static fn ($r): bool => preg_match('#/waves/\{wave\}/(tracker|steps|streams)#', $r->uri()) === 1)
        ->map(static fn ($r): string => implode('|', array_diff($r->methods(), ['HEAD'])).' '.preg_replace('#^api/v1/agencies/\{agency\}/brands/\{brand\}/waves/\{wave\}/#', '', $r->uri()))
        ->sort()->values()->all();

    expect($routes)->toBe([
        'DELETE steps/{step}',
        'DELETE streams/{stream}',
        'GET tracker',
        'PATCH steps/{step}',
        'POST steps',
        'POST streams/{stream}',
    ]);
});

it('TN1: another agency cannot reach the tracker, a step or a stream, and nothing reveals existence', function (): void {
    $w = T::world();
    $step = T::step($w['wave'], WaveStepKey::STATICS_DESIGN_V1);

    $own = fn (string $method, string $suffix, array $body = []) => T::call($w['otherAdmin'], $method, T::url($w['otherAgency'], $w['otherBrand'], $w['wave'], $suffix), $body);
    $foreign = fn (string $method, string $suffix, array $body = []) => T::call($w['otherAdmin'], $method, T::url($w['agency'], $w['brand'], $w['wave'], $suffix), $body);

    foreach ([$own, $foreign] as $call) {
        $call('GET', 'tracker')->assertNotFound();
        $call('POST', 'steps', ['stream' => 'statics', 'name' => 'X'])->assertNotFound();
        $call('PATCH', 'steps/'.$step->ulid, ['status' => 'done'])->assertNotFound();
        $call('DELETE', 'steps/'.$step->ulid)->assertNotFound();
        $call('DELETE', 'streams/statics')->assertNotFound();
        $call('POST', 'streams/statics')->assertNotFound();
    }

    expect(WaveStep::query()->where('wave_id', $w['wave']->id)->count())->toBe(24)
        ->and($step->fresh()?->status->value)->toBe('not_started');
});

it('TN1: a step is not reachable through another wave of the same agency, nor a wave through another brand', function (): void {
    $w = T::world();
    $other = Wave::factory()->forBrand($w['agency']->id, $w['brand']->id)->create();
    $step = T::step($w['wave'], WaveStepKey::STATICS_DESIGN_V1);

    T::call($w['admin'], 'PATCH', T::url($w['agency'], $w['brand'], $other, 'steps/'.$step->ulid), ['status' => 'done'])->assertNotFound();
    T::call($w['admin'], 'DELETE', T::url($w['agency'], $w['brand'], $other, 'steps/'.$step->ulid))->assertNotFound();

    $brandTwo = Brand::factory()->forAgency($w['agency']->id)->createOne();
    T::call($w['admin'], 'GET', T::url($w['agency'], $brandTwo, $w['wave'], 'tracker'))->assertNotFound();

    expect($step->fresh()?->status->value)->toBe('not_started')
        ->and(WaveStep::query()->where('wave_id', $w['wave']->id)->count())->toBe(24);
});

it('TN1: a soft-deleted wave is a 404 on the tracker', function (): void {
    $w = T::world();
    $w['wave']->delete();

    T::call($w['admin'], 'GET', T::url($w['agency'], $w['brand'], $w['wave'], 'tracker'))->assertNotFound();
});

it('R1: admin, manager and staff can view the tracker', function (): void {
    $w = T::world();

    foreach (['admin', 'manager', 'staff'] as $role) {
        T::call($w[$role], 'GET', T::url($w['agency'], $w['brand'], $w['wave'], 'tracker'))->assertOk()
            ->assertJsonCount(24, 'data.attributes.steps')
            ->assertJsonCount(3, 'data.attributes.streams');
    }
});

it('R2: admin and manager can add a step; staff cannot (403, nothing written)', function (): void {
    $w = T::world();
    $url = T::url($w['agency'], $w['brand'], $w['wave'], 'steps');
    $body = ['stream' => 'statics', 'name' => 'Resize for Stories'];

    T::call($w['staff'], 'POST', $url, $body)->assertForbidden();
    expect(WaveStep::query()->where('wave_id', $w['wave']->id)->count())->toBe(24);

    foreach (['admin', 'manager'] as $role) {
        T::call($w[$role], 'POST', $url, $body)->assertCreated()->assertJsonPath('data.attributes.name', 'Resize for Stories');
    }
    expect(WaveStep::query()->where('wave_id', $w['wave']->id)->count())->toBe(26);
});

it('R3: admin and manager can remove a step; staff cannot', function (): void {
    $w = T::world();

    $a = T::step($w['wave'], WaveStepKey::STATICS_DESIGN_V1);
    $b = T::step($w['wave'], WaveStepKey::STATICS_FINAL_EXPORT);
    T::call($w['staff'], 'DELETE', T::url($w['agency'], $w['brand'], $w['wave'], 'steps/'.$a->ulid))->assertForbidden();
    expect($a->fresh())->not->toBeNull();

    T::call($w['admin'], 'DELETE', T::url($w['agency'], $w['brand'], $w['wave'], 'steps/'.$a->ulid))->assertNoContent();
    T::call($w['manager'], 'DELETE', T::url($w['agency'], $w['brand'], $w['wave'], 'steps/'.$b->ulid))->assertNoContent();
    expect(WaveStep::query()->where('wave_id', $w['wave']->id)->count())->toBe(22);
});

it('R4: admin, manager and staff can change status, owner (member or Client) and dates', function (): void {
    $w = T::world();

    foreach (['admin', 'manager', 'staff'] as $i => $role) {
        $step = T::step($w['wave'], [WaveStepKey::STATICS_DESIGN_V1, WaveStepKey::STATICS_INTERNAL_REVIEW, WaveStepKey::STATICS_FINAL_EXPORT][$i]);
        T::call($w[$role], 'PATCH', T::url($w['agency'], $w['brand'], $w['wave'], 'steps/'.$step->ulid), [
            'status' => 'in_progress', 'owner' => $w['member']->ulid, 'starts_on' => '2026-11-20', 'due_on' => '2026-11-25',
        ])->assertOk()->assertJsonPath('data.attributes.status', 'in_progress')->assertJsonPath('data.attributes.owner.id', $w['member']->ulid);

        T::call($w[$role], 'PATCH', T::url($w['agency'], $w['brand'], $w['wave'], 'steps/'.$step->ulid), ['owner_client' => true])
            ->assertOk()->assertJsonPath('data.attributes.owner_client', true)->assertJsonPath('data.attributes.owner', null);
    }
});

it('R5: admin and manager can rename and move a step; staff cannot, and a mixed PATCH from staff is refused whole', function (): void {
    $w = T::world();
    $step = T::step($w['wave'], WaveStepKey::STATICS_DESIGN_V1);
    $url = T::url($w['agency'], $w['brand'], $w['wave'], 'steps/'.$step->ulid);

    T::call($w['staff'], 'PATCH', $url, ['name' => 'Renamed'])->assertForbidden();
    T::call($w['staff'], 'PATCH', $url, ['stream' => 'stills'])->assertForbidden();
    // Mixed: the allowed status is NOT applied when a forbidden field rides along.
    T::call($w['staff'], 'PATCH', $url, ['status' => 'done', 'name' => 'Renamed'])->assertForbidden();
    expect($step->fresh()?->status->value)->toBe('not_started')->and($step->fresh()?->name)->toBe('Design v1');

    T::call($w['admin'], 'PATCH', $url, ['name' => 'Design v1 (final)'])->assertOk()->assertJsonPath('data.attributes.name', 'Design v1 (final)');
    T::call($w['manager'], 'PATCH', $url, ['stream' => 'stills'])->assertOk()->assertJsonPath('data.attributes.stream', 'stills');
});

it('R6: admin and manager can remove and add a stream; staff cannot', function (): void {
    $w = T::world();
    $url = T::url($w['agency'], $w['brand'], $w['wave'], 'streams/statics');

    T::call($w['staff'], 'DELETE', $url)->assertForbidden();
    T::call($w['staff'], 'POST', $url)->assertForbidden();
    expect(WaveStep::query()->where('wave_id', $w['wave']->id)->where('stream', 'statics')->count())->toBe(5);

    T::call($w['admin'], 'DELETE', $url)->assertOk();
    T::call($w['staff'], 'POST', $url)->assertForbidden();
    T::call($w['manager'], 'POST', $url)->assertOk();
    T::call($w['manager'], 'DELETE', $url)->assertOk();
    T::call($w['admin'], 'POST', $url)->assertOk();
    expect(WaveStep::query()->where('wave_id', $w['wave']->id)->where('stream', 'statics')->count())->toBe(5);
});

it('R1-R6: an unknown stream value is a 404, an unknown step ulid is a 404', function (): void {
    $w = T::world();

    T::call($w['admin'], 'DELETE', T::url($w['agency'], $w['brand'], $w['wave'], 'streams/podcasts'))->assertNotFound();
    T::call($w['admin'], 'PATCH', T::url($w['agency'], $w['brand'], $w['wave'], 'steps/01ARZ3NDEKTSV4RRFFQ69G5FAV'), ['status' => 'done'])->assertNotFound();
});
