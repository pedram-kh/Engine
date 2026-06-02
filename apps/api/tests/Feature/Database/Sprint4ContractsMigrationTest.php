<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Sprint 4 Chunk 4 — contracts table column-set migration test
|--------------------------------------------------------------------------
|
| Asserts the `contracts` table ships the FULL spec shape from
| docs/03-DATA-MODEL.md §8 (`:570–597`) — not the subset the kickoff named
| — so the e-sign vendor chunk inherits the envelope columns rather than
| re-migrating. Mirrors Sprint3MigrationTest's discipline.
|
*/

it('contracts table has every Phase 1 column from docs/03-DATA-MODEL.md §8', function (): void {
    expect(Schema::hasTable('contracts'))->toBeTrue();

    $expected = [
        'id',
        'ulid',
        'agency_id',
        'kind',
        'subject_type',
        'subject_id',
        'template_id',
        'version',
        'title',
        'body_markdown',
        'body_pdf_path',
        'signature_provider',
        'signature_envelope_id',
        'status',
        'sent_at',
        'signed_at',
        'signed_by_creator_id',
        'signed_signature_data',
        'expires_at',
        'created_by_user_id',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    foreach ($expected as $column) {
        expect(Schema::hasColumn('contracts', $column))
            ->toBeTrue("contracts.{$column} should exist");
    }
});

it('creators.signed_master_contract_id still exists (FK constraint deferred, column unchanged)', function (): void {
    expect(Schema::hasColumn('creators', 'signed_master_contract_id'))->toBeTrue();
});
