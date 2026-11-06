<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

require_once dirname(__DIR__, 3).'/Support/MessageRevisionFixtures.php';

/*
 * AH-119 B3 — withdrawn text is unsearchable: a deleted message disappears from search, an edited one
 * is found by its CURRENT text and never by the original.
 */

it('removes a deleted message from search, on both surfaces, and from the withdrawn text too', function (string $surface): void {
    $w = revWorld();
    $s = revSurface($w, $surface, 'withdrawnword here');
    expect(revSearch($w, 'withdrawnword')->json('data'))->toHaveCount(1);

    $this->actingAs($s['actor'])->deleteJson($s['url'])->assertOk();

    expect(revSearch($w, 'withdrawnword')->assertOk()->json('data'))->toBe([]);
})->with(revSurfaceDataset());

it('matches an edited message by its current text only, never the original', function (string $surface): void {
    $w = revWorld();
    $s = revSurface($w, $surface, 'oldversion text');

    $this->actingAs($s['actor'])->patchJson($s['url'], ['body' => 'newversion text'])->assertOk();

    expect(revSearch($w, 'newversion')->json('data'))->toHaveCount(1)
        ->and(revSearch($w, 'newversion')->json('data.0.attributes.message_ulid'))->toBe($s['message']->ulid)
        ->and(revSearch($w, 'oldversion')->assertOk()->json('data'))->toBe([]);
})->with(revSurfaceDataset());

it('never carries withdrawn text in a search response', function (): void {
    $w = revWorld();
    $s = revSurface($w, 'creator-direct', 'ghostword first');
    $this->actingAs($s['actor'])->patchJson($s['url'], ['body' => 'ghostword second'])->assertOk();
    $this->actingAs($s['actor'])->deleteJson($s['url'])->assertOk();
    revDirectMessage($w['direct'], $w['admin'], false, 'ghostword survivor');

    $content = revSearch($w, 'ghostword')->assertOk()->getContent();

    expect($content)->toContain('survivor')->and($content)->not->toContain('first')->and($content)->not->toContain('second');
});

it('excludes a soft-deleted row in SQL even if it still carries text (never rely on the body being cleared)', function (string $surface): void {
    $w = revWorld();
    $s = revSurface($w, $surface, 'lingerword text');
    // A live message keeps the thread in the inbox, so only the SQL filter can hide the deleted one.
    str_contains($surface, 'campaign')
        ? revCampaignMessage($w['thread'], $w['admin'], false, 'unrelated live text')
        : revDirectMessage($w['direct'], $w['admin'], false, 'unrelated live text');
    expect(revSearch($w, 'lingerword')->json('data'))->toHaveCount(1);

    // Soft-delete WITHOUT the service: the row keeps its body, only deleted_at is set.
    $s['message']->delete();

    expect(revRow($s['table'], $s['message']->ulid)->body)->toBe('lingerword text')
        ->and(revSearch($w, 'lingerword')->assertOk()->json('data'))->toBe([]);
})->with(revSurfaceDataset());
