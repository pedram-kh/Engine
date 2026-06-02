# Sprint 4 — Chunk 5 Review

**Status:** Closed.

**Author:** Cursor (build + self-review draft).

**Scope:** The agency roster list ("my creators") — a **rich-but-bounded** read surface listing an agency's creators across **all** `relationship_status` values (`roster`/`prospect`/`external`), with the four filters that have backing data today (status / country / language / category), paginated + sorted. A deliberately-scoped forerunner of Sprint 6 (Internal creator matching): the matching engine — FTS search, talent pools, metrics/availability filters, per-creator agency detail, roster-management writes — stays Sprint 6. **One chunk: backend endpoint + filters → frontend page.**

**Reviewed against:** `03-DATA-MODEL.md` (`creators §`, `agency_creator_relations §351`, indexes `:215–219`/`:380–381`, the FTS `tsvector` spec `:219`), `20-PHASE-1-SPEC.md` Sprint 6 (the deferral line), `02-CONVENTIONS.md` (modular monolith, ULID, tenancy), `07-TESTING.md` §5.17 (defense-in-depth) + §5.35 (break-revert), the locked decisions **D-c5-1…D-c5-6**, and the pre-kickoff read-pass inventory (B1–B8 / F1–F4).

---

## Divergences from the kickoff

No scope divergences. The build followed D-c5-1…D-c5-6 as written. Two reportable implementation notes (neither changes scope):

- **The `?category=` jsonb filter degrades gracefully across drivers — no branching needed.** The honest-deviation trigger flagged a possible SQLite-vs-Postgres divergence on jsonb containment. Verified that Laravel 11's query grammar implements `whereJsonContains` on **both**: Postgres compiles to the `@>` containment operator (served by `idx_creators_categories_gin`), and the SQLite test grammar compiles to a `json_each(...)` `EXISTS` (`vendor/.../Query/Grammars/SQLiteGrammar.php:195`). So a single `whereJsonContains('categories', $category)` works on the SQLite `:memory:` test DB **and** uses the GIN index in production. No driver guard required; the category filter is exercised by a real test.
- **Frontend test footprint:** Vuetify's `VSelect` (teleported `VOverlay`/`VMenu`) and `VDataTableServer` leak across jsdom mounts and OOM the worker at scale (`BrandListPage.spec` survives only because it has no selects). The roster spec therefore renders the **real** data-table in exactly one row-DOM test and stubs it in the other four (which assert logic/empty/error/filters via component refs + the mocked API); `VSelect` is always stubbed. No coverage is lost — filter param-passing is asserted through the refs. The read-only rating is rendered as lightweight star `v-icon`s rather than `v-rating` (also a jsdom memory hog), which is display-only by construction.

---

## What was built

### Backend — `GET /api/v1/agencies/{agency}/creators` (D-c5-1, D-c5-5, D-c5-6)

`AgencyCreatorController::index` (Agencies module, in the existing `auth:web → tenancy.agency → tenancy` route group):

- **Tenancy (D-c5-6):** lists `agency_creator_relations` scoped by the `BelongsToAgency` global scope **plus** a belt-and-suspenders `where('agency_creator_relations.agency_id', $agency->id)` (mirrors the chunk-1 dashboard precedent). Gated by `AgencyCreatorRelationPolicy::viewAny` (any agency member — admin/manager/staff — mirroring `BrandPolicy::viewAny`), registered in `AgenciesServiceProvider`.
- **Join + soft-delete:** filters/soft-delete via `whereHas('creator', …)` (the `Creator` SoftDeletes scope applies to the `EXISTS` subquery, so soft-deleted creators drop out — consistent with the dashboard count), with the slim creator columns eager-loaded via `with('creator:id,ulid,display_name,country_code,primary_language,categories')` — **no N+1, no signed-URL minting, no heavy relations.**
- **Filters (all optional, composable, AND together):** `?status=` (relationship_status; unknown value → empty page via `RelationshipStatus::tryFrom` → `whereRaw('1=0')`, the admin-index precedent), `?country=` (`country_code` + `idx_creators_country_code`), `?language=` (`primary_language`), `?category=` (jsonb containment, GIN on pgsql).
- **Pagination + sort:** `per_page` clamped 1–100 (default 25); hand-rolled `{data, meta:{total, page, per_page, last_page}}` shape (mirrors `AdminCreatorController::index`). **Default sort: creator `display_name` ASC** via a correlated subquery (avoids a join + hydration clobber), tiebroken by `agency_creator_relations.id`.
- **Slim row (D-c5-5):** `{ relationship_status, is_blacklisted, internal_rating, total_campaigns_completed, total_paid_minor_units, last_engaged_at, creator_id, display_name, country_code, primary_language, categories }`. Carries `internal_rating` (read-only); **omits `internal_notes`** (GDPR-sensitive, audit-excluded) and **all signed media URLs**.

**`is_blacklisted` — included here, excluded in the KPI (intentional):** blacklisted relations appear in this list **with the flag visible**. This differs from the dashboard `creators_in_roster` KPI, which _excludes_ them (`DashboardSummaryController`). The rationale: a **count of the active roster** (KPI) and a **management list** (this surface) are different things — the agency must be able to see whom they've blacklisted within their own roster. Both behaviours are pinned by tests.

### Decisions held (no surface added)

- **D-c5-2 — FTS search deferred** to Sprint 6 (logged to `tech-debt.md` with the SQLite-divergence note). The `tsvector` column + index were confirmed spec-only/not-built by the inventory.
- **D-c5-3 — `internal_rating` read-only; `internal_notes` never surfaced.** No write endpoint added; the resource omits notes entirely.
- **D-c5-4 — rows do NOT navigate.** No agency-side creator detail surface exists and the admin drill-in's `auth:web_admin`/`platform_admin` gate is untouched. The list is complete on its own; per-creator detail lands with Sprint 6.

### Frontend — agency SPA roster page (`apps/main`)

- New `roster.list` route (`/roster`, layout `agency`, guard `requireAuth`) + a nav entry in `AgencyLayout` (`mdi-account-multiple-outline`, between Dashboard and Brands).
- `CreatorRosterPage.vue` — a `v-data-table-server` mirroring `BrandListPage` (component-local refs + a new `roster.api.ts`; tenancy via `useAgencyStore().currentAgencyId`; explicit `onMounted` fetch + a `currentAgencyId` watcher for async store init / workspace switch).
- **Multi-filter composition (net-new — F3 found only single-filter precedents):** a status `v-chip-group` + `country`/`language`/`category` `v-select`s; any change resets to page 1 and re-queries.
- Rows show name, status chip, country, language, category chips, **read-only star rating**, a blacklist flag chip, and the campaigns counter. **No row navigation.**
- `CEmptyState` two-variant usage (no-creators vs no-filter-match), mirroring `BrandListPage`.
- New `app.roster.*` i18n namespace + `app.nav.roster` label in **en / pt / it** (category labels reuse the canonical `creator.ui.wizard.categories.*` keys; country options reuse the shared `COUNTRY_OPTIONS` launch-market list).
- `RosterCreatorListItem` / `RosterListResponse` / `RosterListParams` / `RosterRelationshipStatus` added to `@catalyst/api-client`.

---

## Coverage (§5.17 defense-in-depth; §5.35 break-revert, git-verified)

**Backend — `AgencyCreatorRosterTest` (17):**

- **Auth/tenancy:** 401 unauth; 404 non-member (tenancy.agency invisibility); 200 any member (staff included — no admin/MFA gate).
- **Tenancy isolation** — agency A never lists agency B's relations. _Break-revert: drop the `agency_id` scope → cross-tenant rows leak in, the single-row assertion fails._
- **All relationship statuses** appear (roster/prospect/external).
- **Slim resource** carries `internal_rating` but **not** `internal_notes` (asserted on the body **and** the keys) and **no** `avatar_url`/`cover_url`. _Break-revert: add `internal_notes` to the row → the no-notes assertion fails._
- Each filter narrows (status, country, language, category-jsonb); **combined filters AND**; unknown status → empty page.
- Pagination meta (`total/page/per_page/last_page`) + `per_page` clamp to 100.
- **Blacklisted included** with the flag visible; **soft-deleted creators excluded**; default sort = `display_name` ASC.

**Frontend — `CreatorRosterPage.spec.ts` (5):** loads scoped to `currentAgencyId` + renders a rich, **non-navigating** row (status chip, read-only rating, blacklist flag, name is a `<span>` — D-c5-4); no API call when no current agency; each filter re-queries + combined filters AND; the two empty-state variants; localized error surfaced.

**Results:** backend `AgencyCreatorRosterTest` 17 passed (50 assertions); full Agencies module 106 passed (281 assertions). PHPStan: `No errors`. Pint: applied/clean. Frontend roster spec 5 passed; `eslint` clean; `vue-tsc --noEmit` clean.

---

## Spot-check anchors

1. **Tenancy isolation on the list** — `AgencyCreatorRosterTest` "never lists another agency's relations". Break-revert: drop the `agency_id` scope.
2. **Slim resource: `internal_rating` yes, `internal_notes` no, no signed URLs** — "exposes the slim row shape with internal_rating but NOT internal_notes and no signed URLs". Break-revert: add notes to the row shape.
3. **Each filter narrows + combined filters AND** — the four filter tests + "ANDs combined filters together".
4. **Rows do NOT navigate (D-c5-4)** — frontend "renders a rich, non-navigating row" (name is `<span>`, no `roster-view-*` affordance).
5. **`internal_rating` is read-only** — no write endpoint exists (route file: only `GET creators`); rating rendered as static star icons.
6. **Blacklisted appear in the list (with flag) while excluded from the dashboard KPI** — "INCLUDES blacklisted relations…" here + the existing `DashboardSummaryTest` "excludes blacklisted relations from the roster count".

---

## Out of scope (logged at close)

- **FTS name/bio search** (D-c5-2) → `tech-debt.md` ("Postgres FTS name/bio search…", with the SQLite-divergence note).
- **`internal_notes` display + rating/notes editing** (D-c5-3) → Sprint 6 roster management.
- **Agency-side creator detail + row navigation** (D-c5-4) → Sprint 6. No admin-gate relaxation.
- **Follower/engagement, availability, talent-pool filters** (blocked) → Sprint 5/6.
- **Two unindexed filters** (`relationship_status`, `primary_language`) → `tech-debt.md` ("Unindexed roster filters…"); acceptable at Phase-1 volume behind the agency-scoped set.

## Docs updated

- `docs/tech-debt.md` — three entries: the FTS-search deferral (spec'd-but-unbuilt `tsvector` + SQLite-divergence note), the two unindexed roster filters, and the jsdom heavy-Vuetify-component testing constraint (the `VSelect`/`VDataTableServer` leak + the stub-one-real-table pattern; trigger = Sprint 6's richer matching view).
- `docs/services.md` — no change (per the kickoff).

---

## Commit pair (proposed — not committed until spot-check)

1. **feat(agencies): agency creator roster list endpoint + page** — `AgencyCreatorController::index` + `AgencyCreatorRelationPolicy` + route + provider; `roster.api.ts` + api-client types; `CreatorRosterPage.vue` + nav entry + route; en/pt/it i18n; backend + frontend tests.
2. **docs(tech-debt): log FTS-search deferral + unindexed roster filters + jsdom heavy-component testing constraint** — `tech-debt.md` (three entries) + this review.
