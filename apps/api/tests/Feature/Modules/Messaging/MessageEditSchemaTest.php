<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * AH-119 B1 — the schema of edit/delete: four nullable columns on BOTH message tables, an FK for the
 * deleter, GIN search indexes on Postgres only, and migrations that move no data.
 */

const MIGRATION_COLUMNS = '2026_11_06_110000_add_edit_and_delete_columns_to_messages.php';
const MIGRATION_INDEXES = '2026_11_06_110001_add_body_search_indexes_to_messages.php';

function migrationSource(string $file): string
{
    return (string) file_get_contents(database_path('migrations/'.$file));
}

it('adds the four columns to both tables, all nullable', function (string $table): void {
    foreach (['edited_at', 'original_body', 'deleted_by_user_id', 'deleted_body'] as $column) {
        expect(Schema::hasColumn($table, $column))->toBeTrue("{$table}.{$column} missing");
    }
    $columns = collect(Schema::getColumns($table))->keyBy('name');
    foreach (['edited_at', 'original_body', 'deleted_by_user_id', 'deleted_body'] as $column) {
        expect($columns[$column]['nullable'])->toBeTrue("{$table}.{$column} must be nullable");
        expect($columns[$column]['default'])->toBeNull("{$table}.{$column} must have no default");
    }
    expect($columns['body']['nullable'])->toBeTrue('body must be nullable so a delete can clear it');
})->with(['messages', 'relationship_messages']);

it('keeps deleted_by_user_id as a foreign key to users that refuses to orphan', function (string $table): void {
    $fk = collect(Schema::getForeignKeys($table))->first(fn (array $f): bool => $f['columns'] === ['deleted_by_user_id']);

    expect($fk)->not->toBeNull()
        ->and($fk['foreign_table'])->toBe('users')
        ->and($fk['on_delete'])->toBe('restrict');
})->with(['messages', 'relationship_messages']);

it('stores and returns the new columns', function (string $table): void {
    $w = null;
    require_once __DIR__.'/../../../Support/MessageRevisionFixtures.php';
    $w = revWorld();
    $message = $table === 'messages'
        ? revCampaignMessage($w['thread'], $w['admin'], false, 'hello')
        : revDirectMessage($w['direct'], $w['admin'], false, 'hello');

    DB::table($table)->where('id', $message->id)->update(['edited_at' => now(), 'original_body' => 'a', 'deleted_body' => 'b', 'deleted_by_user_id' => $w['admin']->id]);

    $row = revRow($table, $message->ulid);
    expect($row->original_body)->toBe('a')->and($row->deleted_body)->toBe('b')->and((int) $row->deleted_by_user_id)->toBe($w['admin']->id)->and($row->edited_at)->not->toBeNull();
})->with(['messages', 'relationship_messages']);

it('rolls back to the old shape and up again, structure only', function (): void {
    $migration = require database_path('migrations/'.MIGRATION_COLUMNS);

    $migration->down();
    foreach (['messages', 'relationship_messages'] as $table) {
        foreach (['edited_at', 'original_body', 'deleted_by_user_id', 'deleted_body'] as $column) {
            expect(Schema::hasColumn($table, $column))->toBeFalse("{$table}.{$column} should be gone");
        }
        expect(Schema::hasColumn($table, 'deleted_at'))->toBeTrue('the SoftDeletes marker is not ours to drop');
    }

    $migration->up();
    foreach (['messages', 'relationship_messages'] as $table) {
        expect(Schema::hasColumns($table, ['edited_at', 'original_body', 'deleted_by_user_id', 'deleted_body']))->toBeTrue();
    }
});

it('creates the two GIN full-text indexes on Postgres and nothing on other drivers', function (): void {
    $names = DB::connection()->getDriverName() === 'pgsql'
        ? DB::table('pg_indexes')->where('indexname', 'like', '%body_fts')->pluck('indexname')->sort()->values()->all()
        : collect(['messages', 'relationship_messages'])->flatMap(fn ($t) => collect(Schema::getIndexes($t))->pluck('name'))->filter(fn ($n) => str_contains($n, 'body_fts'))->values()->all();

    expect($names)->toBe(DB::connection()->getDriverName() === 'pgsql' ? ['idx_messages_body_fts', 'idx_relationship_messages_body_fts'] : []);
});

it('guards the index migration to Postgres and drops the indexes on the way down', function (): void {
    $source = migrationSource(MIGRATION_INDEXES);

    expect($source)->toContain("getDriverName() !== 'pgsql'")
        ->and($source)->toContain('USING gin')
        ->and($source)->toContain("to_tsvector('simple', coalesce(body, ''))")
        ->and($source)->toContain('DROP INDEX IF EXISTS');
});

it('moves no data: both migrations are schema-only', function (string $file): void {
    $source = migrationSource($file);

    foreach (['DB::table', 'DB::insert', 'DB::update', 'DB::delete', '->insert(', '->update(', 'Model::', 'Artisan::', 'DB::unprepared'] as $needle) {
        expect($source)->not->toContain($needle);
    }
})->with([MIGRATION_COLUMNS, MIGRATION_INDEXES]);
