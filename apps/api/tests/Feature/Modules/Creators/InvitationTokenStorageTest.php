<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Creators\Services\BulkInviteService;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| Q1 hardening — unhashed invitation tokens MUST NEVER be persisted
|--------------------------------------------------------------------------
|
| Source-inspection regression test (#1). The bulk-invite service
| generates the token in memory, hashes it with SHA-256, persists ONLY
| the hash on `agency_creator_relations.invitation_token_hash`, and
| ships the unhashed token to the email recipient. The unhashed token
| then exists only in the recipient's inbox.
|
| Break-revert verification per #1:
|   1. Add `'invitation_token' => $token` to the BulkInviteService
|      AgencyCreatorRelation::create() payload;
|   2. Run this test → fails on the schema-column assertion (no such
|      column exists, and we assert the model fillable list excludes
|      the token);
|   3. Revert.
*/

it('AgencyCreatorRelation has no invitation_token column on the model', function (): void {
    $relation = new AgencyCreatorRelation;
    $fillable = $relation->getFillable();

    expect($fillable)->toContain('invitation_token_hash');
    expect(in_array('invitation_token', $fillable, true))->toBeFalse('invitation_token must NEVER be in the fillable list');
});

it('BulkInviteService never assigns a non-hash token field', function (): void {
    $reflection = new ReflectionClass(BulkInviteService::class);
    $filename = $reflection->getFileName();
    expect($filename)->toBeString();
    /** @var string $filename */
    $body = file_get_contents($filename);
    expect($body)->toBeString();
    /** @var string $body */

    // The token is generated and immediately hashed; the unhashed
    // token MUST only flow into the mailable, never into a database
    // column. Assert that no schema column matching /invitation_token[^_]/
    // (i.e. NOT invitation_token_hash) appears in the create() payload.
    expect($body)->toMatch('/\$hash = hash\(.sha256., \$token\);/')
        ->and($body)->not->toMatch("/'invitation_token'\s*=>/");
});

it('AgencyCreatorRelation migration declares invitation_token_hash as char(64)', function (): void {
    $migration = file_get_contents(database_path('migrations/2026_05_14_100007_create_agency_creator_relations_table.php'));
    expect($migration)->toBeString();
    /** @var string $migration */
    expect($migration)->toContain("\$table->char('invitation_token_hash', 64)->nullable();")
        ->and($migration)->not->toContain("varchar('invitation_token'");
});
