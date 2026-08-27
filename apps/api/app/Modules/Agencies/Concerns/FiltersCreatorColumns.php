<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Concerns;

use App\Modules\Agencies\Http\Controllers\AgencyCreatorController;
use App\Modules\Agencies\Http\Controllers\AgencyCreatorDiscoveryController;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The creator-column filter + full-text search logic, shared between the
 * agency roster list ({@see AgencyCreatorController})
 * and the global discovery surface
 * ({@see AgencyCreatorDiscoveryController}).
 *
 * These were originally PRIVATE methods on AgencyCreatorController (Sprint 4
 * Chunk 5 + Sprint 6 Chunk 1). Sprint 6.6a extracts them VERBATIM into this
 * trait (D-3) so both surfaces apply the SAME country / language / category /
 * `?q=` semantics from a single source — critically the driver-aware FTS
 * branch (Postgres `to_tsquery` prefix lexemes vs the SQLite `LIKE` fallback),
 * which must never be duplicated (a copy would drift, and the Postgres path is
 * the untestable seam from Chunk 1). The extraction is behaviour-preserving:
 * the roster controller's call-sites are unchanged, so its tests stay green
 * (the extraction's safety net, honest-deviation trigger #1).
 *
 * Both consumers operate on a creator-columns builder: the roster applies them
 * inside `whereHas('creator', ...)` (a sub-builder on `creators`); discovery
 * applies them directly on `Creator::query()`. Either way the predicates only
 * ever reference `creators` columns (display_name / bio / country_code /
 * primary_language / categories / date_of_birth) — never a relation join —
 * which is exactly what makes them content-reusable. The relation-coupled
 * filters (status, availability) are NOT here: they stay private to the roster
 * controller.
 *
 * AH-088 adds `age_min`/`age_max` here rather than to the roster controller, on
 * the trait's OWN criterion: an age predicate is a pure `creators`-column
 * predicate, so anywhere else reopens exactly the drift this trait exists to
 * prevent. The consequence is deliberate and tested: the discover endpoint
 * ACCEPTS both parameters even though the discover UI ships no age filter
 * (AH-088 D7 is a UI-only fence). The symmetry is a contract, not an accident —
 * see the discover-side age-filter test.
 */
trait FiltersCreatorColumns
{
    /**
     * The clamp bounds for `?age_min=`/`?age_max=` (AH-088). Sanity rails on a
     * query string, NOT a policy about who may use the platform: there is no
     * minimum-age rule anywhere in the stack (offered and declined, D8), and the
     * write path's own bounds are equally permissive.
     */
    private const int AGE_FILTER_MIN = 0;

    private const int AGE_FILTER_MAX = 120;

    /**
     * Apply the creator-column filters. Each is optional and composable (they
     * AND together).
     *
     * `?category=` uses whereJsonContains: on Postgres this compiles to the
     * `@>` containment operator served by idx_creators_categories_gin; on the
     * SQLite test DB it compiles to a `json_each(...)` EXISTS — so the query
     * degrades gracefully across both drivers with no branching.
     *
     * `?q=` (FTS) is the exception that DOES need a driver branch — see
     * {@see self::applySearchFilter}.
     *
     * The `@template` (vs a bare `Builder<Model>`) lets both consumers pass
     * their concrete builder — the roster's `whereHas('creator')` sub-builder
     * AND discovery's `Creator::query()` — without tripping Builder's invariant
     * TModel (the type is the same creators table either way).
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $creatorQuery
     */
    private function applyCreatorFilters(Builder $creatorQuery, Request $request): void
    {
        $country = $request->query('country');
        if (is_string($country) && $country !== '') {
            $creatorQuery->where('country_code', $country);
        }

        $language = $request->query('language');
        if (is_string($language) && $language !== '') {
            $creatorQuery->where('primary_language', $language);
        }

        $category = $request->query('category');
        if (is_string($category) && $category !== '') {
            $creatorQuery->whereJsonContains('categories', $category);
        }

        $this->applyAgeFilters($creatorQuery, $request);

        $search = $request->query('q');
        if (is_string($search) && trim($search) !== '') {
            $this->applySearchFilter($creatorQuery, trim($search));
        }
    }

    /**
     * `?age_min=` / `?age_max=` — an inclusive age-range filter (AH-088 D7),
     * expressed as date bounds on `date_of_birth` so the database does the work
     * and no per-row derivation is needed.
     *
     * The algebra, with `age` = whole years elapsed (the {@see
     * \App\Modules\Creators\Models\Creator::age()} rule, where a birthday TODAY
     * has already counted):
     *
     *   age >= min  ⟺  date_of_birth <= today - min years
     *   age <= max  ⟺  age < max + 1  ⟺  date_of_birth > today - (max + 1) years
     *
     * Keep those two lines and Creator::age() in agreement — a creator shown as
     * 24 must be returned by `age_min=24` and by `age_max=24`. Both directions
     * are pinned at the exact boundary in the roster filter tests.
     *
     * `whereDate`, NOT a plain `where`, and this is load-bearing rather than
     * stylistic: the two drivers store this column differently. Postgres holds a
     * real `date` (`2001-08-27`), while SQLite receives the model's default
     * datetime format (`2001-08-27 00:00:00`) — so a raw string comparison
     * against `'2001-08-27'` is true on Postgres and FALSE on SQLite at exactly
     * the boundary. That divergence would have shipped as a passing CI suite and
     * an off-by-one-day filter in production, or the reverse. whereDate
     * normalizes both sides to a date and costs nothing on a real `date` column.
     *
     * SEMANTICS WORTH SAYING OUT LOUD (AH-088 O5): because SQL comparisons
     * against NULL are never true, ANY age bound excludes creators with no
     * birthday on file — which on the day this ships is all ~279 of them. That
     * is intended (an age filter cannot honestly claim an unknown age matches),
     * but it means the filter looks "broken" until creators backfill. An
     * "include unknown age" toggle is the named follow-up; it is not built.
     *
     * VALIDATION POSTURE (Q5): permissive read, clamped in code — the posture
     * every other filter here uses. Junk is ignored rather than 422'd (there is
     * no FormRequest on either read endpoint, and adding one to discover only
     * would make the two behave differently for the same parameter), and an
     * out-of-range number is clamped instead of rejected. A reversed range
     * (min > max) needs no special case: the two bounds simply cannot both hold,
     * so the result is empty, which is what the caller asked for.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $creatorQuery
     */
    private function applyAgeFilters(Builder $creatorQuery, Request $request): void
    {
        $min = $this->clampedAge($request->query('age_min'));
        if ($min !== null) {
            $creatorQuery->whereDate('date_of_birth', '<=', Carbon::today()->subYears($min));
        }

        $max = $this->clampedAge($request->query('age_max'));
        if ($max !== null) {
            $creatorQuery->whereDate('date_of_birth', '>', Carbon::today()->subYears($max + 1));
        }
    }

    /**
     * A query-string age bound as an int within {@see self::AGE_FILTER_MIN}..
     * {@see self::AGE_FILTER_MAX}, or null when the value is absent/not a number.
     *
     * `''` returns null (a cleared filter input is "no filter", not "age 0") and
     * so does `'abc'`. A numeric value outside the range is clamped rather than
     * dropped, so `age_min=999` narrows to nobody instead of silently widening to
     * everybody — the safer failure for a filter.
     */
    private function clampedAge(mixed $value): ?int
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $raw = trim((string) $value);
        if ($raw === '' || ! ctype_digit(ltrim($raw, '-'))) {
            return null;
        }

        return max(self::AGE_FILTER_MIN, min(self::AGE_FILTER_MAX, (int) $raw));
    }

    /**
     * Name/bio full-text search (Sprint 6 Chunk 1, D-1).
     *
     * Driver-aware because FTS has no portable grammar-level degrade (unlike
     * `whereJsonContains`):
     *
     *   - Postgres: `search_vector @@ to_tsquery('simple', ?)` against the
     *     generated `tsvector` column + GIN index from the pgsql-guarded
     *     migration. Each whitespace-separated word becomes a PREFIX lexeme
     *     `word:*`, ANDed together — so `dis` matches `disply` (type-ahead) and
     *     a multi-word `q` narrows (every word's prefix must match some token).
     *     Tokens are stripped to letters/digits before being handed to
     *     to_tsquery so user input can never break its operator grammar.
     *   - SQLite (test + local dev): `LOWER(...) LIKE` substring match over
     *     `display_name` + `bio`. There is no `tsvector`/`search_vector` column
     *     on SQLite (the migration skips it), so the fallback queries the raw
     *     columns directly. `%`/`_` in the needle are escaped so they're treated
     *     as literals, not wildcards.
     *
     * Result-semantics divergence (D-3): the Postgres path matches by PREFIX
     * (left-anchored within each token) while the SQLite path matches
     * substrings ANYWHERE. The `'simple'` tsvector config keeps the two as
     * close as practical (no stemming). The SQLite fallback is the path the CI
     * suite actually exercises and is fully tested; the Postgres prefix branch
     * is verified by a manual local-Postgres pass + a dormant `markTestSkipped`
     * counterpart until Postgres CI lands (~Sprint 8).
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $creatorQuery
     */
    private function applySearchFilter(Builder $creatorQuery, string $search): void
    {
        // `getConnection()` on an Eloquent builder returns a ConnectionInterface,
        // which does not declare getDriverName(). The concrete value is always a
        // \Illuminate\Database\Connection subclass (Postgres in prod, SQLite under
        // test), so we narrow inline for Larastan — mirrors MembershipController.
        $connection = $creatorQuery->getConnection();
        /** @var Connection $connection */
        $isPostgres = $connection->getDriverName() === 'pgsql';

        if ($isPostgres) {
            // PREFIX (type-ahead) match: turn each whitespace-separated word
            // into a prefix lexeme `word:*` and AND them, so `dis` matches
            // `disply` and a multi-word query narrows (every word's prefix must
            // match some token). We build the tsquery by hand rather than use
            // plainto_tsquery (which has no prefix support); each token is
            // stripped to letters/digits so raw user input can never reach
            // to_tsquery's operator grammar (`& | ! ( ) : *`). Tokens that
            // reduce to empty are dropped; if nothing usable survives, match
            // nothing (a query of only punctuation is not "match everything").
            $lexemes = [];
            foreach (preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
                $clean = preg_replace('/[^\p{L}\p{N}]+/u', '', $token);
                if (is_string($clean) && $clean !== '') {
                    $lexemes[] = $clean.':*';
                }
            }

            if ($lexemes === []) {
                $creatorQuery->whereRaw('1 = 0');

                return;
            }

            $creatorQuery->whereRaw(
                "search_vector @@ to_tsquery('simple', ?)",
                [implode(' & ', $lexemes)],
            );

            return;
        }

        // Escape LIKE wildcards so a literal `%`/`_`/`\` in the search term is
        // matched as itself; the ESCAPE clause makes `\` the escape char (SQLite
        // does not treat `\` as an escape by default).
        $needle = mb_strtolower($search);
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $needle).'%';

        $creatorQuery->where(function (Builder $inner) use ($like): void {
            $inner->whereRaw("LOWER(display_name) LIKE ? ESCAPE '\\'", [$like])
                ->orWhereRaw("LOWER(bio) LIKE ? ESCAPE '\\'", [$like]);
        });
    }
}
