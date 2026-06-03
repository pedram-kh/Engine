<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * Adds the spec'd Postgres full-text search column on `creators`
 * (docs/03-DATA-MODEL.md:219 — "Postgres full-text index on `display_name`,
 * `bio` — combined `tsvector` column"). Sprint 6 Chunk 1 (D-1).
 *
 * Shape: a STORED generated column `search_vector` built from
 * `to_tsvector('simple', display_name || bio)` + a GIN index over it. The
 * agency roster's `?q=` filter (AgencyCreatorController) compiles to
 * `search_vector @@ plainto_tsquery('simple', ?)` on Postgres.
 *
 * Driver guard: the ENTIRE column + index lives behind a `pgsql` guard —
 * mirroring the existing `idx_creators_categories_gin` block in
 * `2026_05_14_100000_create_creators_table.php`. `tsvector`, generated
 * columns and GIN are Postgres-only; the SQLite `:memory:` test DB has no
 * equivalent. So on SQLite the column simply does not exist, and the
 * roster's SQLite fallback searches `display_name`/`bio` directly with
 * `LOWER(...) LIKE` (see AgencyCreatorController::applyCreatorFilters) — it
 * never references `search_vector`. This is the documented untestable seam
 * (docs/tech-debt.md — SQLite-in-tests vs Postgres-in-prod): the FTS branch
 * is exercised only under Postgres (manual local verification + a dormant
 * `markTestSkipped()` counterpart test until Postgres CI lands ~Sprint 8).
 *
 * `'simple'` config (not `'english'`): no stemming, so the Postgres token
 * match stays as close as practical to the SQLite substring fallback. The
 * remaining divergence (FTS matches whole-word lexemes; ILIKE matches
 * substrings) is documented, not papered over.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        Schema::getConnection()->statement(<<<'SQL'
            ALTER TABLE creators
                ADD COLUMN search_vector tsvector
                GENERATED ALWAYS AS (
                    to_tsvector(
                        'simple',
                        coalesce(display_name, '') || ' ' || coalesce(bio, '')
                    )
                ) STORED
            SQL);

        Schema::getConnection()->statement(
            'CREATE INDEX idx_creators_search_gin ON creators USING GIN (search_vector)',
        );
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        Schema::getConnection()->statement('DROP INDEX IF EXISTS idx_creators_search_gin');
        Schema::getConnection()->statement('ALTER TABLE creators DROP COLUMN IF EXISTS search_vector');
    }
};
