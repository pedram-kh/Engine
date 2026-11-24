<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Features\CreatorsScriptsEnabled;
use App\Modules\Strategy\Models\WaveCreator;
use App\Modules\Strategy\Models\WaveMatrixCell;
use App\Modules\Strategy\Models\WavePersona;
use App\Modules\Strategy\Models\WavePersonaAngle;
use App\Modules\Strategy\Models\WaveScript;
use App\Modules\Strategy\Models\WaveScriptHook;
use App\Modules\Strategy\Models\WaveScriptHookText;
use App\Modules\Strategy\Models\WaveStrategyNote;
use App\Modules\Strategy\Models\WaveUsp;
use App\Modules\Strategy\Services\ScriptEstimate;
use App\Modules\Waves\Models\Wave;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use Tests\Support\CreatorFixtures as F;
use Tests\Support\ScriptFixtures as SF;
use Tests\Support\StrategyFixtures as S;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * PL-3.2a — the scripts API: flags, tenancy, roles, create and the cap of three, the brief, the hooks, delete, the queries.
 */

/** Create a script through the API for a slot and return its ulid. */
function pl32Create(array $w, string $slotUlid): string
{
    return S::call($w['admin'], 'POST', SF::url($w), ['slot' => $slotUlid])->assertCreated()->json('data.id');
}

it('G1: every scripts route answers 404 not_found with the sprint flag off, and with either prerequisite off; nothing is written', function (): void {
    $w = SF::world(armed: false);
    $u = '01HZZZZZZZZZZZZZZZZZZZZZZZ';
    $routes = [
        ['GET', ''], ['GET', 'archived'], ['DELETE', "archived/{$u}"], ['POST', ''], ['GET', $u], ['PUT', "{$u}/brief"], ['DELETE', $u],
        ['POST', "{$u}/hooks"], ['PUT', "{$u}/hooks/{$u}"], ['POST', "{$u}/hooks/{$u}/duplicate"], ['DELETE', "{$u}/hooks/{$u}"],
    ];
    // The table is checked against the router: a new scripts route fails here until it is added.
    $registered = collect(app('router')->getRoutes()->getRoutes())->filter(static fn ($r): bool => str_contains($r->uri(), '/strategy/scripts'))
        ->flatMap(static fn ($r): array => collect($r->methods())->reject(fn ($m) => $m === 'HEAD')->all())->count();
    expect($registered)->toBe(count($routes));

    $body = ['slot' => $u, 'revision' => 1];
    foreach ($routes as [$method, $suffix]) {
        S::call($w['admin'], $method, SF::url($w, $suffix), $body)->assertNotFound()->assertJsonPath('errors.0.code', 'not_found');
    }

    Feature::activate(CreatorsScriptsEnabled::NAME);
    Feature::deactivate('strategy_enabled');
    S::call($w['admin'], 'GET', SF::url($w))->assertNotFound();
    Feature::activate('strategy_enabled');
    Feature::deactivate('waves_enabled');
    S::call($w['admin'], 'GET', SF::url($w))->assertNotFound();

    expect(WaveScript::query()->count())->toBe(0);
});

it('G2: tenancy: another agency, brand or wave is 404 on every route; another wave\'s script and hook ids are 404; rows carry the wave\'s agency', function (): void {
    $w = SF::world();
    [$slot] = SF::slot($w);
    $ulid = pl32Create($w, $slot->ulid);
    $hook = WaveScriptHook::query()->where('script_id', WaveScript::query()->where('ulid', $ulid)->value('id'))->orderBy('position')->firstOrFail();

    expect(WaveScript::query()->where('ulid', $ulid)->value('agency_id'))->toBe($w['agency']->id)
        ->and(WaveScriptHook::query()->where('ulid', $hook->ulid)->value('agency_id'))->toBe($w['agency']->id);

    // another agency's admin
    S::call($w['otherAdmin'], 'GET', SF::url($w))->assertNotFound();
    S::call($w['otherAdmin'], 'GET', SF::url($w, $ulid))->assertNotFound();
    S::call($w['otherAdmin'], 'PUT', SF::url($w, "{$ulid}/brief"), ['duration_seconds' => 45, 'revision' => 1])->assertNotFound();

    // the script of this wave addressed through ANOTHER wave of the same agency is a 404 (looked up through the wave)
    $other = Wave::factory()->forBrand($w['agency']->id, $w['brand']->id)->create();
    $otherUrl = S::u(['agency' => $w['agency'], 'brand' => $w['brand'], 'wave' => $other], "scripts/{$ulid}");
    S::call($w['admin'], 'GET', $otherUrl)->assertNotFound();
    S::call($w['admin'], 'PUT', "{$otherUrl}/brief", ['duration_seconds' => 45, 'revision' => 1])->assertNotFound();
    S::call($w['admin'], 'PUT', "{$otherUrl}/hooks/{$hook->ulid}", ['shot_type' => 'wide', 'revision' => 1])->assertNotFound();
    S::call($w['admin'], 'DELETE', $otherUrl)->assertNotFound();

    // a hook ulid of another script
    [$slot2] = SF::slot($w, 'RO');
    $ulid2 = pl32Create($w, $slot2->ulid);
    S::call($w['admin'], 'PUT', SF::url($w, "{$ulid2}/hooks/{$hook->ulid}"), ['shot_type' => 'wide', 'revision' => 1])->assertNotFound();
});

it('G2b: a slot of another wave is a 404 and writes nothing', function (): void {
    $w = SF::world();
    $other = Wave::factory()->forBrand($w['agency']->id, $w['brand']->id)->withMarkets([['name' => 'Slovakia', 'code' => 'SK', 'countries' => ['SK']]])->create();
    $foreign = WaveCreator::factory()->create(['wave_id' => $other->id, 'agency_id' => $w['agency']->id]);

    S::call($w['admin'], 'POST', SF::url($w), ['slot' => $foreign->ulid])->assertNotFound();
    S::call($w['otherAdmin'], 'POST', SF::url($w), ['slot' => $foreign->ulid])->assertNotFound();
    expect(WaveScript::query()->count())->toBe(0);
});

it('G3: roles: admin, manager and staff write; a creator user is refused; deleting an archived script is admin and manager only', function (): void {
    $w = SF::world();
    [$slot] = SF::slot($w);

    foreach (['staff', 'manager', 'admin'] as $role) {
        S::call($w[$role], 'GET', SF::url($w))->assertOk();
    }
    $first = S::call($w['staff'], 'POST', SF::url($w), ['slot' => $slot->ulid])->assertCreated()->json('data.id');
    S::call($w['manager'], 'PUT', SF::url($w, "{$first}/brief"), ['duration_seconds' => 45, 'revision' => 1])->assertOk();
    S::call($w['staff'], 'POST', SF::url($w, "{$first}/hooks"))->assertCreated();

    $creatorUser = User::factory()->create();
    S::call($creatorUser, 'GET', SF::url($w))->assertStatus(404);

    // archive it through a Remove, then the archived delete
    S::call($w['admin'], 'DELETE', F::url($w, $slot->ulid), ['scripts' => 'archive'])->assertOk();
    S::call($w['staff'], 'DELETE', SF::url($w, "archived/{$first}"))->assertForbidden();
    expect(WaveScript::query()->where('ulid', $first)->exists())->toBeTrue();
    S::call($w['manager'], 'DELETE', SF::url($w, "archived/{$first}"))->assertOk();
    expect(WaveScript::query()->where('ulid', $first)->exists())->toBeFalse();
});

it('G4: a new script takes the lowest free number and hooks A, B, C; the fourth is refused; deleting frees the number', function (): void {
    $w = SF::world();
    [$slot] = SF::slot($w);

    $a = S::call($w['admin'], 'POST', SF::url($w), ['slot' => $slot->ulid])->assertCreated();
    $a->assertJsonPath('data.number', 1)->assertJsonPath('data.brief.duration_seconds', 30)->assertJsonPath('data.brief.revision', 1);
    expect(array_column($a->json('data.hooks'), 'letter'))->toBe(['A', 'B', 'C']);
    $b = pl32Create($w, $slot->ulid);
    $c = pl32Create($w, $slot->ulid);
    expect(WaveScript::query()->orderBy('number')->pluck('number')->all())->toBe([1, 2, 3]);

    S::call($w['admin'], 'POST', SF::url($w), ['slot' => $slot->ulid])
        ->assertStatus(422)->assertJsonPath('errors.0.code', 'wave_script.limit_reached')->assertJsonPath('errors.0.meta.max', 3);
    expect(WaveScript::query()->count())->toBe(3);

    S::call($w['admin'], 'DELETE', SF::url($w, $b))->assertOk();
    S::call($w['admin'], 'POST', SF::url($w), ['slot' => $slot->ulid])->assertCreated()->assertJsonPath('data.number', 2);
    expect($c)->not->toBe('');
});

it('G4b: the cap of three counts ARCHIVED scripts of the creator too', function (): void {
    $w = SF::world();
    [$slot, $creator] = SF::slot($w);
    pl32Create($w, $slot->ulid);
    pl32Create($w, $slot->ulid);
    S::call($w['admin'], 'DELETE', F::url($w, $slot->ulid), ['scripts' => 'archive'])->assertOk();
    // the creator comes back: two scripts are restored on the new slot, the third number is free, a fourth is not
    S::call($w['admin'], 'POST', F::url($w), ['market' => $w['mk']['SK'], 'creators' => [$creator->ulid]])->assertCreated();
    $slot = WaveCreator::query()->where('creator_id', $creator->id)->firstOrFail();
    pl32Create($w, $slot->ulid);
    S::call($w['admin'], 'POST', SF::url($w), ['slot' => $slot->ulid])->assertStatus(422)->assertJsonPath('errors.0.code', 'wave_script.limit_reached');

    // archived scripts of ANOTHER market hold their numbers: two archived in SK leave one number for the same creator in RO
    S::call($w['admin'], 'DELETE', F::url($w, $slot->ulid), ['scripts' => 'archive'])->assertOk();
    expect(WaveScript::query()->whereNotNull('archived_at')->count())->toBe(3);
    S::call($w['admin'], 'DELETE', SF::url($w, 'archived/'.WaveScript::query()->where('number', 3)->firstOrFail()->ulid))->assertOk();
    S::call($w['admin'], 'POST', F::url($w), ['market' => $w['mk']['RO'], 'creators' => [$creator->ulid]])->assertCreated();
    $ro = WaveCreator::query()->where('creator_id', $creator->id)->firstOrFail();
    $new = S::call($w['admin'], 'POST', SF::url($w), ['slot' => $ro->ulid])->assertCreated();
    $new->assertJsonPath('data.number', 3);
    S::call($w['admin'], 'POST', SF::url($w), ['slot' => $ro->ulid])
        ->assertStatus(422)->assertJsonPath('errors.0.code', 'wave_script.limit_reached')->assertJsonPath('errors.0.meta.archived', 2);
});

it('G11: text limits: exactly at the limit saves, one over is 422; control characters are stripped; a blank text removes the row', function (): void {
    $w = SF::world();
    [$slot] = SF::slot($w);
    $script = S::call($w['admin'], 'POST', SF::url($w), ['slot' => $slot->ulid])->assertCreated();
    $id = $script->json('data.id');
    $hook = $script->json('data.hooks.0');
    $url = SF::url($w, "{$id}/hooks/{$hook['id']}");

    $ok = S::call($w['admin'], 'PUT', $url, ['texts' => ['audio' => ['en' => str_repeat('a', 300)], 'overlay' => ['en' => str_repeat('b', 100)]], 'visual_description' => str_repeat('c', 400), 'revision' => 1])->assertOk();
    $rev = $ok->json('data.hooks.0.revision');
    expect($rev)->toBe(2);

    S::call($w['admin'], 'PUT', $url, ['texts' => ['audio' => ['en' => str_repeat('a', 301)]], 'revision' => $rev])->assertStatus(422);
    S::call($w['admin'], 'PUT', $url, ['texts' => ['overlay' => ['en' => str_repeat('b', 101)]], 'revision' => $rev])->assertStatus(422);
    S::call($w['admin'], 'PUT', $url, ['visual_description' => str_repeat('c', 401), 'revision' => $rev])->assertStatus(422);
    S::call($w['admin'], 'PUT', $url, ['shot_type' => 'dolly_zoom', 'revision' => $rev])->assertStatus(422);

    $clean = S::call($w['admin'], 'PUT', $url, ['texts' => ['audio' => ['en' => "He\x07llo\r\nworld  "], 'overlay' => ['en' => '   ']], 'revision' => $rev])->assertOk();
    $clean->assertJsonPath('data.hooks.0.texts.audio.en', "Hello\nworld");
    expect($clean->json('data.hooks.0.texts.overlay'))->toBe([])
        ->and(WaveScriptHookText::query()->where('hook_id', WaveScriptHook::query()->where('ulid', $hook['id'])->value('id'))->where('field', 'overlay')->exists())->toBeFalse();

    S::call($w['admin'], 'PUT', SF::url($w, "{$id}/brief"), ['angle' => str_repeat('x', 301), 'revision' => 1])->assertStatus(422);
    S::call($w['admin'], 'PUT', SF::url($w, "{$id}/brief"), ['angle' => str_repeat('x', 300), 'revision' => 1])->assertOk();
    S::call($w['admin'], 'PUT', SF::url($w, "{$id}/brief"), ['duration_seconds' => 25, 'revision' => 2])->assertStatus(422);
});

it('G11b: only English text is accepted for now; another language is refused and writes nothing', function (): void {
    $w = SF::world();
    [$slot] = SF::slot($w);
    $script = S::call($w['admin'], 'POST', SF::url($w), ['slot' => $slot->ulid])->assertCreated();
    $hook = $script->json('data.hooks.0');

    S::call($w['admin'], 'PUT', SF::url($w, "{$script->json('data.id')}/hooks/{$hook['id']}"), ['texts' => ['audio' => ['sk' => 'Ahoj']], 'revision' => 1])
        ->assertStatus(422)->assertJsonPath('errors.0.code', 'wave_script.language_not_available');
    expect(WaveScriptHookText::query()->count())->toBe(0);
});

it('G12: loading a script and the rail issue the same number of queries with 3 hooks and with 6, and with 1 creator and with 4', function (): void {
    $w = SF::world();
    [$slot] = SF::slot($w);
    $script = SF::script($slot, [], hooks: 3);
    foreach (SF::hooksOf($script) as $hook) {
        SF::text($hook, 'audio', 'one two three');
    }
    $count = function (string $url) use ($w): int {
        S::call($w['admin'], 'GET', $url)->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        S::call($w['admin'], 'GET', $url)->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    $showUrl = SF::url($w, $script->ulid);
    $railUrl = SF::url($w).'?market='.$w['mk']['SK'];
    $showSmall = $count($showUrl);
    $railSmall = $count($railUrl);

    foreach ([3, 4, 5] as $position) {
        $hook = WaveScriptHook::factory()->create(['script_id' => $script->id, 'position' => $position]);
        SF::text($hook, 'audio', 'more words here');
        SF::text($hook, 'overlay', 'tag');
    }
    for ($i = 0; $i < 3; $i++) {
        [$extra] = SF::slot($w);
        SF::script($extra);
    }

    expect(WaveScriptHook::query()->where('script_id', $script->id)->count())->toBe(6)
        ->and($count($showUrl))->toBe($showSmall)
        ->and($count($railUrl))->toBe($railSmall);
});

it('G13: revision: stale is 409 with the current block and writes nothing; the current saves and bumps; a no-change save bumps nothing; blocks do not conflict', function (): void {
    $w = SF::world();
    [$slot] = SF::slot($w);
    $script = S::call($w['admin'], 'POST', SF::url($w), ['slot' => $slot->ulid])->assertCreated();
    $id = $script->json('data.id');
    $hookA = $script->json('data.hooks.0.id');
    $hookB = $script->json('data.hooks.1.id');

    // hook A saved at revision 1 -> 2; a second writer still holding 1 gets a 409
    S::call($w['admin'], 'PUT', SF::url($w, "{$id}/hooks/{$hookA}"), ['texts' => ['audio' => ['en' => 'first']], 'revision' => 1])->assertOk();
    $stale = S::call($w['manager'], 'PUT', SF::url($w, "{$id}/hooks/{$hookA}"), ['texts' => ['audio' => ['en' => 'second']], 'revision' => 1])
        ->assertStatus(409)->assertJsonPath('errors.0.code', 'wave_script.conflict')
        ->assertJsonPath('errors.0.meta.block', 'hook')->assertJsonPath('errors.0.meta.current.texts.audio.en', 'first')->assertJsonPath('errors.0.meta.current.revision', 2);
    expect($stale->json('errors.0.meta.current.id'))->toBe($hookA)
        ->and(WaveScriptHookText::query()->where('body', 'second')->exists())->toBeFalse();

    // a different hook of the same script does not conflict, and neither does the brief
    S::call($w['manager'], 'PUT', SF::url($w, "{$id}/hooks/{$hookB}"), ['texts' => ['audio' => ['en' => 'b']], 'revision' => 1])->assertOk();
    S::call($w['manager'], 'PUT', SF::url($w, "{$id}/brief"), ['duration_seconds' => 45, 'revision' => 1])->assertOk();

    // a no-change save bumps nothing and does not conflict, even with a stale revision
    $before = WaveScript::query()->where('ulid', $id)->firstOrFail();
    S::call($w['admin'], 'PUT', SF::url($w, "{$id}/hooks/{$hookA}"), ['texts' => ['audio' => ['en' => 'first']], 'revision' => 1])->assertOk();
    S::call($w['admin'], 'PUT', SF::url($w, "{$id}/brief"), ['duration_seconds' => 45, 'revision' => 1])->assertOk();
    $after = WaveScript::query()->where('ulid', $id)->firstOrFail();
    expect($after->revision)->toBe($before->revision)->and($after->brief_revision)->toBe($before->brief_revision);

    // brief: stale with a real change is 409 with the current brief
    S::call($w['admin'], 'PUT', SF::url($w, "{$id}/brief"), ['duration_seconds' => 60, 'revision' => 1])
        ->assertStatus(409)->assertJsonPath('errors.0.meta.block', 'brief')->assertJsonPath('errors.0.meta.current.duration_seconds', 45)->assertJsonPath('errors.0.meta.current.revision', 2);
    expect(WaveScript::query()->where('ulid', $id)->value('duration_seconds'))->toBe(45);
});

it('G13b: hooks: at most six, the last cannot be deleted, duplicate lands after the original with its texts, delete closes the gap', function (): void {
    $w = SF::world();
    [$slot] = SF::slot($w);
    $script = S::call($w['admin'], 'POST', SF::url($w), ['slot' => $slot->ulid])->assertCreated();
    $id = $script->json('data.id');
    $a = $script->json('data.hooks.0.id');

    S::call($w['admin'], 'PUT', SF::url($w, "{$id}/hooks/{$a}"), ['shot_type' => 'close_up', 'visual_description' => 'Face', 'texts' => ['audio' => ['en' => 'Hi there'], 'overlay' => ['en' => 'Hey']], 'revision' => 1])->assertOk();
    $dup = S::call($w['admin'], 'POST', SF::url($w, "{$id}/hooks/{$a}/duplicate"))->assertCreated();
    $hooks = $dup->json('data.hooks');
    expect(array_column($hooks, 'letter'))->toBe(['A', 'B', 'C', 'D'])
        ->and($hooks[1]['shot_type'])->toBe('close_up')->and($hooks[1]['texts']['audio']['en'])->toBe('Hi there')->and($hooks[1]['texts']['overlay']['en'])->toBe('Hey')
        ->and($hooks[1]['id'])->not->toBe($a)->and($hooks[1]['revision'])->toBe(1);

    S::call($w['admin'], 'POST', SF::url($w, "{$id}/hooks"))->assertCreated();
    S::call($w['admin'], 'POST', SF::url($w, "{$id}/hooks"))->assertCreated();
    S::call($w['admin'], 'POST', SF::url($w, "{$id}/hooks"))->assertStatus(422)->assertJsonPath('errors.0.code', 'wave_script.hooks_limit');
    S::call($w['admin'], 'POST', SF::url($w, "{$id}/hooks/{$a}/duplicate"))->assertStatus(422)->assertJsonPath('errors.0.code', 'wave_script.hooks_limit');

    $scriptId = WaveScript::query()->where('ulid', $id)->value('id');
    $ids = WaveScriptHook::query()->where('script_id', $scriptId)->orderBy('position')->pluck('ulid')->all();
    expect($ids)->toHaveCount(6);
    foreach (array_slice($ids, 1) as $hookId) {
        S::call($w['admin'], 'DELETE', SF::url($w, "{$id}/hooks/{$hookId}"))->assertOk();
    }
    expect(WaveScriptHook::query()->where('script_id', $scriptId)->pluck('position')->all())->toBe([0]);
    S::call($w['admin'], 'DELETE', SF::url($w, "{$id}/hooks/{$a}"))->assertStatus(422)->assertJsonPath('errors.0.code', 'wave_script.last_hook');
    expect(WaveScriptHook::query()->where('script_id', $scriptId)->count())->toBe(1);
});

it('G20: the brief is read live: persona, USPs (priority first), the persona angle; an edited angle survives a persona change; reset returns to live; no persona gives the empty brief', function (): void {
    $w = SF::world();
    $persona = WavePersona::query()->create(['agency_id' => $w['agency']->id, 'wave_id' => $w['wave']->id, 'market_id' => $w['mkid']['SK'], 'slot' => 1, 'code' => 'PSK1', 'title' => 'Busy parent', 'name' => 'Anna']);
    $other = WavePersona::query()->create(['agency_id' => $w['agency']->id, 'wave_id' => $w['wave']->id, 'market_id' => $w['mkid']['SK'], 'slot' => 2, 'code' => 'PSK2', 'title' => 'Student', 'name' => 'Ben']);
    $usp1 = WaveUsp::query()->create(['agency_id' => $w['agency']->id, 'wave_id' => $w['wave']->id, 'color_key' => 'violet', 'position' => 0, 'title' => 'Fast']);
    $usp2 = WaveUsp::query()->create(['agency_id' => $w['agency']->id, 'wave_id' => $w['wave']->id, 'color_key' => 'green', 'position' => 1, 'title' => 'Cheap']);
    $cell = fn ($p, $u, $level, $rationale = null) => WaveMatrixCell::query()->create(['agency_id' => $w['agency']->id, 'wave_id' => $w['wave']->id, 'market_id' => $w['mkid']['SK'], 'persona_id' => $p->id, 'usp_id' => $u->id, 'level' => $level, 'rationale' => $rationale, 'source' => 'manual']);
    $cell($persona, $usp1, 'support');
    $cell($persona, $usp2, 'priority', 'Rationale line');
    WavePersonaAngle::query()->create(['agency_id' => $w['agency']->id, 'wave_id' => $w['wave']->id, 'persona_id' => $persona->id, 'usp_id' => $usp2->id, 'explanation' => 'Save time every morning', 'origin' => 'manual']);

    [$slot] = SF::slot($w, 'SK', ['persona_id' => $persona->id]);
    $created = S::call($w['admin'], 'POST', SF::url($w), ['slot' => $slot->ulid])->assertCreated();
    $id = $created->json('data.id');
    $brief = $created->json('data.brief');
    expect($brief['persona']['code'])->toBe('PSK1')
        ->and(array_column($brief['usps'], 'title'))->toBe(['Cheap', 'Fast'])
        ->and($brief['usps'][0]['level'])->toBe('priority')
        ->and($brief['angle'])->toBe(['value' => 'Save time every morning', 'source' => 'persona', 'custom' => false]);
    expect(WaveScript::query()->where('ulid', $id)->value('angle'))->toBeNull();   // nothing copied

    // the persona's angle changes: the live angle follows at the next load
    WavePersonaAngle::query()->where('persona_id', $persona->id)->update(['explanation' => 'Changed line']);
    S::call($w['admin'], 'GET', SF::url($w, $id))->assertJsonPath('data.brief.angle.value', 'Changed line');

    // edit the angle, then change the creator's persona: the edited angle stays, the persona part follows
    S::call($w['admin'], 'PUT', SF::url($w, "{$id}/brief"), ['angle' => 'My own angle', 'revision' => 1])->assertOk()->assertJsonPath('data.brief.angle.custom', true);
    S::call($w['admin'], 'PATCH', F::url($w, $slot->ulid), ['persona' => $other->ulid])->assertOk();
    $moved = S::call($w['admin'], 'GET', SF::url($w, $id))->assertOk();
    expect($moved->json('data.brief.angle'))->toBe(['value' => 'My own angle', 'source' => 'script', 'custom' => true])
        ->and($moved->json('data.brief.persona.code'))->toBe('PSK2')->and($moved->json('data.brief.usps'))->toBe([]);

    // reset to live: null; with the new persona (no cells) the angle is empty, with the first persona back it is the live line
    S::call($w['admin'], 'PATCH', F::url($w, $slot->ulid), ['persona' => $persona->ulid])->assertOk();
    S::call($w['admin'], 'PUT', SF::url($w, "{$id}/brief"), ['angle' => null, 'revision' => 2])->assertOk()
        ->assertJsonPath('data.brief.angle.custom', false)->assertJsonPath('data.brief.angle.value', 'Changed line');

    // no persona: the empty live brief, the script still works
    S::call($w['admin'], 'PATCH', F::url($w, $slot->ulid), ['persona' => null])->assertOk();
    $none = S::call($w['admin'], 'GET', SF::url($w, $id))->assertOk();
    expect($none->json('data.brief.persona'))->toBeNull()->and($none->json('data.brief.usps'))->toBe([])
        ->and($none->json('data.brief.angle'))->toBe(['value' => null, 'source' => 'none', 'custom' => false]);
});

it('G21: the estimate: the longest hook\'s spoken words divided by 2.5, rounded up (Q12)', function (): void {
    expect(ScriptEstimate::of([])['seconds'])->toBe(0)
        ->and(ScriptEstimate::of(['one two three four five', 'a b'])['seconds'])->toBe(2)
        ->and(ScriptEstimate::of(['one two three four five', 'a b'])['words'])->toBe(5)
        ->and(ScriptEstimate::of(['  spaced   out  words '])['words'])->toBe(3)
        ->and(ScriptEstimate::of([str_repeat('w ', 51)])['seconds'])->toBe(21)
        ->and(ScriptEstimate::of(['a b c'], ['d e'])['words'])->toBe(5);

    $w = SF::world();
    [$slot] = SF::slot($w);
    $script = SF::script($slot);
    SF::text(SF::hooksOf($script)[1], 'audio', 'one two three four five six');
    S::call($w['admin'], 'GET', SF::url($w, $script->ulid))->assertJsonPath('data.estimate', ['words' => 6, 'seconds' => 3]);
});

it('G15: audit rows: literal payloads with ids, number, counts and field names; no script text anywhere', function (): void {
    $w = SF::world();
    [$slot] = SF::slot($w);
    $id = pl32Create($w, $slot->ulid);
    $hook = WaveScriptHook::query()->where('script_id', WaveScript::query()->where('ulid', $id)->value('id'))->orderBy('position')->firstOrFail();
    S::call($w['admin'], 'PUT', SF::url($w, "{$id}/hooks/{$hook->ulid}"), ['visual_description' => 'SECRET-DESCRIPTION', 'texts' => ['audio' => ['en' => 'SECRET-AUDIO']], 'revision' => 1])->assertOk();
    S::call($w['admin'], 'PUT', SF::url($w, "{$id}/brief"), ['angle' => 'SECRET-ANGLE', 'duration_seconds' => 45, 'revision' => 1])->assertOk();
    S::call($w['admin'], 'POST', SF::url($w, "{$id}/hooks"))->assertCreated();
    S::call($w['admin'], 'POST', SF::url($w, "{$id}/hooks/{$hook->ulid}/duplicate"))->assertCreated();
    S::call($w['admin'], 'DELETE', SF::url($w, "{$id}/hooks/{$hook->ulid}"))->assertOk();
    S::call($w['admin'], 'DELETE', SF::url($w, $id))->assertOk();

    $rows = AuditLog::query()->where('action', 'like', 'wave_script%')->orderBy('id')->get();
    expect($rows->pluck('action')->map(fn ($a) => $a->value)->all())->toBe([
        'wave_script.created', 'wave_script_hook.updated', 'wave_script.updated', 'wave_script_hook.created', 'wave_script_hook.duplicated', 'wave_script_hook.deleted', 'wave_script.deleted',
    ]);
    expect(json_encode($rows->map(fn ($r) => $r->metadata)->all()))->not->toContain('SECRET');
    $meta = $rows->map(fn ($r) => $r->metadata)->values()->all();
    expect(data_get($meta, '0.wave'))->toBe($w['wave']->ulid)->and(data_get($meta, '0.market'))->toBe($w['mk']['SK'])->and(data_get($meta, '0.script'))->toBe($id)
        ->and(data_get($meta, '0.number'))->toBe(1)->and(data_get($meta, '0.creator_id'))->toBe($slot->creator_id)->and(data_get($meta, '0.hooks'))->toBe(3);
    expect(data_get($meta, '1.fields'))->toBe(['visual_description', 'audio'])->and(data_get($meta, '2.fields'))->toBe(['angle', 'duration_seconds']);
    expect(data_get($meta, '6.hooks'))->toBe(4)->and(WaveScriptHook::query()->count())->toBe(0)->and(WaveScriptHookText::query()->count())->toBe(0);
});

it('G16: notes: step=scripts needs a market, is one thread per market, is 404 with the sprint flag off', function (): void {
    $w = SF::world();
    $url = S::u($w, 'notes');

    S::call($w['admin'], 'POST', $url, ['step' => 'scripts', 'body' => 'No market'])->assertStatus(422);
    S::call($w['admin'], 'POST', $url, ['step' => 'scripts', 'market' => $w['mk']['SK'], 'body' => 'A note'])->assertCreated();
    S::call($w['admin'], 'POST', $url, ['step' => 'scripts', 'market' => $w['mk']['RO'], 'body' => 'Other market'])->assertCreated();
    expect(S::call($w['admin'], 'GET', $url.'?step=scripts&market='.$w['mk']['SK'])->assertOk()->json('data'))->toHaveCount(1);
    expect(WaveStrategyNote::query()->where('step', 'scripts')->count())->toBe(2);

    Feature::deactivate(CreatorsScriptsEnabled::NAME);
    S::call($w['admin'], 'POST', $url, ['step' => 'scripts', 'market' => $w['mk']['SK'], 'body' => 'Off'])->assertNotFound();
});

it('G22: the rail: the creators of a market in slot order with their scripts, the step state per market and for the wave, the archived count', function (): void {
    $w = SF::world();
    [$slot1] = SF::slot($w, 'SK');
    [$slot2] = SF::slot($w, 'SK');
    [$roSlot] = SF::slot($w, 'RO');
    S::call($w['admin'], 'GET', SF::url($w))->assertOk()->assertJsonPath('data.step.state', 'not_started')->assertJsonPath('data.rail', null);

    $s1 = pl32Create($w, $slot1->ulid);
    pl32Create($w, $slot1->ulid);
    $res = S::call($w['admin'], 'GET', SF::url($w).'?market='.$w['mk']['SK'])->assertOk();
    $res->assertJsonPath('data.step.state', 'in_progress')->assertJsonPath('data.step.markets_started', 1)->assertJsonPath('data.rail.state', 'in_progress');
    $creators = $res->json('data.rail.creators');
    expect(array_column($creators, 'id'))->toBe([$slot1->ulid, $slot2->ulid])
        ->and(array_column($creators[0]['scripts'], 'number'))->toBe([1, 2])->and($creators[0]['scripts'][0]['hooks'])->toBe(3)
        ->and($creators[0]['can_add_script'])->toBeTrue()->and($creators[1]['scripts'])->toBe([]);
    expect(array_column($res->json('data.markets'), 'state'))->toBe(['in_progress', 'not_started', 'not_started']);

    pl32Create($w, $slot1->ulid);
    expect(S::call($w['admin'], 'GET', SF::url($w).'?market='.$w['mk']['SK'])->json('data.rail.creators.0.can_add_script'))->toBeFalse();

    S::call($w['admin'], 'GET', SF::url($w).'?market=01HZZZZZZZZZZZZZZZZZZZZZZZ')->assertStatus(422);
    S::call($w['admin'], 'GET', SF::url($w, "archived?market={$w['mk']['SK']}"))->assertOk()->assertJsonPath('data', []);
    expect($s1)->not->toBe('');
});
