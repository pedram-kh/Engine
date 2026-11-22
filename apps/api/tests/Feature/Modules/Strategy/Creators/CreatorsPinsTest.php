<?php

declare(strict_types=1);

use App\Modules\Boards\Services\BoardService;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Campaigns\Services\CampaignAssignmentStateMachine;
use App\Modules\Strategy\Features\CreatorsScriptsEnabled;
use App\Modules\Strategy\Models\WaveCreatorDecision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use Tests\Support\CreatorFixtures as F;
use Tests\Support\StrategyFixtures as S;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * PL-3.1 B32 (the creators payload is pinned: a new key fails here until it is decided), the board and application markers,
 * and "with the sprint flag OFF everything PL-2 serves is as PL-2 left it" (key sets and queries).
 */

/** @return list<string> */
function pl31KeysOf(mixed $v): array
{
    $k = array_keys((array) $v);
    sort($k);

    return $k;
}

function pl31BoardUrl(array $w): string
{
    return "/api/v1/agencies/{$w['agency']->ulid}/campaigns/{$w['campaign']->ulid}/board";
}

function pl31ApplicationsUrl(array $w): string
{
    return "/api/v1/agencies/{$w['agency']->ulid}/campaigns/{$w['campaign']->ulid}/applications";
}

it('B32: the creators payload key sets are pinned', function (): void {
    $w = F::world();
    $c = F::invitee($w['campaign']);
    $slot = F::slot($w, $c);
    WaveCreatorDecision::factory()->create(['wave_creator_id' => $slot->id]);

    $data = S::call($w['admin'], 'GET', F::url($w))->assertOk()->json('data');
    $market = $data['markets'][0];
    $row = array_merge(...array_column($data['markets'], 'slots'))[0];

    expect(pl31KeysOf($data))->toBe(['campaign', 'limits', 'markets', 'state'])
        ->and(pl31KeysOf($data['limits']))->toBe(['per_market', 'per_wave'])
        ->and(pl31KeysOf($market))->toBe(['code', 'count', 'market', 'name', 'notes_count', 'slots', 'state', 'summary'])
        ->and(pl31KeysOf($row))->toBe(['board', 'creator', 'decision', 'decision_count', 'detail', 'id', 'persona', 'slot', 'slot_code', 'status'])
        ->and(pl31KeysOf($row['creator']))->toBe(['age', 'avatar_url', 'categories', 'country_code', 'display_name', 'example_content_url', 'id', 'is_erased', 'primary_language', 'region', 'secondary_languages', 'verified'])
        ->and(pl31KeysOf($row['board']))->toBe(['column', 'stage']);

    $candidates = S::call($w['admin'], 'GET', F::url($w, 'candidates'))->assertOk();
    expect(pl31KeysOf($candidates->json('data.0')))->toContain('already_in_wave', 'in_market', 'exclusive_elsewhere')
        ->and(pl31KeysOf($candidates->json('meta')))->toContain('market_countries', 'total');

    $history = S::call($w['admin'], 'GET', F::url($w, "{$slot->ulid}/decisions"))->assertOk()->json('data.0');
    expect(array_intersect(pl31KeysOf($history), ['creator_id', 'wave_creator_id']))->toBe([]);
});

it('M1: the board marks cards in_wave on a wave board with all three flags on, and nowhere else', function (): void {
    $w = F::world();
    $in = F::invitee($w['campaign']);
    F::invitee($w['campaign']);
    F::slot($w, $in);

    $on = $this->actingAs($w['admin'])->getJson(pl31BoardUrl($w))->assertOk();
    $body = $on->getContent();
    expect(substr_count((string) $body, '"in_wave"'))->toBe(2)
        ->and(substr_count((string) $body, '"in_wave":true'))->toBe(1);

    Feature::deactivate(CreatorsScriptsEnabled::NAME);
    expect(str_contains((string) $this->actingAs($w['admin'])->getJson(pl31BoardUrl($w))->assertOk()->getContent(), 'in_wave'))->toBeFalse();

    // A normal campaign's board, flag on: no marker.
    Feature::activate(CreatorsScriptsEnabled::NAME);
    $n = $w;
    $n['campaign'] = Campaign::factory()->createOne(['agency_id' => $w['agency']->id, 'brand_id' => $w['brand']->id]);
    app(BoardService::class)->ensureBoard($n['campaign']);
    F::invitee($n['campaign']);
    expect(str_contains((string) $this->actingAs($n['admin'])->getJson(pl31BoardUrl($n))->assertOk()->getContent(), 'in_wave'))->toBeFalse();
});

it('M2: the applications list marks in_wave only for a wave campaign with the flag on', function (): void {
    $w = F::world();
    $a = F::applicant($w['campaign']);
    F::applicant($w['campaign']);
    F::slot($w, $a);

    $body = (string) $this->actingAs($w['admin'])->getJson(pl31ApplicationsUrl($w))->assertOk()->getContent();
    expect(substr_count($body, '"in_wave"'))->toBe(2)->and(substr_count($body, '"in_wave":true'))->toBe(1);

    Feature::deactivate(CreatorsScriptsEnabled::NAME);
    expect(str_contains((string) $this->actingAs($w['admin'])->getJson(pl31ApplicationsUrl($w))->assertOk()->getContent(), 'in_wave'))->toBeFalse();
});

it('F1: with the sprint flag OFF the PL-2 reads and the board carry no creators key and never touch the creators tables', function (): void {
    $w = F::world(armed: false);
    $c = F::invitee($w['campaign']);
    F::slot($w, $c);
    F::applicant($w['campaign']);

    $sql = [];
    DB::listen(static function ($q) use (&$sql): void {
        $sql[] = strtolower($q->sql);
    });

    $landing = S::call($w['admin'], 'GET', S::u($w, ''))->assertOk();
    $notes = S::call($w['admin'], 'GET', S::u($w, 'notes?step=usps'))->assertOk();
    $board = $this->actingAs($w['admin'])->getJson(pl31BoardUrl($w))->assertOk();
    $apps = $this->actingAs($w['admin'])->getJson(pl31ApplicationsUrl($w))->assertOk();

    foreach ([$landing, $notes, $board, $apps] as $res) {
        expect(str_contains((string) $res->getContent(), 'in_wave'))->toBeFalse()
            ->and(str_contains((string) $res->getContent(), '"creators"'))->toBeFalse();
    }
    expect(array_filter($sql, static fn (string $q) => str_contains($q, 'wave_creator')))->toBe([]);
});

it('F2: with the flag OFF a creator accept runs no creators query', function (): void {
    $w = F::world(armed: false);
    $c = F::invitee($w['campaign']);
    F::slot($w, $c);
    $a = CampaignAssignment::query()->withoutGlobalScopes()->where('creator_id', $c->id)->firstOrFail();

    $sql = [];
    DB::listen(static function ($q) use (&$sql): void {
        $sql[] = strtolower($q->sql);
    });
    app(CampaignAssignmentStateMachine::class)->accept($a);

    expect(array_filter($sql, static fn (string $q) => str_contains($q, 'wave_creator')))->toBe([]);
});
