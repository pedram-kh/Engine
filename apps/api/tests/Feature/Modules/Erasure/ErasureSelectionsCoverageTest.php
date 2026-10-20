<?php

declare(strict_types=1);

use App\Modules\Erasure\Registry\Disposition;
use App\Modules\Erasure\Registry\ErasureRegistry;
use App\Modules\Erasure\Selection\Selections;
use App\Modules\Erasure\Subject\SubjectSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ErasureGraph;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * AH-104a Stage 3 — the registry and the selections must agree.
 *
 * Plan §4.1 asks for "a spec listing every model the engine touches" so a new
 * tenant-scoped model cannot be silently skipped or silently over-reached. The
 * engine reads and writes through the raw query builder (no model, no scope, no
 * Audited trait), so the equivalent is a table-level contract: a table the
 * registry says to act on has a selection, and a selection exists only where
 * the registry calls for one.
 */

/** @return array<string, list<Disposition>> table => the acting dispositions the registry gives it */
function actingDispositions(): array
{
    $acting = [Disposition::Anon, Disposition::EraseRow, Disposition::ScrubJson, Disposition::DelMatch];
    $out = [];
    foreach (ErasureRegistry::tables() as $table => $rule) {
        $found = [];
        if ($rule->disposition !== null && in_array($rule->disposition, $acting, true)) {
            $found[$rule->disposition->value] = $rule->disposition;
        }
        foreach ($rule->columns as $column) {
            if (in_array($column->disposition, $acting, true)) {
                $found[$column->disposition->value] = $column->disposition;
            }
        }
        // A column-wise table may also delete a population of its rows (notifications: the own feed).
        if (str_contains($rule->predicate ?? '', 'ERASE-ROW')) {
            $found[Disposition::EraseRow->value] = Disposition::EraseRow;
        }
        if ($found !== []) {
            $out[$table] = array_values($found);
        }
    }

    return $out;
}

it('has a selection for every table the registry says to act on', function (): void {
    $selected = array_unique(array_map(fn ($s): string => $s->table, Selections::all()));
    $missing = array_values(array_diff(array_keys(actingDispositions()), $selected));

    expect($missing)->toBe([], 'The registry says to act on these tables but no selection reaches them: '.implode(', ', $missing));
});

it('has no selection on a table the registry does not call for, and none with a disposition the registry did not give', function (): void {
    $acting = actingDispositions();

    foreach (Selections::all() as $selection) {
        expect(array_key_exists($selection->table, $acting))->toBeTrue("Selection {$selection->id} targets {$selection->table}, which the registry does not act on");
        expect(in_array($selection->disposition, $acting[$selection->table] ?? [], true))
            ->toBeTrue("Selection {$selection->id} is {$selection->disposition->value} but the registry gives {$selection->table} a different disposition");
    }
});

it('gives every selection a unique id, a plan stage and a description', function (): void {
    $ids = array_map(fn ($s): string => $s->id, Selections::all());

    expect($ids)->toBe(array_values(array_unique($ids)));
    foreach (Selections::all() as $selection) {
        expect($selection->stage)->toMatch('/^4[a-e]$/')
            ->and($selection->description)->not->toBe('');
    }
});

it('does not select a table that holds an anchor for deletion (anchors are never deleted)', function (): void {
    $anchors = ['users', 'creators', 'campaign_assignments', 'campaign_applications', 'campaign_drafts', 'contracts', 'messages', 'relationship_messages', 'message_threads', 'relationship_threads', 'agency_creator_relations', 'brand_creator_blacklists', 'audit_logs'];

    foreach (Selections::all() as $selection) {
        if (in_array($selection->table, $anchors, true)) {
            expect($selection->disposition)->not->toBe(Disposition::EraseRow, "{$selection->id} would delete an anchor");
        }
    }
});

it('compiles every selection for the pgsql grammar (production) without connecting', function (): void {
    $set = SubjectSet::resolve(ErasureGraph::seedSubject('a')->user);
    $pg = DB::connection('pgsql');

    $s5 = null;
    foreach (Selections::all() as $selection) {
        $sql = $selection->builder($set, $pg)->toSql();
        expect($sql !== '')->toBeTrue("{$selection->id} compiled to an empty statement");
        expect(str_contains($sql, 'json_extract'))->toBeFalse("{$selection->id} compiled sqlite-only JSON for pgsql");
        if ($selection->id === 'audit_logs.S5') {
            $s5 = $sql;
        }
    }

    expect($s5)->toBeString()->and(str_contains((string) $s5, '->>'))->toBeTrue();
});

it('contains no write call in the subject and selection code (Stage 3 reads only)', function (): void {
    $files = array_merge(glob(app_path('Modules/Erasure/Subject/*.php')) ?: [], glob(app_path('Modules/Erasure/Selection/*.php')) ?: []);
    expect($files)->not->toBe([]);

    foreach ($files as $file) {
        $code = (string) file_get_contents($file);
        expect(preg_match('/->(insert|insertOrIgnore|update|updateOrInsert|upsert|delete|forceDelete|truncate|save|create|increment|decrement|statement|unprepared)\(/', $code))
            ->toBe(0, basename($file).' contains a write call');
    }
});
