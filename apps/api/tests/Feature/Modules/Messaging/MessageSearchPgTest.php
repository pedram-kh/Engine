<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->group('pg');

require_once dirname(__DIR__, 3).'/Support/MessageRevisionFixtures.php';

/*
 * AH-119 B3 — the Postgres leg of search: tsvector prefix matching, ranking, the GIN index and the
 * injection-hostile term handling. Skipped on SQLite (the substring fallback is MessageSearchApiTest).
 */

beforeEach(function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('tsvector search needs PostgreSQL (composer test:pg).');
    }
});

it('matches by word PREFIX, not by substring', function (): void {
    $w = revWorld();
    revDirectMessage($w['direct'], $w['admin'], false, 'Contracting update for tomorrow');
    revDirectMessage($w['direct'], $w['admin'], false, 'subcontract paperwork');

    $found = revSearch($w, 'contra')->assertOk()->json('data.*.attributes.snippet');

    expect($found)->toBe(['Contracting update for tomorrow']);
});

it('ranks the better match first and AND-s the terms', function (): void {
    $w = revWorld();
    $weak = revDirectMessage($w['direct'], $w['admin'], false, 'budget '.str_repeat('filler ', 30).'review');
    $strong = revDirectMessage($w['direct'], $w['admin'], false, 'budget review');
    revDirectMessage($w['direct'], $w['admin'], false, 'budget only');

    $ulids = revSearch($w, 'budget review')->assertOk()->json('data.*.attributes.message_ulid');

    expect($ulids)->toHaveCount(2)->and($ulids[0])->toBe($strong->ulid)->and($ulids)->toContain($weak->ulid);
});

it('is tenancy-bound in SQL on both tables', function (): void {
    $mine = revWorld();
    $theirs = revWorld();
    revCampaignMessage($theirs['thread'], $theirs['admin'], false, 'pgtenant campaign');
    revDirectMessage($theirs['direct'], $theirs['admin'], false, 'pgtenant direct');
    $own = revDirectMessage($mine['direct'], $mine['admin'], false, 'pgtenant mine');

    expect(revSearch($mine, 'pgtenant')->json('data.*.attributes.message_ulid'))->toBe([$own->ulid]);
});

it('survives tsquery metacharacters and SQL-looking input without a 500', function (string $q): void {
    $w = revWorld();
    revDirectMessage($w['direct'], $w['admin'], false, 'harmless text drop table');

    revSearch($w, $q)->assertOk();
})->with(['foo & !bar | (baz)', "'; drop table messages; --", 'a:* & b', '\\\\ \' " <-> ::', 'tab\tnew line']);

it('uses the GIN full-text index expression on both tables', function (): void {
    $defs = DB::table('pg_indexes')->whereIn('tablename', ['messages', 'relationship_messages'])->where('indexname', 'like', '%body_fts')->pluck('indexdef', 'indexname');

    expect($defs->keys()->sort()->values()->all())->toBe(['idx_messages_body_fts', 'idx_relationship_messages_body_fts']);
    foreach ($defs as $def) {
        expect($def)->toContain('USING gin')->toContain("to_tsvector('simple'::regconfig, COALESCE(body, ''::text))");
    }
});

it('can plan the query on the index once the tables are big enough to prefer it', function (): void {
    DB::statement('set enable_seqscan = off');
    $plan = collect(DB::select("explain select id from messages where to_tsvector('simple', coalesce(body, '')) @@ to_tsquery('simple', 'abc:*')"))
        ->map(fn ($r) => (array) $r)->flatten()->implode("\n");
    DB::statement('set enable_seqscan = on');

    expect($plan)->toContain('idx_messages_body_fts');
});
