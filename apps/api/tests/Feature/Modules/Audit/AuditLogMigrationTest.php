<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('audit_logs table includes every column from docs/03-DATA-MODEL.md §12', function (): void {
    $expected = [
        'id',
        'ulid',
        'agency_id',
        'actor_type',
        'actor_id',
        'actor_role',
        'action',
        'subject_type',
        'subject_id',
        'subject_ulid',
        'reason',
        'metadata',
        'before',
        'after',
        'ip',
        'user_agent',
        'created_at',
    ];

    foreach ($expected as $column) {
        expect(Schema::hasColumn('audit_logs', $column))
            ->toBeTrue("audit_logs.{$column} is missing");
    }
});

it('audit_logs is append-only: no updated_at, no deleted_at', function (): void {
    expect(Schema::hasColumn('audit_logs', 'updated_at'))
        ->toBeFalse('audit_logs.updated_at must not exist (append-only contract).');
    expect(Schema::hasColumn('audit_logs', 'deleted_at'))
        ->toBeFalse('audit_logs.deleted_at must not exist (append-only contract).');
});

it('audit_logs has every index from docs/03-DATA-MODEL.md §12', function (): void {
    // Schema::getIndexes is driver-neutral (AH-119 A0: this used to read sqlite_master,
    // which cannot run on the Postgres leg).
    $indexes = collect(Schema::getIndexes('audit_logs'))->pluck('name')->all();

    foreach ([
        'idx_audit_actor',
        'idx_audit_subject',
        'idx_audit_action',
        'idx_audit_agency_created',
        'idx_audit_created_at',
    ] as $expected) {
        expect($indexes)->toContain($expected);
    }
});

it('audit_logs.ulid is unique', function (): void {
    $unique = collect(Schema::getIndexes('audit_logs'))
        ->filter(fn (array $index): bool => $index['unique'] && $index['columns'] === ['ulid']);

    expect($unique)->not->toBeEmpty('audit_logs.ulid has no unique index');
});
