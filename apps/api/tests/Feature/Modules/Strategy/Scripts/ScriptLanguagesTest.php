<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Models\WaveMarketScriptLanguage;
use App\Modules\Strategy\Models\WaveScript;
use App\Modules\Strategy\Models\WaveScriptHookText;
use App\Modules\Strategy\Models\WaveScriptLineText;
use App\Modules\Waves\Models\Wave;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ScriptFixtures as SF;
use Tests\Support\StrategyFixtures as S;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * PL-3.2b L1 and L2 (rulings 4 and 10): English is always there; a market adds up to three local languages; removing one
 * deletes nothing (the texts stay, hidden), and adding it back shows them again.
 */

function pl32bLangUrl(array $w, string $market = 'SK'): string
{
    return S::u($w, "markets/{$w['mk'][$market]}/script-languages");
}

it('L1: a market starts with English only; it takes up to three local languages in order; English cannot be listed; a fourth is refused', function (): void {
    $w = SF::world();

    S::call($w['staff'], 'GET', pl32bLangUrl($w))->assertOk()->assertJsonPath('data.languages', [])->assertJsonPath('data.max_local', 3);

    SF::languages($w, 'SK', ['sk', 'hu', 'cs'])->assertOk()->assertJsonPath('data.languages.0.code', 'sk')->assertJsonPath('data.languages.2.code', 'cs');
    expect(WaveMarketScriptLanguage::query()->count())->toBe(3);

    SF::languages($w, 'SK', ['sk', 'hu', 'cs', 'pl'])->assertStatus(422)->assertJsonPath('errors.0.code', 'wave_script.language_limit');
    SF::languages($w, 'SK', ['en'])->assertStatus(422);
    SF::languages($w, 'SK', ['SK'])->assertStatus(422);
    SF::languages($w, 'SK', ['sk', 'sk'])->assertStatus(422);
    // refused changes wrote nothing
    expect(WaveMarketScriptLanguage::query()->orderBy('position')->pluck('language')->all())->toBe(['sk', 'hu', 'cs']);

    // the languages are per market
    S::call($w['staff'], 'GET', pl32bLangUrl($w, 'RO'))->assertOk()->assertJsonPath('data.languages', []);
});

it('L1: the script shows English plus the market languages, and the same list on every script of the market', function (): void {
    $w = SF::world();
    SF::languages($w, 'SK', ['sk', 'hu'])->assertOk();
    $a = SF::api($w);
    $b = SF::api($w);

    foreach ([$a, $b] as $id) {
        S::call($w['staff'], 'GET', SF::url($w, $id))->assertOk()->assertJsonPath('data.languages', ['en', 'sk', 'hu']);
    }
});

it('L2: removing a language deletes nothing: the texts stay hidden and come back when it is added again; a hidden language cannot be written', function (): void {
    $w = SF::world();
    SF::languages($w, 'SK', ['sk', 'hu'])->assertOk();
    $id = SF::api($w);
    $script = WaveScript::query()->where('ulid', $id)->firstOrFail();
    $line = SF::line($script, 0, 'English line');
    SF::lineText($line, 'Slovenská veta', 'sk');
    $hook = SF::hooksOf($script)[0];
    SF::text($hook, 'audio', 'Ahoj', 'sk');

    SF::languages($w, 'SK', ['hu'])->assertOk();

    $shown = S::call($w['staff'], 'GET', SF::url($w, $id))->assertOk();
    $shown->assertJsonPath('data.languages', ['en', 'hu'])->assertJsonPath('data.lines.0.texts', ['en' => 'English line']);
    expect($shown->json('data.hooks.0.texts.audio'))->toBe([])
        ->and(WaveScriptLineText::query()->where('language', 'sk')->count())->toBe(1)
        ->and(WaveScriptHookText::query()->where('language', 'sk')->count())->toBe(1);

    $line = $shown->json('data.lines.0');
    S::call($w['admin'], 'PUT', SF::url($w, "{$id}/lines/{$line['id']}"), ['texts' => ['sk' => 'Nová'], 'revision' => $line['revision']])
        ->assertStatus(422)->assertJsonPath('errors.0.code', 'wave_script.language_not_in_market');

    SF::languages($w, 'SK', ['sk', 'hu'])->assertOk();
    S::call($w['staff'], 'GET', SF::url($w, $id))->assertOk()
        ->assertJsonPath('data.lines.0.texts', ['en' => 'English line', 'sk' => 'Slovenská veta'])->assertJsonPath('data.hooks.0.texts.audio.sk', 'Ahoj');
});

it('L2: the language list reports how many scripts and texts hold text in each language', function (): void {
    $w = SF::world();
    SF::languages($w, 'SK', ['sk'])->assertOk();
    $a = WaveScript::query()->where('ulid', SF::api($w))->firstOrFail();
    $b = WaveScript::query()->where('ulid', SF::api($w))->firstOrFail();
    SF::lineText(SF::line($a, 0), 'x', 'sk');
    SF::lineText(SF::line($a, 1), 'y', 'sk');
    SF::lineText(SF::line($b, 0), 'z', 'sk');

    S::call($w['staff'], 'GET', pl32bLangUrl($w))->assertOk()->assertJsonPath('data.languages.0.scripts', 2)->assertJsonPath('data.languages.0.texts', 3);
});

it('T1: the language routes are tenancy- and role-safe: another agency, another wave\'s market and a creator user are refused; staff can write', function (): void {
    $w = SF::world();
    S::call($w['otherAdmin'], 'GET', pl32bLangUrl($w))->assertNotFound();
    S::call($w['otherAdmin'], 'PUT', pl32bLangUrl($w), ['languages' => ['sk']])->assertNotFound();
    expect(WaveMarketScriptLanguage::query()->count())->toBe(0);

    // a market ulid that belongs to another wave of this agency
    $other = Wave::factory()->forBrand($w['agency']->id, $w['brand']->id)->withMarkets([['name' => 'Slovakia', 'code' => 'SK', 'countries' => ['SK']]])->create();
    $foreign = S::mk($other)['SK'];
    S::call($w['admin'], 'GET', S::u($w, "markets/{$foreign}/script-languages"))->assertNotFound();
    S::call($w['admin'], 'PUT', S::u($w, "markets/{$foreign}/script-languages"), ['languages' => ['sk']])->assertNotFound();

    S::call(User::factory()->create(), 'PUT', pl32bLangUrl($w), ['languages' => ['sk']])->assertStatus(404);
    S::call($w['staff'], 'PUT', pl32bLangUrl($w), ['languages' => ['sk']])->assertOk();
    S::call($w['manager'], 'PUT', pl32bLangUrl($w), ['languages' => ['sk', 'hu']])->assertOk();
});

it('L2: taking a market out of the wave takes its language rows with it (the table has a foreign key, so nothing is left behind)', function (): void {
    $w = SF::world();
    SF::languages($w, 'CZ', ['cs'])->assertOk();
    expect(WaveMarketScriptLanguage::query()->count())->toBe(1);

    S::keepMarkets($w, ['SK', 'RO'])->assertOk();

    expect(WaveMarketScriptLanguage::query()->count())->toBe(0);
});
