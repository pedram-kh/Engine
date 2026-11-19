<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Strategy\Features\StrategyEnabled;
use App\Modules\Strategy\Models\WavePersona;
use App\Modules\Strategy\Models\WaveUsp;
use App\Modules\Waves\Features\WavesEnabled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Tests\Support\StrategyFixtures as S;
use Tests\Support\WaveFixtures;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * PL-2.1 K1 and K2 — with `strategy_enabled` OFF, or with `waves_enabled` OFF (the strategy flag needs it), EVERY
 * strategy route answers 404 `not_found`, writes nothing and audits nothing. The table below is checked against the
 * router, so a new strategy route fails here until it is added.
 */

it('K1, K2: the route table of the strategy module is covered by this gate test', function (): void {
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(static fn ($r): bool => str_contains($r->uri(), '/waves/{wave}/strategy'))
        ->flatMap(static fn ($r): array => collect($r->methods())->reject(fn ($m) => in_array($m, ['HEAD'], true))->map(fn ($m) => $m.' '.$r->uri())->all())
        ->count();

    expect($routes)->toBe(count(S::routeTable()));
});

it('K1: every strategy route is 404 not_found while strategy_enabled is OFF (waves ON), writes and audits nothing', function (): void {
    $w = S::world();
    Feature::deactivate(StrategyEnabled::NAME);
    $before = AuditLog::query()->count();

    foreach (S::routeTable() as [$method, $suffix]) {
        S::call($w['admin'], $method, S::u($w, $suffix), ['title' => 'x', 'name' => 'x', 'market' => 'SK', 'order' => [], 'usp_audience_title' => 'x', 'from_market' => 'SK', 'to_market' => 'RO', 'usp' => 'x', 'explanation' => 'x'])
            ->assertNotFound()->assertJsonPath('errors.0.code', 'not_found');
    }
    expect(WaveUsp::query()->count())->toBe(0)->and(WavePersona::query()->count())->toBe(0)->and(AuditLog::query()->count())->toBe($before);
});

it('K2: every strategy route is 404 not_found while waves_enabled is OFF even with strategy_enabled ON', function (): void {
    $w = S::world();
    Feature::deactivate(WavesEnabled::NAME);
    Feature::activate(StrategyEnabled::NAME);

    foreach (S::routeTable() as [$method, $suffix]) {
        S::call($w['admin'], $method, S::u($w, $suffix), ['title' => 'x'])->assertNotFound()->assertJsonPath('errors.0.code', 'not_found');
    }
});

it('K1: the strategy flag changes nothing in the wave detail or the tracker answers (PL-2.1 does not touch them)', function (): void {
    $w = S::world();
    $detail = WaveFixtures::wavesUrl($w['agency'], $w['brand'], $w['wave']->ulid);

    Feature::deactivate(StrategyEnabled::NAME);
    $offDetail = S::call($w['admin'], 'GET', $detail)->assertOk()->json();
    $offTracker = S::call($w['admin'], 'GET', $detail.'/tracker')->assertOk()->json();

    Feature::activate(StrategyEnabled::NAME);
    expect(S::call($w['admin'], 'GET', $detail)->assertOk()->json())->toBe($offDetail)
        ->and(S::call($w['admin'], 'GET', $detail.'/tracker')->assertOk()->json())->toBe($offTracker);
});
