# Sprint 6.6a — Review: Creator discovery read path (the global pool)

**Status:** Closed. Spot-check passed (no PMC) — every anchor holds. The load-bearing one is the **D-7 cross-agency isolation pin**: A's `internal_notes`/`internal_rating` are proven invisible to B across **three** read paths (discovery list, public detail, B's own relation), with the break-revert (un-scope the annotation subquery → B sees A's status → fail), and the subquery filters `where('agency_id', …)` **explicitly** rather than trusting the global scope inside the subquery context — closing the realistic leak vector. The fail-closed gate is a whitelist tested from all four exclusion directions (pending / incomplete / rejected / non-discoverable / soft-deleted); the privacy delta tests each withheld field as an absence; the public detail returns 200 on no-relation but 404 on non-discoverable; the FTS extraction is behavior-preserving (the 167-pass Agencies suite is the safety net). Both divergences are sound — the detail's single scoped `value()` (not an N+1, still agency-scoped) and no `is_discoverable` write path (column is future-proofing, flagged).

**Reviewer:** drafted by Cursor (build pass); spot-checked + closed.

**Reviewed against:** the Sprint-6.6a kickoff (locked decisions D-1…D-10, the honest-deviation triggers, the spot-check anchors) + the two folded-in inventory corrections (E2: `creators.is_active` does not exist; the creator-side surface is NOT spec'd at `:241`) + `PROJECT-WORKFLOW.md` § 5 standards (5.17 defense-in-depth, 5.35 break-revert claim verification, 5.36 asymmetric-coverage acknowledgement) + the Sprint-6.6 read-pass inventory + the precedents the kickoff named (Chunk-1 FTS/filters, the talent-pool `is_member` correlated-subquery annotation, the 2a relation-gated detail, the Pools agency surface).

**First chunk of the discovery sprint** (completes `20-PHASE-1-SPEC.md:215`). 6.6a is **read-only**: browse/search the global pool + view a public profile, with the **cross-agency privacy isolation proven by test (D-7)**. The two-sided request lifecycle (`pending_request`/`declined`, send→accept/decline) is **6.6b**; the creator-side requests surface is **6.6c**.

---

## What shipped

### D-1 / D-2 — the unscoped discovery endpoint + the fail-closed gate

`GET agencies/{agency}/creators/discover` (+ `…/discover/{creator}`) in the Agencies module, in the house `auth:web → tenancy.agency → tenancy` stack. **This is the first agency-facing creator query that is NOT relation-scoped** — it queries the global `creators` pool, not the agency's `agency_creator_relations`.

- **New migration** `2026_06_03_110000_add_is_discoverable_to_creators_table.php` adds `is_discoverable BOOLEAN DEFAULT true` to `creators` (+ a composite `idx_creators_discoverable` on `(application_status, is_discoverable)`). Default-on matches the decision (everyone discoverable now) and future-proofs the GDPR opt-out without a later schema change. The column is added to the `Creator` model's docblock / `$attributes` / `$fillable` / `$casts`, and a `notDiscoverable()` factory state covers the opt-out.
- **The gate is a whitelist (fail-closed):** `application_status = 'approved' AND is_discoverable = true` (+ the implicit soft-delete global scope). Mid-onboarding / pending / rejected / soft-deleted are excluded **by construction**, not by a blacklist. `is_active` is **NOT** used — it doesn't exist on `creators` (the inventory's E2 correction).
- **Authz:** a new `discover` ability on `AgencyCreatorRelationPolicy` (`membership($user) !== null`) — any agency member may discover. A distinct ability (not a reuse of `viewAny`, which is semantically "view relations") documents intent.

### D-3 — extracted the Chunk-1 FTS/filter logic into a shared trait (behavior-preserving)

`apps/api/app/Modules/Agencies/Concerns/FiltersCreatorColumns.php` (new): `applyCreatorFilters` + `applySearchFilter` were **moved out of `AgencyCreatorController`** (where they were private methods) into a trait now `use`d by both the roster controller and the discovery controller. **The FTS driver-aware branch stays single-source** (Postgres `search_vector @@ plainto_tsquery` / SQLite `LIKE ESCAPE` — no copy, no drift). The roster controller's behavior is **unchanged** (its existing tests stayed green — the extraction's safety net). The methods are `@template TModel of Model` so they accept the roster's relation builder _and_ discovery's `Builder<Creator>` (the PHPStan invariance fix). The **availability filter was NOT extracted** — it's relation-coupled (plucks relation `creator_id`s) and has no meaning against the global pool; discovery gets no availability filtering this chunk (deferred, per the kickoff).

### D-4 — the calling-agency-scoped "already-connected" annotation (one query, no N+1)

`AgencyCreatorDiscoveryController` annotates "does the CALLING agency already have a relation, and what status?" via a **correlated subquery** against `agency_creator_relations` (the talent-pool `is_member` precedent), **scoped to the calling agency's `agency_id` only** — never any other agency's row. On the **list** it's a single `addSelect` correlated subquery over the page (no per-row query); on the **detail** it's one extra `value()` query for the single creator. So discovery + roster compose: a creator already on your roster surfaces that status (`roster` / `prospect` / `external`), one with no relation surfaces "not connected".

> **⚠ Privacy (D-4/D-7):** the subquery filters `where('agency_id', $agency->id)` explicitly (not relying on the global tenant scope inside the subquery context). It exposes ONLY the calling agency's own status. Un-scoping this join is the break-revert the D-7 test pins.

### D-5 / D-6 — the public-profile resources (the privacy-critical third shape)

Two **new** resources, distinct from the slim roster row (Chunk 5) and the relation-gated 2a detail — they carry **NONE of the relation block**:

- `CreatorDiscoveryResource` (the list card, D-10): `display_name`, `country_code`, `primary_language`, `categories`, a **single signed avatar** (bounded per page — no portfolio signing on a grid), `is_connected`, `relationship_status`.
- `CreatorPublicProfileResource` (the detail): the public profile — `bio`, `region`, languages, categories, social **accounts**, portfolio (bounded signing, mirroring 2a), completeness — plus `is_connected` / `relationship_status`.

**Both WITHHOLD** (the privacy delta = "profile fields, minus the entire relation block, minus email, minus admin KYC"): `internal_notes`, `internal_rating`, blacklist facts, counters, `last_engaged_at` (all per-agency — leaky in a global view), the **contact email** (a relation privilege per 2a's D-2a-8 — no email pre-connection), and admin KYC PII. Neither resource reuses `CreatorResource->withAdmin()` or the 2a relation-bearing resource (honest-deviation trigger #3 — avoided).

**D-6 — the public detail does NOT 404 on no-relation.** The opposite of the 2a detail's relation-exists 404 gate: an agency views a discovered creator it has _no_ relation with (that's the whole point). It **does** 404 when the creator is non-discoverable / non-approved (the gate), never for no-relation. Route-model binding resolves by ULID (with the soft-delete scope); the gate is re-checked in the controller with `abort(404)`.

### Routing (backend)

The discover routes are registered **before** the parameterized `creators/{creator}` route so `discover` is never captured as a creator ULID.

### Frontend — D-8 / D-9 / D-10

- **D-8 — a separate `/discover` surface (NOT a Roster tab).** New agency routes `discover.list` (`/discover`) + `discover.detail` (`/discover/:ulid`), `layout: 'agency'`, guards `['requireAuth','requireAgencyUser']`; a **Discover** nav item in `AgencyLayout`; the `requireAgencyUser` arch-test set grown to include both routes. The card grid reuses the Chunk-1 filter/FTS UI (debounced `?q=` + country / language / category selects) pointed at the discovery endpoint. Each card: avatar + display name + country/language + category chips + the calling-agency-only connection status; click → the public profile.
- **D-9 — read-only this chunk: no send-request action.** The public profile renders the public shape (bio, country, languages, categories, social accounts, portfolio) read-only. **No "Send connection request" button** (that + pending/connected action states are 6.6b). The ONE connection affordance is a **read**: when already connected, a "View in roster" link to the 2a detail.
- **D-10 — list media boundary.** Cards bind to a single avatar (no portfolio signing on the grid); the detail carries the full portfolio with bounded signing (mirrors 2a).
- **api-client + i18n:** new types (`DiscoveryCreatorListItem`, `DiscoveryListResponse`/`Params`, `CreatorPublicProfile`/`Envelope`, `DiscoveryRelationshipStatus`) + a `discovery.api.ts` wrapper (mirrors `roster.api.ts`); `app.discover.*` i18n keys in **en / pt / it**.

---

## Locked-decision divergences (surface at plan-pause / spot-check)

1. **The public _detail_ annotation is a second `value()` query, not the list's correlated `addSelect`.** The no-N+1 constraint (D-4) targets the _list_ (a page of cards). For a single creator the detail issues one extra scoped query rather than threading the subquery through route-model binding + manual hydration — cleaner, avoids `select('creators.*')` hydration concerns, and is not an N+1 (one row). The annotation is still **calling-agency-scoped** (the D-7 invariant holds on both reads). Flagged as a deliberate, behavior-equivalent divergence.
2. **No `is_discoverable` write path this chunk.** The column + the `notDiscoverable()` factory state exist for the gate's fail-closed coverage; the GDPR opt-out UI/endpoint is out of scope (the kickoff frames the column as future-proofing). Noted so we don't claim a self-serve opt-out that isn't built.

(No honest-deviation trigger fired: the roster tests stayed green after the extraction; the annotation scoped cleanly to the calling agency; neither public resource reused the admin/relation shapes; no send-action or availability filtering was added.)

---

## Coverage (§5.17; break-revert §5.35)

| Area                                                    | Coverage                                                                                                                                                                                                                                                                                                                                                                       |
| ------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| **Cross-agency isolation (D-7 — the load-bearing pin)** | `AgencyCreatorDiscoveryTest` — Agency A sets `internal_notes` + `internal_rating` on a SHARED creator; Agency B sees NONE of A's annotations via discovery list, via the public detail, **and** via B's own 2a-style relation. **Break-revert:** un-scope the annotation subquery (drop the `where('agency_id', …)`) → B's view surfaces A's relation status → the test fails. |
| **Discovery gate (D-2, fail-closed)**                   | An `approved` + `is_discoverable=true` creator **appears**; `pending` / `incomplete` / `rejected` do **NOT** (break-revert: relax the gate → a non-approved creator leaks); `is_discoverable=false` is **excluded** (the future opt-out works); soft-deleted is **excluded** (the implicit global scope).                                                                      |
| **Public resource privacy delta (D-5)**                 | The list card + the public detail carry profile fields and **do NOT carry** `internal_notes` / `internal_rating` / blacklist / counters / `last_engaged_at` / **email** / admin KYC — break-revert each absence.                                                                                                                                                               |
| **Public detail no-404 (D-6)**                          | An agency with **no relation** to a discoverable creator gets **200**, not 404 (break-revert: the 2a relation-exists 404 gate must not be copied here). A non-discoverable / non-approved creator **does** 404.                                                                                                                                                                |
| **Annotation (D-4)**                                    | A creator the calling agency already rosters shows that status (`roster`/`prospect`/`external`); one with no relation shows "not connected"; the list annotation is **one query** (no N+1 across the page — asserted via query count).                                                                                                                                         |
| **FTS/filter extraction (D-3)**                         | The roster controller's existing tests stay **green** (behavior-preserving extraction — the safety net); discovery FTS/filters narrow the pool by name/bio / country / language / category (the shared trait works against `creators`).                                                                                                                                        |
| **Authz / tenancy**                                     | 401 unauthenticated (list + detail); 404 for a non-member (tenancy invisibility); any member (staff included) may discover.                                                                                                                                                                                                                                                    |
| **FE — card grid + threading**                          | `DiscoverPage.spec.ts` — loads scoped to the current agency; renders the connection annotation per card (connected chip vs not-connected); each structured filter re-queries; the search box debounces (300ms) + trims `q`; empty vs no-match states; error path; card-click → `discover.detail`.                                                                              |
| **FE — public profile (read-only)**                     | `DiscoverProfilePage.spec.ts` — loads scoped to agency + ULID; renders name/bio; **NO send-request affordance** (D-9); "View in roster" link **only** when connected → `roster.detail`; not-connected state hides the link; a 404 → the localized not-found message.                                                                                                           |
| **FE — api wrapper**                                    | `discovery.api.spec.ts` — `list()` threads country/language/category/q/page/per_page + drops empty strings; `show()` builds the public-profile URL.                                                                                                                                                                                                                            |
| **FE — arch guard**                                     | `agency-routes-agency-user-guard.spec.ts` grown — `discover.list` + `discover.detail` carry `requireAgencyUser`.                                                                                                                                                                                                                                                               |

**Test runs (local):**

- Backend: `AgencyCreatorDiscoveryTest` — **19 passed (82 assertions)**. The broader `tests/Feature/Modules/Agencies` suite green (167 passed, 1 pre-existing skip) — confirms the trait extraction left the roster behavior unchanged. PHPStan: clean. Pint: clean.
- FE: full Vitest suite **749 passed (86 files)** — incl. the three new discover specs (15) + the grown arch guard. `vue-tsc --noEmit`: clean. ESLint on the discover module: clean.

---

## Spot-check anchors

- **The cross-agency isolation test (D-7 — break-revert, the load-bearing pin):** A's notes/rating invisible to B via discovery + via B's own detail. ✓
- **The fail-closed discovery gate:** non-approved / non-discoverable / soft-deleted excluded (break-revert). ✓
- **The public resource carries no relation block / no email / no admin KYC** (break-revert each absence). ✓
- **The public detail does NOT 404 on no-relation** (D-6). ✓
- **The annotation is calling-agency-scoped + one query** (list, no N+1). ✓
- **The FTS/filter extraction left the roster controller's tests green** (behavior-preserving). ✓
- **`/discover` carries `requireAgencyUser`** (arch-test grown). ✓
- **Read-only — no send action** (that's 6.6b). ✓

---

## Out of scope (logged at close)

- The send-request action + the `pending_request` / `declined` statuses + accept/decline → **6.6b**.
- The creator requests surface → **6.6c** (introduced by the two-sided decision; **NOT** spec'd at `:241` — that's Sprint 8 campaigns).
- A real notification subsystem (the email mailable is 6.6b's concern) → still deferred.
- Availability filtering on discovery (relation-coupled) → omitted; flag if wanted.
- An `is_discoverable` self-serve opt-out write path → future (column is future-proofing only).

---

## Files touched

**Backend**

- `apps/api/database/migrations/2026_06_03_110000_add_is_discoverable_to_creators_table.php` — new (column + index).
- `apps/api/app/Modules/Creators/Models/Creator.php` — `is_discoverable` (docblock / attributes / fillable / casts).
- `apps/api/app/Modules/Creators/Database/Factories/CreatorFactory.php` — `notDiscoverable()` state.
- `apps/api/app/Modules/Agencies/Concerns/FiltersCreatorColumns.php` — new (extracted FTS/filter trait).
- `apps/api/app/Modules/Agencies/Http/Controllers/AgencyCreatorController.php` — `use FiltersCreatorColumns` (methods removed).
- `apps/api/app/Modules/Agencies/Policies/AgencyCreatorRelationPolicy.php` — `discover` ability.
- `apps/api/app/Modules/Agencies/Http/Resources/CreatorDiscoveryResource.php` — new (list card).
- `apps/api/app/Modules/Agencies/Http/Resources/CreatorPublicProfileResource.php` — new (public detail).
- `apps/api/app/Modules/Agencies/Http/Controllers/AgencyCreatorDiscoveryController.php` — new (index + show + annotation).
- `apps/api/app/Modules/Agencies/Routes/api.php` — discover routes (before `creators/{creator}`).
- `apps/api/tests/Feature/Modules/Agencies/AgencyCreatorDiscoveryTest.php` — new.

**Frontend**

- `packages/api-client/src/types/agency.ts` — discovery + public-profile types.
- `apps/main/src/modules/discover/api/discovery.api.ts` — new wrapper.
- `apps/main/src/modules/discover/pages/DiscoverPage.vue` — new (card grid).
- `apps/main/src/modules/discover/pages/DiscoverProfilePage.vue` — new (public profile, read-only).
- `apps/main/src/modules/auth/routes.ts` — `discover.list` / `discover.detail`.
- `apps/main/src/modules/agency/layouts/AgencyLayout.vue` — Discover nav item.
- `apps/main/tests/unit/architecture/agency-routes-agency-user-guard.spec.ts` — grown set.
- `apps/main/src/core/i18n/locales/{en,pt,it}/app.json` — `app.discover.*` keys.
- `apps/main/src/modules/discover/api/discovery.api.spec.ts` — new.
- `apps/main/src/modules/discover/pages/DiscoverPage.spec.ts` — new.
- `apps/main/src/modules/discover/pages/DiscoverProfilePage.spec.ts` — new.

**Docs**

- `docs/tech-debt.md` — the FTS/filter-now-shared follow-up note on the (closed) FTS entry.
- `docs/reviews/sprint-6-6a-review.md` — this review.
