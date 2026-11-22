<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Agencies\Models\BrandCreatorBlacklist;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Models\CampaignApplication;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Creators\Enums\ApplicationStatus;
use App\Modules\Creators\Models\Creator;
use App\Modules\Strategy\Enums\CreatorDecisionStatus;
use App\Modules\Strategy\Features\CreatorsScriptsEnabled;
use App\Modules\Strategy\Models\WaveCreator;
use App\Modules\Strategy\Models\WaveCreatorDecision;
use App\Modules\Strategy\Models\WavePersona;
use App\Modules\Waves\Database\Factories\WaveFactory;
use App\Modules\Waves\Models\Wave;
use App\Modules\Waves\Services\WaveCampaignLink;
use App\Modules\Waves\Services\WaveRuleViolation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use Tests\Support\CreatorFixtures;
use Tests\Support\CreatorFixtures as F;
use Tests\Support\StrategyFixtures as S;
use Tests\Support\WaveFixtures;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * PL-3.1 — the creators API: flag gates and roles, add / edit / replace / remove, the eligible set, decisions, the board rules.
 */

function slotCount(array $w): int
{
    return WaveCreator::query()->where('wave_id', $w['wave']->id)->count();
}

it('B2: every creators route answers 404 not_found with the sprint flag off, and with either prerequisite off; nothing is written', function (): void {
    $w = F::world(armed: false);
    $c = F::applicant($w['campaign']);
    $slot = '01HZZZZZZZZZZZZZZZZZZZZZZZ';

    $routes = [
        ['GET', ''], ['GET', 'candidates'], ['POST', ''], ['PATCH', $slot], ['POST', "{$slot}/replace"], ['DELETE', $slot],
        ['GET', "{$slot}/decisions"], ['POST', "{$slot}/decisions"],
    ];
    foreach ($routes as [$method, $suffix]) {
        S::call($w['admin'], $method, F::url($w, $suffix), ['market' => $w['mk']['SK'], 'creators' => [$c->ulid]])
            ->assertNotFound()->assertJsonPath('errors.0.code', 'not_found');
    }

    Feature::activate(CreatorsScriptsEnabled::NAME);
    Feature::deactivate('strategy_enabled');
    S::call($w['admin'], 'GET', F::url($w))->assertNotFound();
    Feature::activate('strategy_enabled');
    Feature::deactivate('waves_enabled');
    S::call($w['admin'], 'GET', F::url($w))->assertNotFound();

    expect(slotCount($w))->toBe(0);
});

it('B3: roles: all three agency roles manage and see; only admin and manager decide; another agency and a creator get 404', function (): void {
    $w = F::world();
    $c = F::applicant($w['campaign']);
    $slot = F::slot($w, $c);

    foreach (['admin', 'manager', 'staff'] as $role) {
        S::call($w[$role], 'GET', F::url($w))->assertOk();
    }
    S::call($w['staff'], 'POST', F::url($w, "{$slot->ulid}/decisions"), ['status' => 'approved', 'client_name' => 'Anna'])->assertForbidden();
    S::call($w['manager'], 'POST', F::url($w, "{$slot->ulid}/decisions"), ['status' => 'approved', 'client_name' => 'Anna'])->assertCreated();
    S::call($w['admin'], 'POST', F::url($w, "{$slot->ulid}/decisions"), ['status' => 'approved', 'client_name' => 'Anna'])->assertCreated();
    S::call($w['otherAdmin'], 'GET', F::url($w))->assertNotFound();
});

it('B4: add creates slots with codes SK-C1, SK-C2, one audit row each, and changes nothing on the campaign', function (): void {
    $w = F::world();
    $a = F::applicant($w['campaign']);
    $b = F::invitee($w['campaign']);
    $persona = WavePersona::factory()->create(['wave_id' => $w['wave']->id, 'agency_id' => $w['agency']->id, 'market_id' => $w['mkid']['SK']]);
    $before = [CampaignApplication::query()->get()->toArray(), CampaignAssignment::query()->get()->toArray()];

    $res = S::call($w['staff'], 'POST', F::url($w), ['market' => $w['mk']['SK'], 'creators' => [$a->ulid, $b->ulid], 'persona' => $persona->ulid])->assertCreated();

    $slots = $res->json('data.markets.0.slots');
    expect(array_column($slots, 'slot_code'))->toBe(['SK-C1', 'SK-C2'])
        ->and($slots[0]['persona']['code'])->toBe($persona->code)
        ->and($res->json('data.markets.0.count'))->toBe(2)
        ->and(AuditLog::query()->where('action', 'wave_creator.added')->count())->toBe(2)
        ->and([CampaignApplication::query()->get()->toArray(), CampaignAssignment::query()->get()->toArray()])->toBe($before);
});

it('B5: the eligible set: only applicants and invitees; anyone else gets ONE refusal body, and nothing is written', function (): void {
    $w = F::world();
    $stranger = CreatorFixtures::applicant(WaveFixtures::waveCampaign($w['agency'], $w['brand']));   // applied to ANOTHER campaign
    $unrelated = Creator::factory()->approved()->create();
    $ok = F::applicant($w['campaign']);

    $bodies = [];
    foreach ([$stranger->ulid, $unrelated->ulid, '01HZZZZZZZZZZZZZZZZZZZZZZZ'] as $ulid) {
        $res = S::call($w['admin'], 'POST', F::url($w), ['market' => $w['mk']['SK'], 'creators' => [$ok->ulid, $ulid]])->assertStatus(422);
        $bodies[] = Arr::only($res->json('errors.0'), ['code', 'title', 'status', 'meta']);
    }

    expect(array_unique(array_column($bodies, 'code')))->toBe(['wave_creator.not_eligible'])
        ->and(count(array_unique(array_map('json_encode', $bodies))))->toBe(1)
        ->and(slotCount($w))->toBe(0);
});

it('B6: excluded from the picker and from add: not approved, erased, scheduled for deletion, hard-blacklisted (agency-wide and brand-scoped); a soft blacklist and a non-discoverable applicant stay', function (): void {
    $w = F::world();
    $camp = $w['campaign'];
    $pending = F::applicant($camp, creator: ['application_status' => ApplicationStatus::Pending]);
    $erased = F::applicant($camp, creator: ['erased_at' => now()]);
    $leaving = F::applicant($camp);
    DB::table('users')->where('id', $leaving->user_id)->update(['deletion_requested_at' => now()]);
    $hardAgency = F::applicant($camp);
    AgencyCreatorRelation::factory()->blacklisted()->create(['agency_id' => $w['agency']->id, 'creator_id' => $hardAgency->id]);
    $hardBrand = F::applicant($camp);
    BrandCreatorBlacklist::factory()->create(['brand_id' => $w['brand']->id, 'creator_id' => $hardBrand->id]);
    $softBrand = F::applicant($camp);
    BrandCreatorBlacklist::factory()->soft()->create(['brand_id' => $w['brand']->id, 'creator_id' => $softBrand->id]);
    $hidden = F::applicant($camp, creator: ['is_discoverable' => false]);
    $good = F::applicant($camp);

    $listed = array_column(S::call($w['admin'], 'GET', F::url($w, 'candidates'))->assertOk()->json('data'), 'id');
    expect($listed)->toContain($good->ulid, $hidden->ulid, $softBrand->ulid);
    expect(array_intersect($listed, [$pending->ulid, $erased->ulid, $leaving->ulid, $hardAgency->ulid, $hardBrand->ulid]))->toBe([]);

    foreach ([$pending, $erased, $leaving, $hardAgency, $hardBrand] as $creator) {
        S::call($w['admin'], 'POST', F::url($w), ['market' => $w['mk']['SK'], 'creators' => [$creator->ulid]])->assertStatus(422)->assertJsonPath('errors.0.code', 'wave_creator.not_eligible');
    }
    expect(slotCount($w))->toBe(0);
});

it('B7: the picker: filters, country[] chips, already_in_wave with the market, exclusivity hint field present, campaign required', function (): void {
    $w = F::world();
    $sk = F::applicant($w['campaign'], creator: ['country_code' => 'SK', 'display_name' => 'Lucia Horvathova']);
    $ro = F::applicant($w['campaign'], creator: ['country_code' => 'RO', 'display_name' => 'Maria Popescu']);
    F::slot($w, $ro, 'RO');

    $all = S::call($w['admin'], 'GET', F::url($w, 'candidates')."?market={$w['mk']['SK']}")->assertOk();
    expect($all->json('meta.market_countries'))->toBe(['SK'])->and($all->json('meta.total'))->toBe(2);
    $row = (array) Arr::first($all->json('data'), static fn (array $r): bool => $r['id'] === $ro->ulid);
    expect($row['already_in_wave'])->toBeTrue()->and($row['in_market'])->toBe($w['mk']['RO'])->and(array_key_exists('exclusive_elsewhere', $row))->toBeTrue();

    $only = S::call($w['admin'], 'GET', F::url($w, 'candidates').'?country[]=SK')->assertOk()->json('data');
    expect(array_column($only, 'id'))->toBe([$sk->ulid]);
    $q = S::call($w['admin'], 'GET', F::url($w, 'candidates').'?q=Maria')->assertOk()->json('data');
    expect(array_column($q, 'id'))->toBe([$ro->ulid]);

    $none = F::world(linked: false);
    S::call($none['admin'], 'GET', F::url($none, 'candidates'))->assertStatus(422)->assertJsonPath('errors.0.code', 'wave_creator.campaign_required');
    S::call($none['admin'], 'POST', F::url($none), ['market' => $none['mk']['SK'], 'creators' => [$sk->ulid]])->assertStatus(422)->assertJsonPath('errors.0.code', 'wave_creator.campaign_required');
});

it('B8: one creator per wave; the refusal names the market; a persona of another market is refused', function (): void {
    $w = F::world();
    $c = F::applicant($w['campaign']);
    S::call($w['admin'], 'POST', F::url($w), ['market' => $w['mk']['SK'], 'creators' => [$c->ulid]])->assertCreated();

    S::call($w['admin'], 'POST', F::url($w), ['market' => $w['mk']['RO'], 'creators' => [$c->ulid]])
        ->assertStatus(422)->assertJsonPath('errors.0.code', 'wave_creator.already_in_wave')->assertJsonPath('errors.0.meta.market', $w['mk']['SK']);

    $d = F::applicant($w['campaign']);
    $roPersona = WavePersona::factory()->create(['wave_id' => $w['wave']->id, 'agency_id' => $w['agency']->id, 'market_id' => $w['mkid']['RO']]);
    S::call($w['admin'], 'POST', F::url($w), ['market' => $w['mk']['SK'], 'creators' => [$d->ulid], 'persona' => $roPersona->ulid])
        ->assertStatus(422)->assertJsonPath('errors.0.code', 'wave_creator.persona_market_mismatch');
    expect(slotCount($w))->toBe(1);
});

it('B9: caps: 30 per market, 100 per wave; a refused add writes nothing (all or nothing)', function (): void {
    $w = F::world();
    $c = F::applicant($w['campaign']);
    F::slots($w, 29, 'SK');
    $d = F::applicant($w['campaign']);

    S::call($w['admin'], 'POST', F::url($w), ['market' => $w['mk']['SK'], 'creators' => [$c->ulid, $d->ulid]])
        ->assertStatus(422)->assertJsonPath('errors.0.code', 'wave_creator.cap_reached')->assertJsonPath('errors.0.meta.scope', 'market');
    expect(slotCount($w))->toBe(29);
    S::call($w['admin'], 'POST', F::url($w), ['market' => $w['mk']['SK'], 'creators' => [$c->ulid]])->assertCreated();
});

it('B9b: a wave holds at most 100 creators', function (): void {
    $w = F::world();
    WaveFactory::attachMarkets($w['wave'], [['name' => 'Hungary', 'code' => 'HU', 'countries' => ['HU']]]);
    $w['mkid'] = S::mkid($w['wave']);
    $w['mk'] = S::mk($w['wave']);
    F::slots($w, 30, 'SK');
    F::slots($w, 30, 'RO');
    F::slots($w, 30, 'CZ');
    F::slots($w, 10, 'HU');
    $e = F::applicant($w['campaign']);

    expect(slotCount($w))->toBe(100);
    S::call($w['admin'], 'POST', F::url($w), ['market' => $w['mk']['HU'], 'creators' => [$e->ulid]])
        ->assertStatus(422)->assertJsonPath('errors.0.code', 'wave_creator.cap_reached')->assertJsonPath('errors.0.meta.scope', 'wave');
    expect(slotCount($w))->toBe(100);
});

it('B10: edit the slot code (upper-cased, unique in the market) and the persona; audit lists field names only', function (): void {
    $w = F::world();
    $a = F::applicant($w['campaign']);
    $b = F::applicant($w['campaign']);
    $sa = F::slot($w, $a);
    F::slot($w, $b);
    $persona = WavePersona::factory()->create(['wave_id' => $w['wave']->id, 'agency_id' => $w['agency']->id, 'market_id' => $w['mkid']['SK']]);

    S::call($w['staff'], 'PATCH', F::url($w, $sa->ulid), ['slot_code' => ' lucia ', 'persona' => $persona->ulid])->assertOk();
    expect(F::fresh($sa)->slot_code)->toBe('LUCIA')->and(F::fresh($sa)->persona_id)->toBe($persona->id)
        ->and(F::meta('wave_creator.updated', 'fields'))->toBe(['slot_code', 'persona']);

    S::call($w['staff'], 'PATCH', F::url($w, $sa->ulid), ['slot_code' => 'SK-C2'])->assertStatus(422)->assertJsonPath('errors.0.code', 'wave_creator.slot_code_taken');
    S::call($w['staff'], 'PATCH', F::url($w, $sa->ulid), ['slot_code' => 'X'])->assertStatus(422);
    S::call($w['staff'], 'PATCH', F::url($w, $sa->ulid), ['persona' => null])->assertOk();
    expect(F::fresh($sa)->persona_id)->toBeNull();
});

it('B11: replace keeps the slot, code and persona; the old creator\'s decisions go; same creator and an ineligible one are refused', function (): void {
    $w = F::world();
    $old = F::applicant($w['campaign']);
    $new = F::applicant($w['campaign']);
    $persona = WavePersona::factory()->create(['wave_id' => $w['wave']->id, 'agency_id' => $w['agency']->id, 'market_id' => $w['mkid']['SK']]);
    $slot = F::slot($w, $old, extra: ['persona_id' => $persona->id, 'slot_code' => 'LUCIA']);
    WaveCreatorDecision::factory()->count(2)->create(['wave_creator_id' => $slot->id]);

    S::call($w['admin'], 'POST', F::url($w, "{$slot->ulid}/replace"), ['creator' => $old->ulid])->assertStatus(422)->assertJsonPath('errors.0.code', 'wave_creator.replace_same_creator');
    S::call($w['admin'], 'POST', F::url($w, "{$slot->ulid}/replace"), ['creator' => '01HZZZZZZZZZZZZZZZZZZZZZZZ'])->assertStatus(422)->assertJsonPath('errors.0.code', 'wave_creator.not_eligible');
    S::call($w['staff'], 'POST', F::url($w, "{$slot->ulid}/replace"), ['creator' => $new->ulid])->assertOk();

    $fresh = F::fresh($slot);
    expect($fresh->creator_id)->toBe($new->id)->and($fresh->slot_code)->toBe('LUCIA')->and($fresh->persona_id)->toBe($persona->id)
        ->and(WaveCreatorDecision::query()->count())->toBe(0)
        ->and(F::meta('wave_creator.replaced', 'decisions_removed'))->toBe(2);
});

it('B12: remove deletes the slot and its decisions, frees the number, audits the count, and changes nothing on the campaign', function (): void {
    $w = F::world();
    $c = F::invitee($w['campaign']);
    $slot = F::slot($w, $c);
    WaveCreatorDecision::factory()->count(3)->create(['wave_creator_id' => $slot->id]);
    $before = [CampaignApplication::query()->get()->toArray(), CampaignAssignment::query()->get()->makeHidden('updated_at')->toArray()];

    S::call($w['staff'], 'DELETE', F::url($w, $slot->ulid))->assertOk();

    expect(slotCount($w))->toBe(0)->and(WaveCreatorDecision::query()->count())->toBe(0)
        ->and(F::meta('wave_creator.removed', 'decisions_removed'))->toBe(3)
        ->and([CampaignApplication::query()->get()->toArray(), CampaignAssignment::query()->get()->makeHidden('updated_at')->toArray()])->toBe($before);

    $d = F::applicant($w['campaign']);
    $res = S::call($w['admin'], 'POST', F::url($w), ['market' => $w['mk']['SK'], 'creators' => [$d->ulid]])->assertCreated();
    expect($res->json('data.markets.0.slots.0.slot_code'))->toBe('SK-C1');
});

it('B13: decisions: names required, Suggest another needs a comment, Approved takes a note, append-only newest first, lengths', function (): void {
    $w = F::world();
    $slot = F::slot($w, F::applicant($w['campaign']));
    $url = F::url($w, "{$slot->ulid}/decisions");

    S::call($w['admin'], 'POST', $url, ['status' => 'approved'])->assertStatus(422);
    S::call($w['admin'], 'POST', $url, ['status' => 'suggest_another', 'client_name' => 'Anna'])->assertStatus(422)->assertJsonPath('errors.0.code', 'wave_creator.decision_invalid');
    S::call($w['admin'], 'POST', $url, ['status' => 'approved', 'client_name' => 'Anna', 'comment' => 'no'])->assertStatus(422);
    S::call($w['admin'], 'POST', $url, ['status' => 'approved', 'client_name' => str_repeat('x', 81)])->assertStatus(422);
    S::call($w['admin'], 'POST', $url, ['status' => 'suggest_another', 'client_name' => 'Anna', 'comment' => str_repeat('x', 1001)])->assertStatus(422);

    S::call($w['admin'], 'POST', $url, ['status' => 'approved', 'client_name' => '  Anna K. ', 'note' => 'lovely'])->assertCreated();
    S::call($w['admin'], 'POST', $url, ['status' => 'suggest_another', 'client_name' => 'Anna K.', 'comment' => 'older audience', 'note' => 'ignored'])->assertCreated();

    $history = S::call($w['staff'], 'GET', $url)->assertOk()->json('data');
    expect(array_column($history, 'status'))->toBe(['suggest_another', 'approved'])
        ->and($history[0]['note'])->toBeNull()->and($history[1]['client_name'])->toBe('Anna K.');
    $list = S::call($w['staff'], 'GET', F::url($w))->json('data.markets.0.slots.0');
    expect($list['decision']['status'])->toBe('suggest_another')->and($list['decision_count'])->toBe(2);
});

it('B14: no audit row holds a client name, note or comment, or a creator name', function (): void {
    $w = F::world();
    $c = F::applicant($w['campaign'], creator: ['display_name' => 'Zora Unique-Name']);
    S::call($w['admin'], 'POST', F::url($w), ['market' => $w['mk']['SK'], 'creators' => [$c->ulid]])->assertCreated();
    $slot = WaveCreator::query()->firstOrFail();
    S::call($w['admin'], 'POST', F::url($w, "{$slot->ulid}/decisions"), ['status' => 'suggest_another', 'client_name' => 'Quirina Client', 'comment' => 'secret comment words'])->assertCreated();
    S::call($w['admin'], 'POST', F::url($w, "{$slot->ulid}/decisions"), ['status' => 'approved', 'client_name' => 'Quirina Client', 'note' => 'secret note words'])->assertCreated();
    S::call($w['admin'], 'DELETE', F::url($w, $slot->ulid))->assertOk();

    $blob = AuditLog::query()->where('action', 'like', 'wave_creator.%')->get()->map(fn ($a) => json_encode([$a->metadata, $a->before, $a->after]))->implode(' ');
    $blob = (string) $blob;
    expect(AuditLog::query()->where('action', 'like', 'wave_creator.%')->count())->toBeGreaterThanOrEqual(4);
    foreach (['Quirina', 'secret', 'Zora'] as $needle) {
        expect(str_contains($blob, $needle))->toBeFalse();
    }
});

it('B15: the list: derived status per slot, summary counts, persona warning, step state, campaign link', function (): void {
    $w = F::world();
    $camp = $w['campaign'];
    $p1 = WavePersona::factory()->create(['wave_id' => $w['wave']->id, 'agency_id' => $w['agency']->id, 'market_id' => $w['mkid']['SK']]);
    $p2 = WavePersona::factory()->create(['wave_id' => $w['wave']->id, 'agency_id' => $w['agency']->id, 'market_id' => $w['mkid']['SK']]);
    $shortlisted = F::applicant($camp);
    $confirmed = F::invitee($camp, AssignmentStatus::Accepted);
    $contacted = F::invitee($camp, AssignmentStatus::Invited);
    $declined = F::invitee($camp, AssignmentStatus::Declined);
    F::slot($w, $shortlisted, extra: ['persona_id' => $p1->id]);
    $sc = F::slot($w, $confirmed, extra: ['persona_id' => $p1->id]);
    F::slot($w, $contacted);
    F::slot($w, $declined, extra: ['persona_id' => $p2->id]);
    WaveCreatorDecision::factory()->create(['wave_creator_id' => $sc->id]);

    $data = S::call($w['admin'], 'GET', F::url($w))->assertOk()->json('data');
    $sk = $data['markets'][0];
    expect(array_column($sk['slots'], 'status'))->toBe(['shortlisted', 'confirmed', 'contacted', 'declined'])
        ->and($sk['summary']['total'])->toBe(4)
        ->and($sk['summary']['by_status'])->toBe(['shortlisted' => 1, 'contacted' => 1, 'confirmed' => 1, 'declined' => 1])
        ->and($sk['summary']['warnings']['personas_without_creator'])->toBe([$p2->code])
        ->and(array_column($sk['summary']['personas'], 'count'))->toBe([2, 1])
        ->and($sk['state'])->toBe('in_progress')->and($data['campaign']['linked'])->toBeTrue()
        ->and($data['markets'][1]['state'])->toBe('not_started')
        ->and($data['state']['state'])->toBe('in_progress');
});

it('B16: the list costs a CONSTANT number of queries however many slots there are', function (): void {
    $w = F::world();
    $count = function () use ($w): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        S::call($w['admin'], 'GET', F::url($w))->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };
    foreach (range(1, 2) as $_) {
        F::slot($w, F::invitee($w['campaign']));
    }
    $few = $count();
    foreach (range(1, 12) as $_) {
        F::slot($w, F::invitee($w['campaign'], AssignmentStatus::Accepted));
    }

    expect($count())->toBe($few);
});

it('B17: step state: done needs one Confirmed AND Approved and every creator Declined or (Confirmed and Approved)', function (): void {
    $w = F::world();
    $agreed = F::slot($w, F::invitee($w['campaign'], AssignmentStatus::Accepted));
    $state = fn () => S::call($w['admin'], 'GET', F::url($w))->json('data.markets.0.state');

    expect($state())->toBe('in_progress');
    WaveCreatorDecision::factory()->create(['wave_creator_id' => $agreed->id]);
    expect($state())->toBe('done');
    $waiting = F::slot($w, F::invitee($w['campaign'], AssignmentStatus::Invited));
    expect($state())->toBe('in_progress');
    CampaignAssignment::query()->where('creator_id', $waiting->creator_id)->update(['status' => AssignmentStatus::Declined]);
    expect($state())->toBe('done');
    WaveCreatorDecision::factory()->create(['wave_creator_id' => $agreed->id, 'status' => CreatorDecisionStatus::SuggestAnother, 'comment' => 'x']);
    expect($state())->toBe('in_progress');
});

it('B18: notes accept the creators step (per market) only with the sprint flag on', function (): void {
    $w = F::world();
    S::call($w['admin'], 'POST', S::u($w, 'notes'), ['step' => 'creators', 'market' => $w['mk']['SK'], 'body' => 'call her'])->assertCreated();
    expect(S::call($w['admin'], 'GET', F::url($w))->json('data.markets.0.notes_count'))->toBe(1);

    Feature::deactivate(CreatorsScriptsEnabled::NAME);
    S::call($w['admin'], 'POST', S::u($w, 'notes'), ['step' => 'creators', 'market' => $w['mk']['SK'], 'body' => 'x'])->assertNotFound();
});

it('B19: market and persona guards: a market with creators is not removed; a persona matched to creators is not deleted', function (): void {
    $w = F::world();
    $slot = F::slot($w, F::applicant($w['campaign']), 'CZ');
    S::keepMarkets($w, ['SK', 'RO'])->assertStatus(422)->assertJsonPath('errors.0.code', 'wave.market_has_creators');

    $persona = WavePersona::factory()->create(['wave_id' => $w['wave']->id, 'agency_id' => $w['agency']->id, 'market_id' => $w['mkid']['CZ']]);
    $slot->update(['persona_id' => $persona->id]);
    S::call($w['admin'], 'DELETE', S::u($w, "personas/{$persona->ulid}"))->assertStatus(422)->assertJsonPath('errors.0.code', 'persona.in_use_by_creators');

    S::call($w['admin'], 'DELETE', F::url($w, $slot->ulid))->assertOk();
    S::call($w['admin'], 'DELETE', S::u($w, "personas/{$persona->ulid}"))->assertNoContent();
    S::keepMarkets($w, ['SK', 'RO'])->assertOk();
});

it('B20: a wave that has creators is not deleted (model guard); one without is', function (): void {
    $w = F::world();
    $slot = F::slot($w, F::applicant($w['campaign']));

    expect(fn () => $w['wave']->delete())->toThrow(WaveRuleViolation::class);
    expect($w['wave']->fresh()->trashed())->toBeFalse();

    $slot->decisions()->delete();
    $slot->delete();
    $w['wave']->delete();
    expect(Wave::withTrashed()->where('id', $w['wave']->id)->firstOrFail()->trashed())->toBeTrue();
});

it('B21: the campaign link is locked while the wave has creators and unlocks when the last one is removed', function (): void {
    $w = F::world();
    $link = app(WaveCampaignLink::class);
    expect($link->isLocked($w['campaign']->id))->toBeFalse();

    $slot = F::slot($w, F::applicant($w['campaign']));
    expect($link->isLocked($w['campaign']->id))->toBeTrue();
    S::call($w['admin'], 'DELETE', F::url($w, $slot->ulid))->assertOk();
    expect($link->isLocked($w['campaign']->id))->toBeFalse();
});
