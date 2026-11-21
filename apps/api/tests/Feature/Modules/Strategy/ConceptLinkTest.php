<?php

declare(strict_types=1);

use App\Modules\Strategy\Models\WaveConcept;
use App\Modules\Strategy\Models\WaveConceptLink;
use App\Modules\Strategy\Services\ConceptLinkRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\ConceptFixtures as C;
use Tests\Support\StrategyFixtures as S;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * PL-2.3 — reference links (plan section 1.6, rulings C4, Q7, Q8): https only, at most 10, each add/edit/remove counts as
 * an edit of the concept, and the SERVER NEVER OPENS A PASTED URL (Http::fake + assertNothingSent).
 */

function linkUrl(array $w, string $concept, string $suffix = ''): string
{
    return S::u($w, "concepts/{$concept}/links".($suffix !== '' ? "/{$suffix}" : ''));
}

it('adds, edits and removes a link; the domain is derived from the stored URL (lower case, no www)', function (): void {
    $w = S::world();
    $id = C::make($w, C::persona($w));

    $link = S::call($w['staff'], 'POST', linkUrl($w, $id), ['url' => 'https://WWW.Example.com/path?q=1', 'title' => 'Moodboard'])->assertCreated()->json('data');
    expect($link['domain'])->toBe('example.com')->and($link['title'])->toBe('Moodboard')->and($link['url'])->toBe('https://WWW.Example.com/path?q=1');

    $edited = S::call($w['staff'], 'PATCH', linkUrl($w, $id, $link['id']), ['url' => 'https://other.org/x'])->assertOk()->json('data');
    expect($edited['domain'])->toBe('other.org')->and($edited['title'])->toBe('Moodboard');

    $shown = S::call($w['admin'], 'GET', S::u($w, 'concepts?market=SK'))->json('data.0.attributes.links');
    expect($shown)->toHaveCount(1)->and($shown[0]['url'])->toBe('https://other.org/x');

    S::call($w['staff'], 'DELETE', linkUrl($w, $id, $link['id']))->assertOk();
    expect(WaveConceptLink::query()->count())->toBe(0);
});

it('Q7: only https links are accepted; everything else is 422 link.invalid_url', function (string $url): void {
    $w = S::world();
    $id = C::make($w, C::persona($w));

    S::call($w['staff'], 'POST', linkUrl($w, $id), ['url' => $url, 'title' => 'x'])->assertUnprocessable();
    expect(WaveConceptLink::query()->count())->toBe(0);
})->with([
    'http' => 'http://example.com',
    'javascript' => 'javascript:alert(1)',
    'data' => 'data:text/html;base64,PHNjcmlwdD4=',
    'ftp' => 'ftp://example.com/file',
    'file' => 'file:///etc/passwd',
    'protocol relative' => '//example.com/x',
    'no scheme' => 'example.com/x',
    'user info' => 'https://user:pw@example.com/',
    'localhost' => 'https://localhost/x',
    'sub localhost' => 'https://app.localhost/x',
    'ipv4' => 'https://127.0.0.1/x',
    'ipv4 private' => 'https://10.0.0.5/x',
    'ipv6' => 'https://[::1]/x',
    'dotless host' => 'https://intranet/x',
    'space' => 'https://example.com/a b',
    'newline' => "https://example.com/a\nb",
    'bidi space' => "https://example.com/a\u{00A0}b",
]);

it('a link of 2048 characters is accepted and 2049 is not', function (): void {
    $w = S::world();
    $id = C::make($w, C::persona($w));
    $base = 'https://example.com/';

    S::call($w['staff'], 'POST', linkUrl($w, $id), ['url' => $base.str_repeat('a', 2048 - strlen($base)), 'title' => 'x'])->assertCreated();
    S::call($w['staff'], 'POST', linkUrl($w, $id), ['url' => $base.str_repeat('a', 2049 - strlen($base)), 'title' => 'x'])->assertUnprocessable();
});

it('the title is required and at most 120', function (): void {
    $w = S::world();
    $id = C::make($w, C::persona($w));
    S::call($w['staff'], 'POST', linkUrl($w, $id), ['url' => 'https://example.com'])->assertUnprocessable();
    S::call($w['staff'], 'POST', linkUrl($w, $id), ['url' => 'https://example.com', 'title' => '  '])->assertUnprocessable();
    S::call($w['staff'], 'POST', linkUrl($w, $id), ['url' => 'https://example.com', 'title' => str_repeat('a', 121)])->assertUnprocessable();
    S::call($w['staff'], 'POST', linkUrl($w, $id), ['url' => 'https://example.com', 'title' => str_repeat('a', 120)])->assertCreated();
});

it('Q8: at most 10 links per concept', function (): void {
    $w = S::world();
    $id = C::make($w, C::persona($w));
    for ($i = 1; $i <= 10; $i++) {
        S::call($w['staff'], 'POST', linkUrl($w, $id), ['url' => "https://example.com/{$i}", 'title' => "L{$i}"])->assertCreated();
    }
    S::call($w['staff'], 'POST', linkUrl($w, $id), ['url' => 'https://example.com/11', 'title' => 'L11'])->assertUnprocessable()->assertJsonPath('errors.0.code', 'concept_link.limit_count');
    expect(WaveConceptLink::query()->count())->toBe(10);
});

it('the server never fetches a pasted URL: no HTTP request on add, edit, list, duplicate or delete', function (): void {
    Http::fake();
    $w = S::world();
    $id = C::make($w, C::persona($w));

    $link = S::call($w['staff'], 'POST', linkUrl($w, $id), ['url' => 'https://example.com/a', 'title' => 'A'])->assertCreated()->json('data.id');
    S::call($w['staff'], 'PATCH', linkUrl($w, $id, $link), ['url' => 'https://example.org/b'])->assertOk();
    S::call($w['staff'], 'GET', S::u($w, 'concepts?market=SK'))->assertOk();
    $copy = S::call($w['staff'], 'POST', S::u($w, "concepts/{$id}/duplicate"))->assertCreated()->json('data.id');
    S::call($w['staff'], 'DELETE', linkUrl($w, $id, $link))->assertOk();
    S::call($w['admin'], 'DELETE', S::u($w, "concepts/{$copy}"))->assertNoContent();

    Http::assertNothingSent();
    $source = file_get_contents(base_path('app/Modules/Strategy/Services/ConceptLinkService.php')).file_get_contents(base_path('app/Modules/Strategy/Services/ConceptLinkRules.php'));
    expect($source)->not->toMatch('/Http::|curl_|file_get_contents\(|fopen\(|Guzzle|get_headers/');
});

it('Q6: adding, editing and removing a link each send an Approved concept back to Awaiting; a no-op edit does not', function (): void {
    $w = S::world();
    $id = C::make($w, C::persona($w));
    $link = S::call($w['staff'], 'POST', linkUrl($w, $id), ['url' => 'https://example.com/a', 'title' => 'A'])->assertCreated()->json('data.id');
    $status = fn (): string => (string) DB::table('wave_concepts')->value('status');

    C::decide($w, $id, 'approved')->assertOk();
    S::call($w['staff'], 'PATCH', linkUrl($w, $id, $link), ['url' => 'https://example.com/a', 'title' => 'A'])->assertOk()->assertJsonPath('meta.reset_to_awaiting', false);
    expect($status())->toBe('approved');

    S::call($w['staff'], 'PATCH', linkUrl($w, $id, $link), ['title' => 'B'])->assertOk()->assertJsonPath('meta.reset_to_awaiting', true);
    expect($status())->toBe('awaiting');

    C::decide($w, $id, 'approved')->assertOk();
    S::call($w['staff'], 'POST', linkUrl($w, $id), ['url' => 'https://example.com/c', 'title' => 'C'])->assertCreated()->assertJsonPath('meta.reset_to_awaiting', true);
    expect($status())->toBe('awaiting');

    C::decide($w, $id, 'approved')->assertOk();
    S::call($w['staff'], 'DELETE', linkUrl($w, $id, $link))->assertOk()->assertJsonPath('meta.reset_to_awaiting', true);
    expect($status())->toBe('awaiting');

    C::decide($w, $id, 'changes_requested')->assertOk();
    S::call($w['staff'], 'POST', linkUrl($w, $id), ['url' => 'https://example.com/d', 'title' => 'D'])->assertCreated()->assertJsonPath('meta.reset_to_awaiting', false);
    expect($status())->toBe('changes_requested');
});

it('a link of another concept, wave or agency is a 404; audit rows carry ids and a flag only', function (): void {
    $w = S::world();
    $p = C::persona($w);
    $a = C::make($w, $p);
    $b = C::make($w, $p);
    $link = S::call($w['staff'], 'POST', linkUrl($w, $a), ['url' => 'https://secret-host-qz.example.com', 'title' => 'SECRET-LINK-TITLE'])->assertCreated()->json('data.id');

    S::call($w['staff'], 'PATCH', linkUrl($w, $b, $link), ['title' => 'x'])->assertNotFound();
    S::call($w['staff'], 'DELETE', linkUrl($w, $b, $link))->assertNotFound();
    S::call($w['otherAdmin'], 'DELETE', linkUrl($w, $a, $link))->assertNotFound();
    expect(WaveConceptLink::query()->count())->toBe(1);

    $rows = C::auditMeta('wave_concept_link.added');
    expect($rows)->toHaveCount(1)->and(array_keys($rows[0]))->toBe(['wave', 'concept', 'link', 'reset_to_awaiting'])
        ->and(json_encode($rows[0]))->not->toContain('secret');
});

it('ConceptLinkRules: isSafeHref allows https only; domain of an odd URL is never a failure', function (): void {
    expect(ConceptLinkRules::isSafeHref('https://example.com'))->toBeTrue()
        ->and(ConceptLinkRules::isSafeHref('javascript:alert(1)'))->toBeFalse()
        ->and(ConceptLinkRules::isSafeHref('http://example.com'))->toBeFalse()
        ->and(ConceptLinkRules::domain('not a url'))->toBe('')
        ->and(ConceptLinkRules::domain('https://xn--bcher-kva.example/'))->toBe('xn--bcher-kva.example')
        ->and(WaveConcept::query()->count())->toBe(0);
});
