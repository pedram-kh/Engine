<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * PL-2.4 (G3) — the PL migrations were edited in place, so a database that ran the OLD files is not updated by
 * `php artisan migrate`. Pest and CI build the schema from nothing, so this is green there by construction; it goes red on a
 * long-lived test database that is migrated but never refreshed, and says what to do. It cannot see a developer's own
 * database (tests never touch it): for that the instruction in docs/reviews/pl-2-4-review.md is the guard.
 */

const PL_MARKET_TABLES = ['wave_personas', 'wave_matrix_cells', 'wave_concepts', 'wave_concept_usps', 'wave_concept_images', 'wave_concept_links', 'wave_concept_decisions'];

it('the PL schema is not stale: market_id on the seven tables, no "market" column, no waves.markets, and the two market tables exist', function (): void {
    $problems = [];

    foreach (PL_MARKET_TABLES as $table) {
        $columns = Schema::getColumnListing($table);
        if (in_array('market', $columns, true) && ! in_array('market_id', $columns, true)) {
            $problems[] = "PL schema is stale: {$table} still has the column \"market\" (char) and no \"market_id\".";
        } elseif (in_array('market', $columns, true)) {
            $problems[] = "PL schema is stale: {$table} has both \"market\" and \"market_id\".";
        } elseif (! in_array('market_id', $columns, true)) {
            $problems[] = "PL schema is stale: {$table} has no \"market_id\".";
        }
    }
    if (in_array('markets', Schema::getColumnListing('waves'), true)) {
        $problems[] = 'PL schema is stale: waves still has the column "markets" (json).';
    }
    foreach (['wave_markets', 'wave_market_countries'] as $table) {
        if (! Schema::hasTable($table)) {
            $problems[] = "PL schema is stale: the table {$table} does not exist.";
        }
    }

    $instruction = $problems === [] ? '' : implode("\n", $problems)."\n"
        .'The PL migrations were edited in place in PL-2.4 (WORKING-PROCESS.md section 5), so a database that ran the old files is not updated by `php artisan migrate`.'."\n"
        .'Rebuild ONLY the PL tables of a throwaway or local development database: see docs/reviews/pl-2-4-review.md, "Rebuilding a stale local database".';

    expect($problems)->toBe([], "\n".$instruction);
});
