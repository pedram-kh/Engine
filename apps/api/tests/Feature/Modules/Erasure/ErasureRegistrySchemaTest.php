<?php

declare(strict_types=1);

use App\Modules\Erasure\Registry\AnonValue;
use App\Modules\Erasure\Registry\ColumnRule;
use App\Modules\Erasure\Registry\Disposition;
use App\Modules\Erasure\Registry\ErasureRegistry;
use App\Modules\Erasure\Registry\RegistryVerifier;
use App\Modules\Erasure\Registry\TableRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * AH-104a Stage 2 — the registry is failing by default.
 *
 * The first test reads the REAL migrated schema and demands zero violations:
 * that is the guard CI runs. The rest feed the same verifier a mutated copy of
 * the schema (or registry) and demand the exact violation, so each of the five
 * failure modes is pinned both ways. The break-reverts additionally add a
 * scratch migration and watch the first test go red.
 */

/** @return array<string, array{columns: array<string, bool>, refs: list<string>}> */
function realSchema(): array
{
    return RegistryVerifier::snapshot();
}

/**
 * @param  array<string, array{columns: array<string, bool>, refs: list<string>}>  $schema
 * @param  list<string>|null  $refs
 * @return array<string, array{columns: array<string, bool>, refs: list<string>}>
 */
function schemaWith(array $schema, string $table, ?string $addColumn = null, ?string $dropColumn = null, ?array $refs = null): array
{
    $entry = $schema[$table] ?? ['columns' => ['id' => false], 'refs' => []];
    $columns = $entry['columns'];
    $tableRefs = $refs ?? $entry['refs'];
    if ($addColumn !== null) {
        $columns[$addColumn] = true;
    }
    if ($dropColumn !== null) {
        unset($columns[$dropColumn]);
    }
    $schema[$table] = ['columns' => $columns, 'refs' => $tableRefs];

    return $schema;
}

/**
 * @param  array<string, array{columns: array<string, bool>, refs: list<string>}>  $schema
 * @return array<string, array{columns: array<string, bool>, refs: list<string>}>
 */
function schemaWithoutTable(array $schema, string $table): array
{
    unset($schema[$table]);

    return $schema;
}

it('classifies every table, column and foreign key of the migrated schema', function (): void {
    $violations = RegistryVerifier::violations(realSchema(), ErasureRegistry::tables());

    expect($violations)->toBe([], "The erasure registry is out of step with the schema:\n - ".implode("\n - ", $violations));
});

it('fails on a table the registry does not name', function (): void {
    $schema = schemaWith(realSchema(), 'payments');

    expect(RegistryVerifier::violations($schema, ErasureRegistry::tables()))
        ->toContain('Unclassified table `payments`: add it to app/Modules/Erasure/Registry/ErasureRegistry.php (column level if it holds creator data or points at users/creators, otherwise N/A with a reason).');
});

it('fails on a column the registry does not name, on a column-level table', function (): void {
    $schema = schemaWith(realSchema(), 'creators', addColumn: 'passport_number');

    expect(RegistryVerifier::violations($schema, ErasureRegistry::tables()))
        ->toContain('Unclassified column `creators.passport_number`: decide it (ANON / ERASE-ROW / KEEP / SCRUB-JSON / DEL-MATCH) in app/Modules/Erasure/Registry/ErasureRegistry.php.');
});

it('fails on a registry entry for a column or a table that no longer exists', function (): void {
    $schema = schemaWithoutTable(schemaWith(realSchema(), 'creators', dropColumn: 'bio'), 'sessions');

    $violations = RegistryVerifier::violations($schema, ErasureRegistry::tables());

    expect($violations)
        ->toContain('Stale entry `creators.bio`: the column no longer exists; remove or rename it in app/Modules/Erasure/Registry/ErasureRegistry.php.')
        ->toContain('Stale entry for table `sessions`: it no longer exists; remove it from app/Modules/Erasure/Registry/ErasureRegistry.php.');
});

it('treats a renamed column as one unclassified plus one stale entry', function (): void {
    $schema = schemaWith(realSchema(), 'creators', addColumn: 'biography', dropColumn: 'bio');

    $violations = RegistryVerifier::violations($schema, ErasureRegistry::tables());

    expect(implode("\n", $violations))
        ->toContain('Unclassified column `creators.biography`')
        ->toContain('Stale entry `creators.bio`');
});

it('fails on a new foreign key to users or creators, even on an N/A table', function (): void {
    $schema = schemaWith(realSchema(), 'boards', addColumn: 'owner_user_id', refs: ['owner_user_id']);

    expect(RegistryVerifier::violations($schema, ErasureRegistry::tables()))
        ->toContain('New foreign key `boards.owner_user_id` to users/creators is not declared: add it to `refs` for `boards` in app/Modules/Erasure/Registry/ErasureRegistry.php and decide what erasure does with it.');
});

it('fails on a declared foreign key the schema no longer has', function (): void {
    $schema = schemaWith(realSchema(), 'user_push_tokens', refs: []);

    expect(RegistryVerifier::violations($schema, ErasureRegistry::tables()))
        ->toContain('Stale ref: the registry declares `user_push_tokens.user_id` as a foreign key to users/creators but the schema has none; fix `refs` in app/Modules/Erasure/Registry/ErasureRegistry.php.');
});

it('fails on a PII-shaped column on a table-level (N/A) table that is not classified', function (): void {
    $schema = schemaWith(schemaWith(realSchema(), 'boards', addColumn: 'contact_email'), 'cache', addColumn: 'owner_phone');

    $violations = implode("\n", RegistryVerifier::violations($schema, ErasureRegistry::tables()));

    expect($violations)
        ->toContain('PII-shaped column `boards.contact_email`')
        ->toContain('PII-shaped column `cache.owner_phone`');
});

it('does not match a PII name pattern inside a longer word', function (): void {
    expect(RegistryVerifier::looksPersonal('expiration'))->toBeFalse()
        ->and(RegistryVerifier::looksPersonal('description'))->toBeFalse()
        ->and(RegistryVerifier::looksPersonal('ip_allowlist'))->toBeTrue()
        ->and(RegistryVerifier::looksPersonal('billing_email'))->toBeTrue()
        ->and(RegistryVerifier::looksPersonal('legal_name'))->toBeTrue();
});

it('refuses an ANON rule that cannot execute', function (): void {
    $registry = ErasureRegistry::tables();
    $creators = $registry['creators'];
    $columns = $creators->columns;
    $columns['phone'] = new ColumnRule(Disposition::Anon, 'no value given');
    $registry['creators'] = new TableRule(null, $creators->reason, $creators->refs, $columns);

    expect(RegistryVerifier::violations(realSchema(), $registry))
        ->toContain('ANON column `creators.phone` has no AnonValue: an ANON rule that cannot execute is a hole.');
});

it('refuses NULL as the anonymised value of a NOT NULL column (a sentinel, never a migration)', function (): void {
    $registry = ErasureRegistry::tables();
    $users = $registry['users'];
    $columns = $users->columns;
    $columns['email'] = new ColumnRule(Disposition::Anon, 'wrongly nulled', AnonValue::Null);
    $registry['users'] = new TableRule(null, $users->reason, $users->refs, $columns);

    expect(RegistryVerifier::violations(realSchema(), $registry))
        ->toContain('ANON column `users.email` is NULL on a NOT NULL column: use a sentinel AnonValue (never a migration).');
});

it('keeps the headline decisions of the approved map in place', function (): void {
    $t = ErasureRegistry::tables();

    expect($t['users']->columns['email']->anon)->toBe(AnonValue::EmailPlaceholder)
        ->and($t['users']->columns['erased_at']->anon)->toBe(AnonValue::Now)
        ->and($t['users']->columns['deleted_at']->disposition)->toBe(Disposition::Keep)
        ->and($t['creator_tax_profiles']->columns['legal_name']->anon)->toBe(AnonValue::EncryptedErased)
        ->and($t['creator_tax_profiles']->columns['tax_id_country']->disposition)->toBe(Disposition::Keep)
        ->and($t['campaign_applications']->columns['note']->anon)->toBe(AnonValue::Null)
        ->and($t['agency_creator_relations']->columns['invitation_token_hash']->anon)->toBe(AnonValue::Null)
        ->and($t['campaign_posted_content']->columns['post_url']->disposition)->toBe(Disposition::Keep)
        ->and($t['messages']->columns['body']->disposition)->toBe(Disposition::Keep)
        ->and($t['messages']->columns['attachments']->disposition)->toBe(Disposition::ScrubJson)
        ->and($t['creator_payout_methods']->disposition)->toBe(Disposition::EraseRow)
        ->and($t['talent_pool_creators']->disposition)->toBe(Disposition::EraseRow);
});

it('never lists an anchor as ERASE-ROW (anchors are never deleted)', function (): void {
    $anchors = ['users', 'creators', 'campaign_assignments', 'campaign_applications', 'campaign_drafts', 'contracts', 'messages', 'relationship_messages', 'message_threads', 'relationship_threads', 'agency_creator_relations', 'brand_creator_blacklists', 'audit_logs', 'campaign_posted_content'];

    foreach ($anchors as $table) {
        $rule = ErasureRegistry::tables()[$table];
        expect($rule->disposition)->not->toBe(Disposition::EraseRow, "{$table} is an anchor");
        foreach ($rule->columns as $col => $cr) {
            expect($cr->disposition)->not->toBe(Disposition::EraseRow, "{$table}.{$col} is on an anchor");
        }
    }
});
