<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Brands\Enums\BrandStatus;
use App\Modules\Waves\Models\Wave;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\WaveFixtures;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * PL-1.1 — the wave endpoints: flag gate (B1), validation and persistence (B8), audit (B10), shape (B12),
 * the archived-brand rule on create and edit (B8 / C4).
 */

it('B1: every wave route answers 404 not_found while the flag is OFF, writes nothing and audits nothing', function (): void {
    $w = WaveFixtures::world();
    $wave = Wave::factory()->forBrand($w['agency']->id, $w['brand']->id)->create();
    $url = fn (string $s = ''): string => WaveFixtures::wavesUrl($w['agency'], $w['brand'], $s);
    $actor = $this->actingAs($w['admin'], 'web');

    $actor->getJson($url())->assertNotFound()->assertJsonPath('errors.0.code', 'not_found');
    $actor->postJson($url(), WaveFixtures::body())->assertNotFound()->assertJsonPath('errors.0.code', 'not_found');
    $actor->getJson($url('campaign-options'))->assertNotFound()->assertJsonPath('errors.0.code', 'not_found');
    $actor->getJson($url($wave->ulid))->assertNotFound()->assertJsonPath('errors.0.code', 'not_found');
    $actor->patchJson($url($wave->ulid), ['name' => 'Changed'])->assertNotFound()->assertJsonPath('errors.0.code', 'not_found');

    expect(Wave::query()->count())->toBe(1)
        ->and($wave->fresh()?->name)->toBe($wave->name)
        ->and(AuditLog::query()->where('action', 'like', 'wave.%')->count())->toBe(0);
});

it('B1: route table: every route in the waves route file is covered by the flag gate test above', function (): void {
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(static fn ($r): bool => str_contains($r->uri(), '/waves'))
        ->map(static fn ($r): string => implode('|', $r->methods()).' '.$r->uri())
        ->values()->all();

    // The gate test above exercises exactly these five. A sixth route fails here until it is added there.
    expect($routes)->toHaveCount(5);
});

it('creates a wave, lists it, shows it', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();

    $created = $this->actingAs($w['admin'], 'web')
        ->postJson(WaveFixtures::wavesUrl($w['agency'], $w['brand']), WaveFixtures::body(['name' => '  Wave 7  ']))
        ->assertCreated();

    $id = $created->json('data.id');
    expect($created->json('data.attributes.name'))->toBe('Wave 7')
        ->and($created->json('data.attributes.status'))->toBe('draft');

    $this->actingAs($w['admin'], 'web')->getJson(WaveFixtures::wavesUrl($w['agency'], $w['brand']))
        ->assertOk()->assertJsonPath('data.0.id', $id)->assertJsonPath('meta.total', 1);
    $this->actingAs($w['admin'], 'web')->getJson(WaveFixtures::wavesUrl($w['agency'], $w['brand'], $id))
        ->assertOk()->assertJsonPath('data.attributes.markets', ['SK', 'RO']);
});

it('B8: every accepted create field persists and is returned', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();
    $campaign = WaveFixtures::campaign($w['agency'], $w['brand']);

    $res = $this->actingAs($w['admin'], 'web')
        ->postJson(WaveFixtures::wavesUrl($w['agency'], $w['brand']), WaveFixtures::body([
            'name' => 'Persisted', 'go_live_on' => '2025-01-31', 'markets' => ['DE', 'FR', 'IT'], 'campaign_id' => $campaign->ulid,
        ]))->assertCreated();

    $row = Wave::query()->firstOrFail();
    expect($row->name)->toBe('Persisted')
        ->and($row->go_live_on->format('Y-m-d'))->toBe('2025-01-31')
        ->and($row->markets)->toBe(['DE', 'FR', 'IT'])
        ->and($row->campaign_id)->toBe($campaign->id)
        ->and($row->created_by_user_id)->toBe($w['admin']->id)
        ->and($row->agency_id)->toBe($w['agency']->id)
        ->and($row->brand_id)->toBe($w['brand']->id)
        ->and($res->json('data.attributes.go_live_on'))->toBe('2025-01-31')
        ->and($res->json('data.relationships.campaign.data.id'))->toBe($campaign->ulid);
});

it('B8: refuses each invalid create body with 422 and writes nothing', function (array $override, string $field): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();

    $this->actingAs($w['admin'], 'web')
        ->postJson(WaveFixtures::wavesUrl($w['agency'], $w['brand']), WaveFixtures::body($override))
        ->assertUnprocessable()->assertJsonPath('errors.0.meta.field', $field);

    expect(Wave::query()->count())->toBe(0);
})->with([
    'empty name' => [['name' => ''], 'name'],
    '161-character name' => [['name' => str_repeat('a', 161)], 'name'],
    'not a date' => [['go_live_on' => 'tomorrow'], 'go_live_on'],
    'wrong date format' => [['go_live_on' => '01/12/2026'], 'go_live_on'],
    'no markets' => [['markets' => []], 'markets'],
    '21 markets' => [['markets' => array_map(static fn (int $i): string => chr(65 + intdiv($i, 26)).chr(65 + $i % 26), range(0, 20))], 'markets'],
    'lowercase market' => [['markets' => ['sk']], 'markets.0'],
    'three-letter market' => [['markets' => ['SVK']], 'markets.0'],
    'duplicate market' => [['markets' => ['SK', 'SK']], 'markets.0'],
    'non-string market' => [['markets' => [12]], 'markets.0'],
    'wrong-length campaign id' => [['campaign_id' => 'abc'], 'campaign_id'],
]);

it('B8: accepts exactly 20 markets and a past go-live date', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();
    $twenty = array_map(static fn (int $i): string => 'A'.chr(65 + $i), range(0, 19));

    $this->actingAs($w['admin'], 'web')
        ->postJson(WaveFixtures::wavesUrl($w['agency'], $w['brand']), WaveFixtures::body(['markets' => $twenty, 'go_live_on' => '2020-01-01']))
        ->assertCreated();
});

it('B8: a wave of an archived brand cannot be created: 422 wave.brand_archived', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();
    $w['brand']->update(['status' => BrandStatus::Archived]);
    $w['brand']->delete();

    $this->actingAs($w['admin'], 'web')
        ->postJson(WaveFixtures::wavesUrl($w['agency'], $w['brand']), WaveFixtures::body())
        ->assertUnprocessable()->assertJsonPath('errors.0.code', 'wave.brand_archived');

    expect(Wave::query()->count())->toBe(0);
});

it('C4: a PATCH on a wave of an archived brand is refused with 422 wave.brand_archived and changes nothing', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();
    $wave = Wave::factory()->forBrand($w['agency']->id, $w['brand']->id)->create(['name' => 'Before']);
    $w['brand']->update(['status' => BrandStatus::Archived]);
    $w['brand']->delete();

    $this->actingAs($w['admin'], 'web')
        ->patchJson(WaveFixtures::wavesUrl($w['agency'], $w['brand'], $wave->ulid), ['name' => 'After'])
        ->assertUnprocessable()->assertJsonPath('errors.0.code', 'wave.brand_archived');

    expect($wave->fresh()?->name)->toBe('Before')
        ->and(AuditLog::query()->where('action', 'wave.updated')->count())->toBe(0);
});

it('B8: an archived brand still lists and shows its waves (read-only page)', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();
    $wave = Wave::factory()->forBrand($w['agency']->id, $w['brand']->id)->create();
    $w['brand']->update(['status' => BrandStatus::Archived]);
    $w['brand']->delete();

    $this->actingAs($w['staff'], 'web')->getJson(WaveFixtures::wavesUrl($w['agency'], $w['brand']))
        ->assertOk()->assertJsonPath('meta.total', 1);
    $this->actingAs($w['staff'], 'web')->getJson(WaveFixtures::wavesUrl($w['agency'], $w['brand'], $wave->ulid))->assertOk();
});

it('edits name, go-live date and markets, and ignores an empty body', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();
    $wave = Wave::factory()->forBrand($w['agency']->id, $w['brand']->id)->create();
    $url = WaveFixtures::wavesUrl($w['agency'], $w['brand'], $wave->ulid);

    $this->actingAs($w['manager'], 'web')
        ->patchJson($url, ['name' => 'Renamed', 'go_live_on' => '2027-02-02', 'markets' => ['PL']])
        ->assertOk()->assertJsonPath('data.attributes.name', 'Renamed');

    $row = $wave->fresh();
    expect($row?->name)->toBe('Renamed')
        ->and($row?->go_live_on->format('Y-m-d'))->toBe('2027-02-02')
        ->and($row?->markets)->toBe(['PL']);

    $this->actingAs($w['manager'], 'web')->patchJson($url, [])->assertOk();
    expect(AuditLog::query()->where('action', 'wave.updated')->count())->toBe(1);
});

it('B8: a PATCH refuses an invalid value with 422', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();
    $wave = Wave::factory()->forBrand($w['agency']->id, $w['brand']->id)->create();

    $this->actingAs($w['admin'], 'web')
        ->patchJson(WaveFixtures::wavesUrl($w['agency'], $w['brand'], $wave->ulid), ['markets' => ['sk']])
        ->assertUnprocessable()->assertJsonPath('errors.0.meta.field', 'markets.0');
});

it('B10: audits wave.created and wave.updated with actor, agency and subject, and nothing on a refused request', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();

    $id = $this->actingAs($w['admin'], 'web')
        ->postJson(WaveFixtures::wavesUrl($w['agency'], $w['brand']), WaveFixtures::body())->assertCreated()->json('data.id');
    $this->actingAs($w['admin'], 'web')
        ->patchJson(WaveFixtures::wavesUrl($w['agency'], $w['brand'], $id), ['name' => 'Second'])->assertOk();

    $created = AuditLog::query()->where('action', 'wave.created')->firstOrFail();
    $updated = AuditLog::query()->where('action', 'wave.updated')->firstOrFail();
    expect($created->actor_id)->toBe($w['admin']->id)
        ->and($created->agency_id)->toBe($w['agency']->id)
        ->and($created->subject_type)->not->toBeNull()
        ->and($updated->actor_id)->toBe($w['admin']->id);

    // A refused request audits nothing.
    $this->actingAs($w['admin'], 'web')->postJson(WaveFixtures::wavesUrl($w['agency'], $w['brand']), WaveFixtures::body(['name' => '']))->assertUnprocessable();
    expect(AuditLog::query()->where('action', 'wave.created')->count())->toBe(1);
});

it('B12: the resource shape carries ulids only: no internal id, no agency_id; dates as Y-m-d and ISO', function (): void {
    $w = WaveFixtures::world();
    WaveFixtures::armed();
    $campaign = WaveFixtures::campaign($w['agency'], $w['brand'], 'active', 'Bolt Summer');
    $wave = Wave::factory()->forBrand($w['agency']->id, $w['brand']->id)->linkedTo($campaign->id)->create(['go_live_on' => '2026-11-07']);

    $json = $this->actingAs($w['admin'], 'web')
        ->getJson(WaveFixtures::wavesUrl($w['agency'], $w['brand'], $wave->ulid))->assertOk()->json('data');

    expect($json['id'])->toBe($wave->ulid)
        ->and($json['attributes'])->not->toHaveKeys(['agency_id', 'brand_id', 'campaign_id', 'created_by_user_id'])
        ->and($json['attributes']['go_live_on'])->toBe('2026-11-07')
        ->and($json['attributes']['created_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/')
        ->and($json['relationships']['campaign']['data']['id'])->toBe($campaign->ulid)
        ->and($json['relationships']['brand']['data']['id'])->toBe($w['brand']->ulid)
        ->and(json_encode($json))->not->toContain('"agency_id"');
});
