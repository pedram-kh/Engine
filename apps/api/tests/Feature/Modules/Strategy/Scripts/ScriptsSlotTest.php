<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Creators\Models\Creator;
use App\Modules\Strategy\Models\WaveCreator;
use App\Modules\Strategy\Models\WaveScript;
use App\Modules\Strategy\Models\WaveScriptHook;
use App\Modules\Strategy\Models\WaveScriptHookText;
use App\Modules\Strategy\Models\WaveStrategyNote;
use App\Modules\Waves\Models\WaveMarket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatorFixtures as F;
use Tests\Support\ScriptFixtures as SF;
use Tests\Support\StrategyFixtures as S;
use Tests\Support\WaveFixtures;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * PL-3.2a — what happens to a slot's scripts on Remove, Replace and Add (ruling Q8), the archived list (Q14) and the market guard.
 */

/** A slot with `$n` scripts, the first with a recognisable hook text. */
function pl32Slot(array $w, int $n, string $market = 'SK'): array
{
    [$slot, $creator] = SF::slot($w, $market);
    $scripts = [];
    for ($i = 0; $i < $n; $i++) {
        $scripts[] = SF::script($slot);
    }
    if ($n > 0) {
        SF::text(SF::hooksOf($scripts[0])[0], 'audio', 'Keep me');
    }

    return [$slot, $creator, $scripts];
}

it('G9a: Remove with scripts and no choice is refused with the count and writes nothing; with archive the scripts are kept and hidden', function (): void {
    $w = SF::world();
    [$slot, $creator, $scripts] = pl32Slot($w, 2);

    S::call($w['admin'], 'DELETE', F::url($w, $slot->ulid))->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'wave_creator.scripts_choice_required')->assertJsonPath('errors.0.meta.scripts', 2);
    expect(WaveCreator::query()->count())->toBe(1)->and(WaveScript::query()->whereNull('archived_at')->count())->toBe(2);

    S::call($w['admin'], 'DELETE', F::url($w, $slot->ulid), ['scripts' => 'archive'])->assertOk();
    expect(WaveCreator::query()->count())->toBe(0);
    $kept = WaveScript::query()->orderBy('number')->get();
    expect($kept)->toHaveCount(2)->and($kept->every(fn ($s) => $s->wave_creator_id === null && $s->archived_at !== null))->toBeTrue()
        ->and($kept->pluck('number')->all())->toBe([1, 2])
        ->and(WaveScriptHookText::query()->where('body', 'Keep me')->exists())->toBeTrue()
        ->and(WaveScriptHook::query()->count())->toBe(6);

    // an archived script is not reachable for editing or loading
    $id = $scripts[0]->ulid;
    S::call($w['admin'], 'GET', SF::url($w, $id))->assertNotFound();
    S::call($w['admin'], 'PUT', SF::url($w, "{$id}/brief"), ['duration_seconds' => 45, 'revision' => 1])->assertNotFound();
    S::call($w['admin'], 'POST', SF::url($w, "{$id}/hooks"))->assertNotFound();
    S::call($w['admin'], 'POST', SF::url($w, "{$id}/archive"))->assertNotFound();

    // the archived list (Q14) names them
    $list = S::call($w['admin'], 'GET', SF::url($w, 'archived?market='.$w['mk']['SK']))->assertOk()->json('data');
    expect(array_column($list, 'number'))->toBe([1, 2])->and($list[0]['creator']['id'])->toBe($creator->ulid)->and($list[0]['creator_in_market'])->toBeFalse();
    $rail = S::call($w['admin'], 'GET', SF::url($w).'?market='.$w['mk']['SK'])->json('data');
    expect($rail['markets'][0]['archived_scripts'])->toBe(2)->and($rail['markets'][0]['scripts'])->toBe(0);
});

it('G9b: Remove with the delete choice: admin and manager only; staff is refused and nothing is written; scripts, hooks and texts are deleted', function (): void {
    $w = SF::world();
    [$slot] = pl32Slot($w, 2);

    S::call($w['staff'], 'DELETE', F::url($w, $slot->ulid), ['scripts' => 'delete'])->assertForbidden();
    expect(WaveCreator::query()->count())->toBe(1)->and(WaveScript::query()->count())->toBe(2);

    S::call($w['manager'], 'DELETE', F::url($w, $slot->ulid), ['scripts' => 'delete'])->assertOk();
    expect(WaveCreator::query()->count())->toBe(0)->and(WaveScript::query()->count())->toBe(0)
        ->and(WaveScriptHook::query()->count())->toBe(0)->and(WaveScriptHookText::query()->count())->toBe(0);
    $removed = F::lastAudit('wave_creator.removed');
    expect($removed->metadata)->toMatchArray(['scripts_archived' => 0, 'scripts_deleted' => 2])
        ->and(AuditLog::query()->where('action', 'wave_script.deleted')->count())->toBe(2);

    // staff may archive
    [$slot2] = pl32Slot($w, 1, 'RO');
    S::call($w['staff'], 'DELETE', F::url($w, $slot2->ulid), ['scripts' => 'archive'])->assertOk();
    S::call($w['admin'], 'DELETE', F::url($w, 'x'), ['scripts' => 'shred'])->assertStatus(422);
});

it('G9c: Remove without scripts needs no choice (PL-3.1 behaviour unchanged)', function (): void {
    $w = SF::world();
    [$slot] = SF::slot($w);

    S::call($w['admin'], 'DELETE', F::url($w, $slot->ulid))->assertOk();
    expect(WaveCreator::query()->count())->toBe(0);
});

it('G9d: Add restores the archived scripts of the same creator in the same market, not in another market', function (): void {
    $w = SF::world();
    [$slot, $creator] = pl32Slot($w, 2);
    S::call($w['admin'], 'DELETE', F::url($w, $slot->ulid), ['scripts' => 'archive'])->assertOk();

    // another market: nothing comes back
    S::call($w['admin'], 'POST', F::url($w), ['market' => $w['mk']['RO'], 'creators' => [$creator->ulid]])->assertCreated();
    expect(WaveScript::query()->whereNull('archived_at')->count())->toBe(0);
    $ro = WaveCreator::query()->where('creator_id', $creator->id)->firstOrFail();
    S::call($w['admin'], 'DELETE', F::url($w, $ro->ulid))->assertOk();

    // the same market: both come back, on the new slot, with their hooks and text
    S::call($w['admin'], 'POST', F::url($w), ['market' => $w['mk']['SK'], 'creators' => [$creator->ulid]])->assertCreated();
    $back = WaveCreator::query()->where('creator_id', $creator->id)->firstOrFail();
    $live = WaveScript::query()->orderBy('number')->get();
    expect($live->pluck('wave_creator_id')->unique()->all())->toBe([$back->id])->and($live->every(fn ($s) => $s->archived_at === null))->toBeTrue()
        ->and(WaveScriptHookText::query()->where('body', 'Keep me')->exists())->toBeTrue();
    expect(F::meta('wave_creator.added', 'scripts_restored'))->toBe(2)
        ->and(AuditLog::query()->where('action', 'wave_script.restored')->count())->toBe(2);
    S::call($w['admin'], 'GET', SF::url($w, (string) data_get($live->values()->all(), '0.ulid')))->assertOk();
});

it('G9e: Replace carries the scripts to the new creator by default: same ids, hooks and text, the creator follows, the number holds', function (): void {
    $w = SF::world();
    [$slot, $old, $scripts] = pl32Slot($w, 2);
    $new = F::applicant($w['campaign']);

    S::call($w['admin'], 'POST', F::url($w, "{$slot->ulid}/replace"), ['creator' => $new->ulid])->assertOk();
    $moved = WaveScript::query()->orderBy('number')->get();
    expect($moved->pluck('ulid')->all())->toBe([$scripts[0]->ulid, $scripts[1]->ulid])
        ->and($moved->pluck('creator_id')->unique()->all())->toBe([$new->id])
        ->and($moved->pluck('wave_creator_id')->unique()->all())->toBe([$slot->id])
        ->and($moved->every(fn ($s) => $s->archived_at === null))->toBeTrue()
        ->and(WaveScriptHookText::query()->where('body', 'Keep me')->exists())->toBeTrue();
    expect(F::meta('wave_creator.replaced', 'scripts_carried'))->toBe(2)->and(F::meta('wave_creator.replaced', 'scripts_archived'))->toBe(0)
        ->and($old->id)->not->toBe($new->id);
    S::call($w['admin'], 'GET', SF::url($w, $scripts[0]->ulid))->assertOk()->assertJsonPath('data.creator.id', $new->ulid);
});

it('G9f: Replace with the archive choice archives the old creator\'s scripts and the new slot starts empty', function (): void {
    $w = SF::world();
    [$slot, $old] = pl32Slot($w, 2);
    $new = F::applicant($w['campaign']);

    S::call($w['admin'], 'POST', F::url($w, "{$slot->ulid}/replace"), ['creator' => $new->ulid, 'scripts' => 'archive'])->assertOk();
    $all = WaveScript::query()->get();
    expect($all->every(fn ($s) => $s->archived_at !== null && $s->wave_creator_id === null && $s->creator_id === $old->id))->toBeTrue()
        ->and(F::meta('wave_creator.replaced', 'scripts_archived'))->toBe(2)->and(F::meta('wave_creator.replaced', 'scripts_carried'))->toBe(0);
    S::call($w['admin'], 'POST', SF::url($w), ['slot' => $slot->ulid])->assertCreated()->assertJsonPath('data.number', 1);
    S::call($w['admin'], 'POST', F::url($w, "{$slot->ulid}/replace"), ['creator' => $new->ulid, 'scripts' => 'bogus'])->assertStatus(422);
});

it('G9g: Replace with carry-over keeps the numbers; the new creator\'s archived scripts hold no number and return only as far as three allow', function (): void {
    $w = SF::world();
    [$slot, , $scripts] = pl32Slot($w, 2);          // live numbers 1, 2
    $new = F::applicant($w['campaign']);
    // the new creator has two archived scripts in the SAME market (numbers 1 and 2) and one in another market
    $gone = F::slot($w, $new, 'SK');
    $mine = [SF::script($gone), SF::script($gone)];
    S::call($w['admin'], 'DELETE', F::url($w, $gone->ulid), ['scripts' => 'archive'])->assertOk();
    $other = F::slot($w, $new, 'RO');
    SF::script($other);
    S::call($w['admin'], 'DELETE', F::url($w, $other->ulid), ['scripts' => 'archive'])->assertOk();

    S::call($w['admin'], 'POST', F::url($w, "{$slot->ulid}/replace"), ['creator' => $new->ulid])->assertOk();
    // the carried scripts keep 1 and 2 and their ids; one archived script returned as number 3; the rest stay archived
    expect(WaveScript::query()->whereIn('ulid', [$scripts[0]->ulid, $scripts[1]->ulid])->orderBy('number')->pluck('number', 'ulid')->all())
        ->toBe([$scripts[0]->ulid => 1, $scripts[1]->ulid => 2]);
    $live = WaveScript::query()->whereNull('archived_at')->orderBy('number')->get();
    expect($live->pluck('number')->all())->toBe([1, 2, 3])->and($live->every(fn ($s) => $s->creator_id === $new->id))->toBeTrue()
        ->and($live->pluck('ulid')->contains($mine[0]->ulid))->toBeTrue()
        ->and(WaveScript::query()->whereNotNull('archived_at')->count())->toBe(2);
});

it('G9h: the picker says, per creator, how many archived scripts they have in the asked market (and none from another market)', function (): void {
    $w = SF::world();
    $new = F::applicant($w['campaign']);
    $none = F::applicant($w['campaign']);
    $sk = F::slot($w, $new, 'SK');
    SF::script($sk);
    SF::script($sk);
    S::call($w['admin'], 'DELETE', F::url($w, $sk->ulid), ['scripts' => 'archive'])->assertOk();
    $ro = F::slot($w, $new, 'RO');
    SF::script($ro);
    S::call($w['admin'], 'DELETE', F::url($w, $ro->ulid), ['scripts' => 'archive'])->assertOk();

    $rows = fn (string $market): array => collect(S::call($w['admin'], 'GET', F::url($w, 'candidates')."?market={$market}")->assertOk()->json('data'))->pluck('archived_scripts', 'id')->all();
    expect($rows($w['mk']['SK']))->toMatchArray([$new->ulid => 2, $none->ulid => 0])
        ->and($rows($w['mk']['RO']))->toMatchArray([$new->ulid => 1, $none->ulid => 0]);
    // no market asked: nothing to say
    $any = collect(S::call($w['admin'], 'GET', F::url($w, 'candidates'))->assertOk()->json('data'))->pluck('archived_scripts', 'id')->all();
    expect($any[$new->ulid])->toBe(0);
});

it('G9i: Add with more archived scripts than three allow brings back the lowest numbers first; the rest stay archived', function (): void {
    $w = SF::world();
    $new = F::applicant($w['campaign']);
    // four archived scripts of one creator in one market (numbers 1, 2, 3 from a first stay, the fourth from a second stay)
    $first = F::slot($w, $new, 'SK');
    $a = [SF::script($first), SF::script($first), SF::script($first)];
    S::call($w['admin'], 'DELETE', F::url($w, $first->ulid), ['scripts' => 'archive'])->assertOk();
    $second = F::slot($w, $new, 'SK');
    $b = SF::script($second);
    S::call($w['admin'], 'DELETE', F::url($w, $second->ulid), ['scripts' => 'archive'])->assertOk();
    expect(WaveScript::query()->whereNotNull('archived_at')->count())->toBe(4);

    S::call($w['admin'], 'POST', F::url($w, ''), ['market' => $w['mk']['SK'], 'creators' => [$new->ulid]])->assertSuccessful();
    $live = WaveScript::query()->whereNull('archived_at')->orderBy('number')->get();
    $left = WaveScript::query()->whereNotNull('archived_at')->get();
    expect($live->pluck('number')->all())->toBe([1, 2, 3])->and($left)->toHaveCount(1)
        // the one left archived is the last in number order (number 3, newest): the lowest numbers are the ones that come back
        ->and($left->first()->ulid)->toBe($b->ulid)->and($left->first()->number)->toBe(3);
});

it('G17: a market with archived scripts is removed only with confirm_scripts_deleted equal to the count; its scripts, hooks and texts go; one audit row per script; creators block first', function (): void {
    $w = SF::world();
    [$slot] = pl32Slot($w, 2, 'CZ');
    $url = WaveFixtures::wavesUrl($w['agency'], $w['brand'], $w['wave']->ulid);
    $body = function () use ($w, $url): array {
        $attrs = S::call($w['admin'], 'GET', $url)->assertOk()->json('data.attributes');

        return ['markets' => array_values(array_filter($attrs['markets'], fn ($m) => $m['code'] !== 'CZ')), 'markets_version' => $attrs['markets_version']];
    };

    // a live creator blocks the removal first (PL-3.1 Q16), whatever the confirmation says
    S::call($w['admin'], 'PATCH', $url, $body() + ['confirm_scripts_deleted' => 2])->assertStatus(422)->assertJsonPath('errors.0.code', 'wave.market_has_creators');

    S::call($w['admin'], 'DELETE', F::url($w, $slot->ulid), ['scripts' => 'archive'])->assertOk();
    $res = S::call($w['admin'], 'PATCH', $url, $body())->assertStatus(422);
    expect($res->json('errors.0.code'))->toBe('wave.market_has_scripts')->and($res->json('errors.0.meta.script_count'))->toBe(2)
        ->and($res->json('errors.0.meta.markets.0.script_count'))->toBe(2)->and($res->json('errors.0.meta.markets.0.code'))->toBe('CZ');
    S::call($w['admin'], 'PATCH', $url, $body() + ['confirm_scripts_deleted' => 1])->assertStatus(422)->assertJsonPath('errors.0.code', 'wave.market_has_scripts');
    expect(WaveScript::query()->count())->toBe(2)->and(WaveMarket::query()->where('wave_id', $w['wave']->id)->count())->toBe(3);

    S::call($w['admin'], 'PATCH', $url, $body() + ['confirm_scripts_deleted' => 2])->assertOk();
    expect(WaveScript::query()->count())->toBe(0)->and(WaveScriptHook::query()->count())->toBe(0)->and(WaveScriptHookText::query()->count())->toBe(0)
        ->and(WaveMarket::query()->where('wave_id', $w['wave']->id)->count())->toBe(2)
        ->and(AuditLog::query()->where('action', 'wave_script.deleted')->count())->toBe(2);
});

it('G17b: removing a market with no scripts needs no confirmation (nothing changes for waves without scripts)', function (): void {
    $w = SF::world();
    S::keepMarkets($w, ['SK', 'RO'])->assertOk();
    expect(WaveMarket::query()->where('wave_id', $w['wave']->id)->count())->toBe(2);
});

it('G15b: erasing a creator keeps their scripts and texts; the slot then reads "Deleted creator"', function (): void {
    $w = SF::world();
    [$slot, $creator] = pl32Slot($w, 1);
    $creator->forceFill(['erased_at' => now()])->save();

    $rail = S::call($w['admin'], 'GET', SF::url($w).'?market='.$w['mk']['SK'])->assertOk()->json('data.rail.creators.0');
    expect($rail['creator']['is_erased'])->toBeTrue()->and($rail['creator']['display_name'])->toBeNull()->and($rail['scripts'])->toHaveCount(1);
    expect(WaveScriptHookText::query()->where('body', 'Keep me')->exists())->toBeTrue();
});

it('G16b: the notes of a removed market include its scripts thread', function (): void {
    $w = SF::world();
    S::call($w['admin'], 'POST', S::u($w, 'notes'), ['step' => 'scripts', 'market' => $w['mk']['CZ'], 'body' => 'About CZ scripts'])->assertCreated();
    $url = WaveFixtures::wavesUrl($w['agency'], $w['brand'], $w['wave']->ulid);
    $attrs = S::call($w['admin'], 'GET', $url)->assertOk()->json('data.attributes');
    $body = ['markets' => array_values(array_filter($attrs['markets'], fn ($m) => $m['code'] !== 'CZ')), 'markets_version' => $attrs['markets_version']];

    S::call($w['admin'], 'PATCH', $url, $body)->assertStatus(422)->assertJsonPath('errors.0.code', 'wave.market_has_notes');
    S::call($w['admin'], 'PATCH', $url, $body + ['confirm_notes_deleted' => 1])->assertOk();
    expect(WaveStrategyNote::query()->count())->toBe(0);
});
