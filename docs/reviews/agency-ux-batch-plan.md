# AH-116 — agency UX batch: PLAN (plan-pause)

> **Status:** **PLAN CLEARED by Pedram, 2026-11-02**, with one change: P1 is overruled and A3 is added (§2, A3). P2 to P8 stand as proposed; the nine i18n keys and the three deletions are approved. Build order: A1, A2, A3, W3, W2, W1, W4, W5, W6, W7; stop before D1 with the evidence named in §9.1.
> **Mode:** A (full loop). Three items touch the API (1, 4 and the archived-brand read, A3), so the whole chunk runs the full loop; every step below is chunk-sized and has its own gate.
> **Inventory:** accepted by Pedram on 2026-11-02 (in the chat; the inventory was delivered as a chat report and is not a file). Its findings are restated where a step depends on them. Rulings Q1 to Q14 are applied in §1.
> **Baseline:** `origin/main` = `3acdbbbe` (clean tree). Production: API at `066ff29c`, main SPA at `1bc398e8` (AH-114 live).
> **Hard constraint:** creator web and native render stay byte-identical (spec 5 content-hash pins, the creator baseline walk). No frozen file is edited by any step.

**PROD-DATA RISK: NONE.** Read-only aggregate (item 1), a read-only filter on an existing list (item 4), and presentation changes. No migration, no data write, no backfill, no one-shot, no queue, no env, no flag, no route-table change. The two API changes are additive (a new key in one `meta`; new optional query parameters on one list). The only stop-gate the chunk touches is **i18n keys** (§3), and Pedram approves that list at this review.

**Evidence labels.** `READ` = file and line read at `3acdbbbe`. `INFERRED` = follows from what was read, not run. `VERIFY AT BUILD` = a fact the step's first action must read before any edit, named so it cannot be skipped.

---

## 0. Summary

1. **Ten steps, in the order Pedram ruled:** three API steps with their Pest tests (A1 item 1, A2 item 4, A3 the archived-brand read), then seven web steps in the order **3, 2, 1, 4, 5, 6, 7** (W3, W2, W1, W4, W5, W6, W7), then a docs step (D1) and the deploy-log entries (D2). One commit per step, tip green, commits stay local until Pedram says go.
2. **Deploy: API first, then main web.** Two `deploy-log.md` entries in that fixed order (§7). The web build treats an absent `meta.status_counts` as null (§2, W1), so a web build that reaches a server before the API deploy degrades to the AH-114 behaviour for item 1. It does **not** degrade safely for item 4 (an old API ignores the pool filters, so the page would show a filter over an unfiltered list); that is why the order is fixed and not advisory.
3. **Nine new i18n keys** (one plural), three deletions, in §3. Everything else reuses existing keys. 24 locales; the flaky ten get real machine translation, never English fallback.
4. **Brand names link on every listed surface** (ruling on P1, 2026-11-02). Ruling Q10 originally limited the link to the brand list because no payload carried a brand's status; Pedram instead had `GET brands/{brand}` return archived brands (A3), so every brand link resolves and the archived-brand follow-up is closed. The brand page shows its status pill, which already exists.
5. **Two inventory findings now have a decided shape:** the Message action reads roster membership from the existing `messageable-creators` list (paged, capped, fails closed, §2 W7); the files strip is built around `ChatPanel`, never inside it (§2 W6).

---

## 1. Rulings applied

| Ruling                                                                                              | Applied as                                                                                                                                                                                                                                                                                                                          |
| --------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Q1** "N total" is the sum of `status_counts`                                                      | The summary reads `sum(status_counts)` and `status_counts.active`. `meta.total` stays for pagination only. Under a brand or date filter the sum is that filter's total; the status filter does not change it (the API ignores it for the counts, A1).                                                                               |
| **Q2** The dashboard reads the list                                                                 | `DashboardPage` issues `campaignsApi.list(agencyId, { per_page: 1 })` and takes `meta.status_counts.active`. `DashboardSummaryController` and `DashboardSummaryTest.php` are not touched (`active_campaigns` stays a pinned null).                                                                                                  |
| **Q3** One empty-state line, reuse the roster's if present                                          | Read: the roster has **no** age-specific line. `app.roster.emptyFiltered` is the generic "No creators match these filters". So there is one new key, `app.discover.emptyFiltered.ageNote`, drawn inside Discover's existing filtered-empty state when an age bound is active. The same gap on the roster is logged, not fixed (§8). |
| **Q4** Delete the three roster affordance keys                                                      | Delete the whole `app.roster.affordances` object (`followers.label`, `engagement.label`, `metrics.tooltip`) from all 24 `app.json` files in W3. Their only reader is the block W3 deletes (`CreatorRosterPage.vue:542-569`, READ; no other reader in `apps/` or `packages/`).                                                       |
| **Q9** Keep `paymentsDue`                                                                           | `dashboard.kpi.paymentsDue` stays in all 24 locales; `KpiStrip` simply does not render the card while `payments_due` is null.                                                                                                                                                                                                       |
| **Q5** Lists and headers → route; campaign work surfaces keep the dialog; board card face untouched | The full per-surface disposition is the table in W5. Applicants (Applications tab, board Applications column) and the campaign Creators tab keep `CreatorProfileDialog`; their D5 specs are not edited.                                                                                                                             |
| **Q6** Relation known → `roster.detail`, else `discover.detail`                                     | Every route-link surface in W5 has a known relation (roster, pool members, a messageable thread), so all link to `roster.detail`. The `discover.detail` branch has **no caller today**; no helper is built for it (point P3).                                                                                                       |
| **Q7** `messageableCreators` lookup now, `can_message` logged                                       | W7 pages `relationshipMessagingApi.messageableCreators` into a set of creator ULIDs. The additive `can_message` field on the application row is logged (§8).                                                                                                                                                                        |
| **Q8** URL state                                                                                    | The pool detail filters and page live in `useListQueryState` (`apps/main/src/composables/useListQueryState.ts`), the same composable Discover uses. Discover's age bounds join its existing URL state.                                                                                                                              |
| **Q10** Brand link only where status is known; archived-brand route logged                          | **Superseded by the P1 ruling (2026-11-02).** A3 makes `brands.show` resolve archived brands in the caller's own agency, so a brand link can no longer 404 and status need not be known at the link. Brand names link on every surface in the W5 table. The archived-brand follow-up is closed, not logged.                         |
| **Q11** Chat strip `visible_to_creators` only; overview strip all, with an internal tag             | The chat strip filters to `visible_to_creators === true` and draws no tag. The overview strip lists all rows and tags `visible_to_creators === false` rows "Internal".                                                                                                                                                              |
| **Q12** Overview panel draws when files exist, even below two glance rows                           | The aside's draw condition becomes `showGlance \|\| files.length > 0`. The "At a glance" block itself keeps its two-row rule.                                                                                                                                                                                                       |
| **Q13** Message on pending rows only                                                                | Draws inside the same `canAct && isPending(row)` branch as Accept and Reject.                                                                                                                                                                                                                                                       |
| **Q14** Hidden for erased                                                                           | No Message action when `creator.is_erased` is true.                                                                                                                                                                                                                                                                                 |
| Dashboard activity n/a, follow-up logged                                                            | No surface built; the follow-up (activity rows carrying entity ids) is logged (§8).                                                                                                                                                                                                                                                 |
| Deploy API first; web treats absent `status_counts` as null                                         | §7, and W1's "absent means no counts" behaviour with its own spec.                                                                                                                                                                                                                                                                  |

---

## 2. The plan: steps in build order

**Ground rules for every step**

- **Files that may not be edited at any step:** the 22 files pinned by `shared-components-frozen.spec.ts` (the 21 creator-shared `.vue` files and `CKpiCard.vue`, READ at `:38-122`; this includes `ChatPanel.vue`, `CEmptyState.vue`, `RichBrief.vue`, `RelationshipThreadView.vue`, the notification files), all of `packages/design-tokens`, `packages/ui`, `apps/main/src/plugins/vuetify.ts`, `App.vue`, `useTheme.ts`, `useThemePreference.ts`, `apps/admin`, every `*.native.spec.ts`. Existing specs may be edited only where §4 names them.
- **New code lives in agency-only files:** `apps/main/src/modules/agency/`, the agency pages themselves, or a campaigns/messaging/roster component already imported only by agency routes. Spec 2 (`agency-theme-isolation`) proves nothing creator-side imports it.
- **`packages/api-client` is shared** with the creator and admin apps. Every edit there is a type-only addition (an optional field on an interface). The wrapper edits (`discovery.api.ts`, `talentPools.api.ts`) are in `apps/main`.
- **No route-table change, no policy change, no validation change, no queue or env change.** Any step that would need one stops and reports (alarm rule).
- **One commit per step; tip green at every commit** (the full gate is §6.1). Commit messages carry the break-revert results and the evidence labels.

### A1 — API: `meta.status_counts` on the agency campaigns list (item 1, API half)

**What.** `CampaignController::index` (`apps/api/app/Modules/Campaigns/Http/Controllers/CampaignController.php:45-76`) adds `meta.status_counts`: an object with exactly five keys (`draft`, `active`, `paused`, `completed`, `cancelled`, the `CampaignStatus` cases), each an integer, zero-filled.

- One grouped query: `COUNT(*)` grouped by `status`, on the same agency-scoped, soft-delete-aware base as the list.
- It reuses the private `applyBrandFilter` (`:338`) and `applyDateFilters` (`:378`) so a brand or date filter narrows the counts exactly as it narrows the list. It does **not** call `applyStatusFilter` (`:358`), so every segment shows what it would contain.
- Results are cast to `int` explicitly (Postgres can return a string for `count`).

**VERIFY AT BUILD (first action).** Read `index()` to mirror its base query and scoping exactly (agency scope, `SoftDeletes`, any search filter). If `index()` has a filter I did not list, it is applied to the counts too, unless it is the status filter. Check `docs/04-API-DESIGN.md` for the campaigns list envelope; if it documents `meta`, add the key there in the same commit.

**API change.** Additive: one new key in `meta`. Existing keys and the `data` shape are unchanged. No migration. The existing `idx_campaigns_agency_brand` serves the `agency_id` prefix.

**Pest tests** (new, in `apps/api/tests/Feature/Modules/Campaigns/CampaignCrudTest.php`, next to the list tests at `:172-226`):

1. `status_counts` has exactly the five keys, zero-filled for an agency with no campaigns, all values integers.
2. Counts are correct for a mix of statuses, and `sum(status_counts)` equals `meta.total` when no filter is applied.
3. **Cross-agency negative:** another agency's campaigns, in every status, are not counted.
4. **Soft-deleted campaigns are not counted** (the same exclusion as the list).
5. The **status filter does not change the counts** (`?status=active` returns the active rows in `data` and the full five-key breakdown in `meta`).
6. The **brand filter and the date filters do narrow the counts** (each one a case; the brand filter case includes an unknown brand ULID giving all zeros, matching the list's behaviour for it).
7. A campaign of an **archived brand** is counted (the list returns it, `CampaignCrudTest.php:212-229`).
8. **One grouped query:** with the query log on, the counts add exactly one `group by` statement against `campaigns` to the request.

**Existing tests:** none edited. `meta.total` assertions (`CampaignCrudTest.php:172-226`) are unaffected (READ).

**Break-reverts** (each run, result in the commit message): (a) drop the agency scope from the counts query, test 3 fails; (b) call `applyStatusFilter` in the counts, test 5 fails; (c) remove the zero-fill, tests 1 and 2 fail; (d) drop `applyBrandFilter`, test 6 fails.

**i18n:** none. **Gate:** full backend Pest serial (`-d memory_limit=2G`), Pint `--all`, PHPStan.

### A2 — API: country and language filters on the pool members list (item 4, API half)

**What.** `TalentPoolMembershipController::index` (`apps/api/app/Modules/TalentPools/Http/Controllers/TalentPoolMembershipController.php:56-93`) uses the `FiltersCreatorColumns` trait (`apps/api/app/Modules/Agencies/Concerns/FiltersCreatorColumns.php`) and calls `applyCreatorFilters` on the members query, before `paginate(25)`.

**VERIFY AT BUILD (first gate, before the edit).**

- The trait's predicates use unqualified column names (`country_code`, `primary_language`, `categories`, `date_of_birth`, `search_vector`, `display_name`, `bio`). The members query is a `BelongsToMany` over `creators` joined to `talent_pool_creators`, whose columns are `id`, `talent_pool_id`, `creator_id`, `added_by_user_id`, `created_at`, `updated_at` (migration, READ). No name collides, so an ambiguous-column error is not expected on Postgres or SQLite. The step proves it by running each predicate (country, language, category, age, `q`) against the relation, not by reading alone.
- The trait takes an Eloquent `Builder`; the relation is unwrapped with `getQuery()`. PHPStan (the trait's `@template`) must stay green with no ignore added.

**API change.** Additive: the endpoint now accepts `country`, `language`, `category`, `age_min`, `age_max` and `q`, because the trait is all-or-nothing and extracting a subset would reopen the drift the trait exists to prevent (its own docblock, and `creator-dob-age-review.md` Q1). Only country and language get UI (W4). The blacklist badge subqueries stay scoped to `agency_id = pool.agency_id`. `per_page` remains unsupported (25 fixed).

**Pest tests** (new, in `apps/api/tests/Feature/Modules/TalentPools/TalentPoolMembershipTest.php`; its helpers live in `TalentPoolCrudTest.php`, so single-file runs fail: run the module folder, `tech-debt.md:3165`):

1. `country` narrows `data` and `meta.total` to matching members.
2. `language` narrows likewise.
3. `country` and `language` together AND.
4. **Cross-pool negative:** a creator matching the filter who is a member of a different pool of the same agency is not returned.
5. **Cross-agency negative:** the filter on another agency's pool still 404s, and a creator who is in both agencies' pools does not leak across.
6. The blacklist badge survives filtering (a hard-blacklisted member keeps `is_blacklisted: true` and `blacklist_type: 'hard'`, and another agency's blacklist of the same creator still does not appear).
7. `age_min`, `category` and `q` each work through the trait (one case each; this pins the accepted all-or-nothing surface so it cannot drift silently).
8. An unknown value (`country=ZZ`) returns an empty list, not an error, and an empty value is ignored (the trait's permissive-read posture).
9. Pagination: with 26 members matching, page 2 holds one row and `meta.total` is 26.

**Existing tests:** none edited.

**Break-reverts:** (a) remove the trait call, tests 1 to 3 and 7 fail; (b) drop the `talent_pool_id` scoping by querying `Creator::query()` instead of the relation, test 4 fails; (c) remove the `agency_id` clause from a badge subquery, test 6 fails (the existing D-4 break-revert, re-run).

**i18n:** none. **Gate:** as A1. **A1, A2 and A3 together are the one API deploy (§7).**

### A3 — API: `GET brands/{brand}` resolves archived brands (closes the archived-brand follow-up; P1 ruling)

**What.** In `apps/api/app/Modules/Brands/Routes/api.php:28` the resource is declared `Route::apiResource('brands', BrandController::class)->withTrashed(['show'])`. Route-model binding then resolves a soft-deleted brand for `show` only. Every other brand route (`index`, `store`, `update`, `destroy`, `restore`, the logo routes) keeps its current binding, so an archived brand still cannot be edited or archived again, and the list is unchanged (`?status=active` default, `archived`, `all` exactly as `BrandController::index` today).

**Tenancy (the part that must not move).** Binding resolves `{brand}` before `tenancy.agency` sets the context (the docblock at `BrandController.php:99-107`), so `BelongsToAgencyScope` cannot filter during binding; the explicit `assertBelongsToAgency` in `show` is what returns 404 for another agency's brand. With `withTrashed` the binding can now also return another agency's _archived_ brand, and the same `assertBelongsToAgency` call must (and will) 404 it. `BrandPolicy::view` is read at build to confirm it does not depend on `deleted_at`.

**VERIFY AT BUILD (first action).** (1) `withTrashed(['show'])` on an `apiResource` binds as described on this Laravel version (checked by the new tests, not by reading). (2) `BrandPolicy::view` and `BrandResource` behave for a trashed model (the resource already emits `status`, `BrandResource.php:58`). (3) No architecture or route-table test pins brand binding; the route names and URIs do not change, so none is expected to move.

**API change.** Additive in behaviour: one route that returned 404 for an archived brand now returns 200 for the caller's own agency. No new field, no migration, no new route. The route-cache note in §7: a binding option is part of the route definition, so a box that runs `route:cache` rebuilds it as part of its standard deploy; whether it does is recorded as read or not read.

**Pest tests** (new, in `apps/api/tests/Feature/Modules/Brands/BrandCrudTest.php`, next to `cross-tenant brand show returns 404` at `:266`; the file's `archiveBrand()` helper at `:422` makes a real archived state, `status` archived and soft-deleted):

1. **Archived brand, own agency: 200**, `data.attributes.status` is `archived` (each of the three roles, as `any role can view a brand` does).
2. **Archived brand, another agency: 404** (the non-fingerprinting contract).
3. **The list still excludes archived brands** by default, and `?status=archived` and `?status=all` still behave as before (the existing tests at `:101-135` and `:395` are re-run, not edited; one new test pins that showing an archived brand did not leak it into the default list).
4. **`update` and `destroy` on an archived brand still 404** (the binding change is `show` only).
5. A genuinely missing ULID still 404s.

**Existing tests:** none edited (READ: no test pins an archived `show` as 404).

**Break-reverts:** (a) remove `withTrashed(['show'])`, test 1 fails; (b) remove `assertBelongsToAgency` from `show`, test 2 fails; (c) widen `withTrashed` to the whole resource, test 4 fails.

**Web half (in W5, not here):** the brand page already draws `AgencyStatusPill kind="brand"` from `brand.attributes.status` (`BrandDetailPage.vue:76-82`, READ), so the Archived pill needs no new string; W5 hides Edit and Archive for an archived brand and adds `BrandDetailPage.spec.ts`.

**i18n:** none. **Gate:** as A1. **The API deploy is A1, A2 and A3 together.**

### W3 — Roster: remove the disabled Followers and Engagement filters (item 3)

**First, because it is a pure deletion** and proves the guards on a small web change before the larger steps.

**Files.**

- `apps/main/src/modules/roster/pages/CreatorRosterPage.vue`: delete the two tooltip-wrapped selects (`:539-583`), the `METRICS_FILTERS_CONNECTED` constant (`:78`), and rewrite the header comment that describes them (`:25-33`).
- `apps/main/src/modules/roster/api/roster.api.ts:35-36`: the comment about the unconnected filters.
- `apps/main/playwright/helpers/selectors.ts:269-272`: remove `rosterFollowersAffordance`, `rosterEngagementAffordance`, `rosterFollowersFilter`, `rosterEngagementFilter`.
- `apps/main/playwright/fixtures/test-helpers.ts:703`: the comment mentioning the disabled affordances.
- **i18n:** delete `app.roster.affordances` (three leaf keys) from all 24 `app.json` files.

**API:** none.

**Specs edited by name.**

- `apps/main/src/modules/roster/pages/CreatorRosterPage.spec.ts`: the test `renders metrics as DISABLED affordances that issue no query (D-4)` (`:472-495`) is **replaced** by `draws no Followers or Engagement filter` (asserts the four ids are absent and that no query param named for them is ever sent). The comment at `:154-156` ("VTooltip intentionally NOT stubbed") goes with it if nothing else needs it (VERIFY AT BUILD).
- Playwright `apps/main/playwright/specs/roster-search-and-affordances.spec.ts`: the disabled-affordance loop and tooltip hover (`:88-101`) are removed; the spec's name and header comment are updated ("and affordances" no longer applies). The search and availability cases stay.

**Break-revert:** restore the Followers select, the replacement spec fails.

**Gate:** the standard web gate (§6.1) plus `i18n-locale-parity.spec.ts` and `verify-locale` 24/24 (a key was removed), and `i18n-used-keys-resolve.spec.ts`.

### W2 — Discover: age filter (item 2)

**Files.**

- `packages/api-client/src/types/agency.ts:603-611`: add `age_min?: number` and `age_max?: number` to `DiscoveryListParams` (types only).
- `apps/main/src/modules/discover/api/discovery.api.ts:30-42`: serialise both (only when set, like the other params). **Type and wrapper land in the same commit**: `api-wrapper-params-serialized.spec.ts` (the AH-096 class-killer) fails if the type gains a field the wrapper does not read as `params.age_min` and `params.age_max`.
- `apps/main/src/modules/discover/pages/DiscoverPage.vue`: two number inputs copied from the roster's (`CreatorRosterPage.vue:479-511`, bounds 0 to 120, the same clamp), placed after Category in the existing filter row; both join `useListQueryState` with the existing `boundedIntParam` helper (the roster uses it, VERIFY AT BUILD for the exact export), the watch at `:196-200` and `hasActiveFilters` (`:136-142`). A reversed range simply returns nothing (the API's posture); no client-side swap.
- When an age bound is active and the result is empty, the existing `discover-empty-filtered` block (`:316-322`) draws one extra line: `app.discover.emptyFiltered.ageNote` (Q3).

**API:** none. Discover already accepts the parameters (`AgencyCreatorDiscoveryController.php:105`, pinned by `AgencyCreatorDiscoveryTest.php:228-241`).

**i18n:** the two labels **reuse** `app.roster.filters.age.min` and `.max` (no new key; point P5). One new key: `app.discover.emptyFiltered.ageNote`.

**Specs edited by name.**

- `apps/main/src/modules/discover/api/discovery.api.spec.ts`: the `discoveryApi.list` block (`:33-66`) gains age cases (set values are sent, unset are omitted, `0` is sent). The existing cases are not edited.
- `apps/main/src/modules/discover/pages/DiscoverPage.spec.ts`: additions only: `re-queries with each structured filter` (`:236`) gets an age case; `resumes the browse context held in the URL…` (`:297`) gets `age_min`/`age_max`; `ignores a hand-edited URL…` (`:312`) gets a junk age; a new test for the age note (shown only when a bound is active and the list is empty). The `the page source holds no font-size or colour literal` test (`:549`) must stay green.

**Break-reverts:** (a) delete the wrapper's `age_min` line, `api-wrapper-params-serialized.spec.ts` and the wrapper spec fail; (b) stop sending the bound from the page, the page spec fails; (c) draw the age note when no bound is active, the page spec fails.

### W1 — Campaign status counts, dashboard KPI, Payments card (item 1, web half)

**Files.**

- `packages/api-client/src/types/campaign.ts:155-163`: `CampaignListResponse['meta']` gains `status_counts?: Record<CampaignStatus, number>` (**optional**: the web build must run against an API that does not send it).
- `apps/main/src/modules/campaigns/pages/CampaignListPage.vue`:
  - The status chip-group (`statusFilterItems` `:108-114`, chips `:455-468`) shows the count next to each label. The "All" segment shows the sum.
  - The `summary` computed (`:203-207`) becomes "N total, M active" using `app.campaigns.summaryActive`, N = `sum(status_counts)`, M = `status_counts.active` (Q1). **Absent `status_counts`:** no counts on the segments and the summary falls back to today's `app.campaigns.summary` from `meta.total`.
  - The HTML comment at `:410` ("no per-status counts") is removed.
- `apps/main/src/modules/agency/theme/agency.css`: a count style for the segments, **scoped** under the existing `:is(.v-theme--agency-dark, .v-theme--agency-light)` form. `agency-theme-scoping.spec.ts` must stay green.
- `apps/main/src/modules/dashboard/pages/DashboardPage.vue:34-49`: one extra call, `campaignsApi.list(agencyId, { per_page: 1 })`, run alongside the summary call; its failure does not fail the summary (the Active campaigns card shows the muted placeholder, the other cards still render).
- `apps/main/src/modules/dashboard/components/KpiStrip.vue:35-62`: Active campaigns reads the list's `status_counts.active` (a new prop; null when absent), and the **Payments due card is not drawn while `summary.payments_due` is null** (Q9: the key stays, the card returns when the API starts sending a number).
- `apps/main/tools/walk/l4-fixtures.mjs:586-587`: the dashboard fixture for the dev walk (dev tool only).

**API:** none in this step (A1 is the API half).

**i18n:** one new key, `app.campaigns.summaryActive` (plural). Segment counts and the KPI need no key (the number is rendered beside the existing label; the segment's accessible name is the label and the number read together).

**Specs edited by name.**

- `apps/main/src/modules/campaigns/pages/CampaignListPage.spec.ts`, inside `describe('agency restyle (AH-114, S3.1)')`:
  - `reads the header summary off the list total, and not before the first response` (`:404`) → renamed `reads the header summary off the status counts, and not before the first response`; expects "24 total, 5 active" style text from a fixture with `status_counts`.
  - `uses the singular for one campaign` (`:411`) → edited for the plural key (`1 campaign, 1 active`).
  - `draws no summary when the load failed` (`:417`) → unchanged in intent; the fixture helper gains `status_counts`.
  - `draws the status segments without counts or check marks` (`:499`) → **inverted** to `draws the status segments with their counts and no check marks` (the old `not.toMatch(/\d/)` goes).
  - New tests: counts absent → segments plain and the summary falls back to `meta.total`; the status tabs keep their counts while a status tab is selected; the `mountList` helper (`:~100-140`) gains `status_counts`.
- `apps/main/src/modules/dashboard/pages/DashboardPage.spec.ts`:
  - `renders the real KPI values and muted placeholders once loaded` (`:84`) → Active campaigns from the list; Payments due no longer drawn when null.
  - `renders the KPI cards in the locked order (D-c1-4)` (`:104`) → the locked order is now three cards when `payments_due` is null, four when it is a number (both cases asserted).
  - The module mock gains `campaignsApi.list`; the existing `shows KPI skeletons…` (`:74`) and `surfaces a localized error alert…` (`:126`) are kept, with the summary call and the list call both mocked.
  - New: the list call failing leaves the other cards intact and shows the placeholder on Active campaigns.
- `apps/api` `DashboardSummaryTest.php`: **not edited** (Q2).

**Break-reverts:** (a) sum from `meta.total` instead of the counts, the "N total" test fails under a status tab; (b) read `status_counts` without a null guard, the absent-counts test fails; (c) draw Payments due unconditionally, the KPI order test fails.

### W4 — Talent pool detail: country and language filters (item 4, web half)

**Files.**

- `apps/main/src/modules/pools/api/talentPools.api.ts:90-103`: promote the inline `{ page, per_page }` parameter of `members()` to a named exported `TalentPoolMembersParams` (so the AH-096 guard now covers it), adding `country?` and `language?`, serialised only when set. (`TalentPoolMembersParams` lives in `packages/api-client/src/types/` if the other pool types do; VERIFY AT BUILD where `TalentPool*` types are defined and put it beside them. Types only.)
- `apps/main/src/modules/pools/pages/PoolDetailPage.vue`: two filter pills (country, language) above the members list, using the same pill and option sources as the roster (`COUNTRY_OPTIONS`, `worldLanguageOptions`). Page, country and language move into `useListQueryState` (Q8), so a member's profile can be left and re-entered on the same view. A filter change resets to page 1. Filtered-empty draws the existing empty-state block with the roster's `app.roster.emptyFiltered.*` copy (no new key; P5).
- `agency.css`: nothing, unless the pills need a margin (VERIFY AT BUILD; if so, scoped, and `agency-theme-scoping` stays green).

**API:** none here (A2 is the API half). **Deploy-order note:** this step's behaviour depends on A2 being live.

**i18n:** none new. Reused: `app.roster.filters.country`, `app.roster.filters.language`, `app.roster.emptyFiltered.heading` and `.body`.

**Specs edited by name.**

- `apps/main/src/modules/pools/pages/PoolDetailPage.spec.ts`: `loads the pool + members scoped to agency and route ulid` (`:177`) pins `members` called with `{ page: 1, per_page: 25 }` (`:182-185`); it is **edited** to the new exact call shape (unset filters omitted, so the shape stays `{ page: 1, per_page: 25 }` if the wrapper omits them; the edit is to the page's call, which now passes the params object from URL state, VERIFY AT BUILD). New tests: a country filter re-queries with `country` and resets to page 1; language likewise; both together; the URL carries the filters and a hand-edited junk value is ignored; the filtered-empty state draws.
- A new `apps/main/src/modules/pools/api/talentPools.api.spec.ts` (none exists today): the `members()` query builder, mirroring `discovery.api.spec.ts`.
- `api-wrapper-params-serialized.spec.ts` picks up `TalentPoolMembersParams` automatically; it is the guard, not edited.

**Break-reverts:** (a) drop `country` from the wrapper, the guard and the wrapper spec fail; (b) keep the page in a local ref instead of the URL, the URL spec fails.

### W5 — The clickable sweep (item 5)

**Rule.** A name is a link only on **lists and headers**; **campaign work surfaces keep the dialog**; the **board card face is untouched**. Route links are Vuetify `router-link`/`v-btn :to` with named routes (no direct router import, `no-direct-router-imports` stays green), carry one shared agency-scoped class `.agency-entity-link` in `agency.css` (no underline, inherits colour, underline and a visible focus ring on hover and focus; selector under the scope form, `agency-theme-scoping` green), and **never link an erased creator** (`is_erased` renders the plain `creatorLabel`). Names and avatars still go through `core/creators/creatorLabel.ts` (`creator-label-single-reader.spec.ts` stays green).

**The full surface list, every disposition decided.**

| Surface                                                                                                          | Where (READ at `3acdbbbe`)                                                                                                                                                                                                                | Today                                            | Disposition                                                                                                                                                                                                    |
| ---------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Roster table, creator name**                                                                                   | `roster/pages/CreatorRosterPage.vue:638-641`                                                                                                                                                                                              | plain span; the row click navigates              | **LINK** to `roster.detail`. `@click.stop` on the link so the row handler (`:402-411`) does not also push. Row click stays.                                                                                    |
| **Pool detail members, avatar and name**                                                                         | `pools/pages/PoolDetailPage.vue:218-232`                                                                                                                                                                                                  | plain, no navigation at all                      | **LINK** both to `roster.detail` (pool membership requires an existing relation of any status, `TalentPoolMembershipController.php:101`, so the route resolves). No link on an erased member.                  |
| **Relationship thread header, avatar and title**                                                                 | `agency/components/AgencyRelationshipThreadView.vue:424-431`                                                                                                                                                                              | plain                                            | **LINK** to `roster.detail` (a messageable thread implies the roster relation). VERIFY AT BUILD the view receives the creator ULID; if not, `AgencyRelationshipThreadPage` passes it down (agency-only files). |
| **Campaign list, campaign title**                                                                                | `campaigns/pages/CampaignListPage.vue:512-524`                                                                                                                                                                                            | plain; only the eye icon links                   | **LINK** to `campaigns.detail`. The eye icon (`:570-578`) stays.                                                                                                                                               |
| **Board drawer, campaign title and brand in the sub-line**                                                       | `boards/components/BoardCardDrawer.vue:199-203, 458`                                                                                                                                                                                      | one plain string `Campaign · Brand`              | **LINK** the campaign title to `campaigns.detail` and the brand name to `brands.detail`. The string becomes two parts, so the computed is split.                                                               |
| **Brand list, brand name**                                                                                       | `brands/pages/BrandListPage.vue:293-307`                                                                                                                                                                                                  | plain; only the eye icon links                   | **LINK** to `brands.detail`, for active and archived rows alike (A3 makes the archived page resolve). The eye icon stays.                                                                                      |
| **Campaign list, brand**                                                                                         | `CampaignListPage.vue:526-533`                                                                                                                                                                                                            | plain with `AgencyBrandMark`                     | **LINK** the name to `brands.detail` (the mark stays beside it).                                                                                                                                               |
| **Campaign detail, brand** (overview field and header subtitle)                                                  | `campaigns/pages/CampaignDetailPage.vue:698, 507-517`                                                                                                                                                                                     | plain strings                                    | **LINK** both to `brands.detail`. `AgencyPageHeader` (agency-only) gains a `subtitle` slot so the subtitle can hold a link; the string prop stays for the pages that use it.                                   |
| **Pool list, brand cell**                                                                                        | `pools/pages/PoolListPage.vue:257-262`                                                                                                                                                                                                    | plain with `AgencyBrandMark`                     | **LINK** the name to `brands.detail` when the pool is brand-scoped; "Agency-wide" stays plain text.                                                                                                            |
| **Pool detail header line, brand**                                                                               | `PoolDetailPage.vue:84-88`                                                                                                                                                                                                                | plain string                                     | **LINK** the brand part through the new subtitle slot when the pool is brand-scoped; the member count stays text.                                                                                              |
| Campaign **Creators tab**                                                                                        | `CampaignDetailPage.vue:840-862`                                                                                                                                                                                                          | row click opens `CreatorProfileDialog`           | **DIALOG KEPT, untouched** (work surface, Q5). Spec at `CampaignDetailPage.spec.ts:629-659` not edited.                                                                                                        |
| Campaign **Applications tab**                                                                                    | `campaigns/components/ApplicationsTab.vue:216-246`                                                                                                                                                                                        | identity block opens the dialog                  | **DIALOG KEPT, untouched.** `ApplicationsTab.spec.ts:289-322` not edited.                                                                                                                                      |
| **Board Applications column**                                                                                    | `boards/components/BoardApplicationsColumn.vue:206-218`                                                                                                                                                                                   | identity block opens the dialog                  | **DIALOG KEPT, untouched.** `BoardApplicationsColumn.spec.ts:288-322` not edited.                                                                                                                              |
| **Board card face** (avatar and name)                                                                            | `boards/components/BoardCard.vue:104-122`                                                                                                                                                                                                 | the card opens the drawer; the card is draggable | **UNTOUCHED** (ruled). A link inside a sortable card is the reason.                                                                                                                                            |
| Board drawer header, avatar and name                                                                             | `BoardCardDrawer.vue:422-452`                                                                                                                                                                                                             | plain; the drawer has its own Profile tab        | **UNTOUCHED** (a work surface that already carries the profile; reading of Q5).                                                                                                                                |
| Drafts tab, creator name                                                                                         | `campaigns/components/DraftsTab.vue:205`                                                                                                                                                                                                  | plain text in a row that opens the review drawer | **UNTOUCHED** (a work surface with nothing to "keep"; my reading of Q5, point P2).                                                                                                                             |
| Campaign Messages rollup, creator name                                                                           | `messaging/components/CampaignMessagesPanel.vue:121-123`                                                                                                                                                                                  | name inside a row that opens the chat            | **EXCLUDED**: the row is the interactive element; a link inside it is nested interactive content.                                                                                                              |
| Relationship inbox rows                                                                                          | `agency/components/AgencyRelationshipInbox.vue` (`router-link` row)                                                                                                                                                                       | the whole row is a link to the thread            | **EXCLUDED**: a nested anchor is invalid markup.                                                                                                                                                               |
| Discover card                                                                                                    | `agency/components/AgencyCreatorCard.vue`                                                                                                                                                                                                 | whole card `role="link"`, opens detail           | **ALREADY DONE**, unchanged.                                                                                                                                                                                   |
| Pickers and dialogs (invite, add creators, add to pool, accept and reject dialogs, the three drawers' subtitles) | `InviteCreatorsDialog.vue:421`, `AddCreatorsToPoolDialog.vue:359`, `AcceptApplicationDialog.vue:59`, `RejectApplicationDialog.vue:61`, `ReviewDraftDrawer.vue:104`, `ViewPostedContentDrawer.vue:96`, `ResolveVerificationDrawer.vue:152` | selection and confirmation UIs                   | **EXCLUDED by design**: navigating away mid-task defeats the surface.                                                                                                                                          |
| Notifications (bell, center, page)                                                                               | frozen shared files                                                                                                                                                                                                                       | whole-row links                                  | **EXCLUDED**: frozen (spec 5) and already links.                                                                                                                                                               |
| **Dashboard activity**                                                                                           | `dashboard/components/ActivityFeed.vue`; `DashboardActivityFeed.php:73-75`                                                                                                                                                                | actor and count only, no entity ids              | **N/A**: needs an API change (ruled out). Follow-up logged (§8).                                                                                                                                               |

**Files touched.** `CreatorRosterPage.vue`, `PoolDetailPage.vue`, `AgencyRelationshipThreadView.vue` (and `AgencyRelationshipThreadPage.vue` if the ULID has to be passed down), `CampaignListPage.vue`, `BoardCardDrawer.vue`, `BrandListPage.vue`, `agency.css`.

**API:** none. **Creator exposure:** none beyond `agency.css` (scoped, inert in the creator bundle, the AH-114 accepted shape).

**i18n:** none (link text is the visible name; no extra accessible-name string needed).

**Specs edited by name.**

- `CreatorRosterPage.spec.ts`: `loads scoped to the current agency and renders a rich, non-navigating row` (`:194`): the pin that the name is a plain `SPAN` (`:234-238`, with its comment about no per-cell link) is **edited** to expect the link, and the title is renamed (the row is no longer "non-navigating"); the `roster-view` absence assertion stays. A new test: clicking the name link does not also fire the row navigation (one push, not two); an erased creator's name is not a link.
- `PoolDetailPage.spec.ts`: `renders the member roster` (`:189`) gains the avatar and name link assertions; new: an erased member is not a link.
- `CampaignListPage.spec.ts`: new tests for the title link and its target, and for the brand link (name only, `brands.detail`).
- `BrandListPage.spec.ts`: new tests: an active brand's name links, and an archived row's name links too. A new `BrandDetailPage.spec.ts` (none exists): an archived brand loads and shows the Archived pill, and the Edit and Archive buttons are not drawn for it (Edit would 404, A3 leaves `update` on the default binding). `PoolListPage.spec.ts` and `PoolDetailPage.spec.ts`: the brand link for a brand-scoped pool and plain text for an agency-wide one.
- `BoardCardDrawer.spec.ts`: new test: the campaign title links to `campaigns.detail` and the brand name to `brands.detail`. (The AgencyRelationshipThreadView spec, if one exists, gets the header link test; VERIFY AT BUILD.)
- **Not edited** (the dialog surfaces): `CampaignDetailPage.spec.ts:629-659`, `ApplicationsTab.spec.ts:289-322`, `BoardApplicationsColumn.spec.ts:288-322`. If any of them goes red in this step, the step stops: that would mean a dialog surface changed.

**Break-reverts:** (a) remove `@click.stop` from the roster link, the single-push test fails; (b) link an erased member, the erased tests fail; (c) link an agency-wide pool's "brand", the pool tests fail; (d) draw Edit on an archived brand, the brand detail spec fails.

### W6 — Campaign files strip (item 6)

**Files.**

- New agency-only component `apps/main/src/modules/campaigns/components/CampaignFilesStrip.vue` with a `mode` of `overview` or `chat`: a compact row of file chips (name, size), each a download link on the row's `download_url` (READ in `CampaignAttachmentResource.php:48`, fields incl. `visible_to_creators`), opening in a new tab with `rel="noopener noreferrer"`. A cap of 4 chips with "+N more" (overview mode links "View all" to the existing Attachments tab; chat mode has no "View all").
- New composable `apps/main/src/modules/campaigns/composables/useCampaignFiles.ts` over the existing `campaignsApi.listAttachments` (`campaigns.api.ts:141`; unpaginated, `view`-gated). **No new endpoint, no upload path, no mutation.**
- `CampaignDetailPage.vue`: the Overview aside (drawn at `:777` only when `showGlance`, `:570`) draws the strip **under "At a glance"** and the aside's condition becomes `showGlance || files.length > 0` (Q12); the layout class that keys on `showGlance` follows the same condition. The strip is a sibling of `[data-test="overview-glance"]`, not inside it, so the glance block keeps its own two-row rule. **Overview mode lists all rows and tags `visible_to_creators === false` rows "Internal"** (Q11).
- `messaging/components/ChatDialog.vue` (agency-only; its only mount is `CampaignMessagesPanel.vue:140`): a named slot `pinned` between the title divider and the scrolling `v-card-text`. The dialog is `scrollable`, so `v-card-text` is the scroller and a sibling above it stays pinned. `CampaignMessagesPanel.vue` fills the slot with the strip in **chat mode** (visible-to-creators rows only, no tag). `ChatPanel.vue` is **not touched** (frozen, no slots).
- `boards/components/BoardCardDrawer.vue`: the same chat-mode strip above `ChatPanel` in the Messages tab (`:390-398`), fetched from the card's campaign (the drawer already has `agencyId` and `campaignId`).
- `agency.css`: styles for the strip and chips, scoped.

**VERIFY AT BUILD.** (1) Whether the Overview tab remounts on tab change, to settle whether the strip refetches after an attach or detach on the Attachments tab (if the tab is kept alive, the strip refetches on tab activation). (2) That `CampaignMessagesPanel` has the agency and campaign ids to pass to the composable. (3) The chip layout in the 360 px right panel.

**API:** none. **Creator exposure:** none. `CreatorAssignmentDetailPage.vue:650` (the creator's own `ChatPanel` mount) is not touched; the chat strip exists only in agency-side mounts.

**i18n (5 new keys, under `app.campaigns.detail.files`):** `title`, `viewAll`, `more`, `internal`, `download`. The chat strip reuses `title`.

**Specs edited by name.**

- `apps/main/src/modules/campaigns/pages/CampaignDetailPage.spec.ts`:
  - The module mock at `:33` (`vi.mock('../api/campaigns.api', …)`) gains `listAttachments`, and the `open` helper (`:~1040-1075`) defaults it to an empty list, so every existing test keeps its current expectation.
  - `describe('At a glance (drawn only with two rows)')` (`:1188-1243`): the existing tests are **not edited** (they assert the `overview-glance` element, which stays two-row gated), but each is re-run with an empty file list as the default; **new tests** in a sibling `describe('Campaign files strip')`: strip drawn under the glance block with files; strip drawn alone below two glance rows (Q12); "Internal" tag on a `visible_to_creators: false` row and not on a visible one; "+N more" over four; nothing drawn (and no aside) with no files and below two glance rows; the load failing draws nothing and does not break the page.
- `apps/main/src/modules/boards/components/BoardCardDrawer.spec.ts`: `mounts the Messages tab (default) with the per-assignment chat transport` (`:340`) gets the `listAttachments` mock added to its setup (not its assertions); new: the chat strip lists only visible-to-creators rows, carries no tag, and is absent for a card with no assignment (`:352`'s null-transport case).
- New specs for `ChatDialog` (pinned slot renders outside the scroller) and `CampaignMessagesPanel` (fills the slot; none exist today) and `CampaignFilesStrip` (cap, tag, mode behaviour).

**Break-reverts:** (a) draw internal rows in chat mode, the chat-strip spec fails; (b) put the strip inside `overview-glance`, the "alone below two rows" test fails; (c) drop the `files.length > 0` term from the aside condition, the same test fails.

### W7 — Applications: Message action (item 7)

**Files.**

- New agency-only composable `apps/main/src/modules/agency/composables/useMessageableCreatorIds.ts`: pages `relationshipMessagingApi.messageableCreators(agencyId, { page, perPage: 100 })` (`relationshipMessaging.api.ts:94-110`; the API caps `per_page` at 100) until `meta.last_page`, **capped at 10 pages (1000 creators)**, into a `Set` of creator ULIDs. It is fetched **lazily, only when the tab or column has at least one pending row and the viewer can act**, once per mount. **It fails closed:** a load error, or a roster larger than the cap with the creator not found in the loaded pages, hides Message (no error shown; the action is an extra).
  - The set is exactly the messaging gate (`MessageableContactsFinder`: roster, not blacklisted, approved, not leaving), so a Message shown here cannot 403 on the first send.
- `apps/main/src/modules/campaigns/components/ApplicationsTab.vue:269-293` and `apps/main/src/modules/boards/components/BoardApplicationsColumn.vue:245-266`: a "Message" action beside Accept and Reject, **inside the same `canAct && isPending(row)` branch** (Q13), shown only when the creator's ULID is in the set and `creator.is_erased` is not true (Q14). It navigates to `messages.thread` with `params.creatorUlid` and `query.name`, exactly as `roster/pages/CreatorDetailPage.vue:365-372` does. The thread is provisioned only on first send (no write on click).
- The action's click must not open the profile dialog (the D5 pins).
- `agency.css`: nothing expected.

**API:** none (no new endpoint, no field). The `can_message` field is the logged follow-up that would replace the paged lookup with one flag on the row.

**VERIFY AT BUILD.** (1) The list endpoint's `search` parameter is name-based, so it is **not** used to look up one applicant (collisions, erased names). (2) Whether a viewer with `canAct` but without the messaging ability exists; the roster detail's `canMessage` (`CreatorDetailPage.vue:94`) checks `relationship_status === 'roster'` and no role, so none is expected. (3) The board column's mount is per campaign, so the lookup is once per board view.

**i18n (2 new keys, under `app.campaigns.applications.message`):** `action` ("Message") and `label` ("Message {name}", the accessible name). The board column reuses both.

**Specs edited by name.**

- `ApplicationsTab.spec.ts`: setup gains a `relationshipMessagingApi.messageableCreators` mock (default: an empty page); `offers accept + reject on a PENDING row only` (`:177`) stays as is. New tests: Message shown for a pending row whose creator is in the set; hidden for a pending row whose creator is not; hidden for accepted and rejected rows (Q13); hidden for an erased applicant (Q14); hidden for a viewer without `canAct`; the lookup is not called when there is no pending row; a failing lookup hides Message and leaves Accept and Reject; multi-page set (page 2 holds the creator); the cap case (creator beyond 10 pages is hidden); the click navigates to `messages.thread` with the creator ULID and does not open the profile dialog.
- `BoardApplicationsColumn.spec.ts`: the same setup edit and the same set of new tests; `D5 — clicking Accept or Reject does NOT also open the profile dialog` (`:303`) gets a sibling for Message.
- **Not edited:** `hides both answers from someone without the invite ability` (`:200`) and the accept and reject dialog tests.

**Break-reverts:** (a) show Message on non-pending rows, the Q13 test fails; (b) drop the erased guard, the Q14 test fails; (c) show Message on a failed lookup (fail open), the fail-closed test fails; (d) remove the set check, the not-in-set test fails.

### D1 — Docs pass

- The AH-116 entry in `docs/reviews/adhoc-changes-log.md` and a review doc `docs/reviews/agency-ux-batch-review.md` (the deviation record: the P1 overrule and A3; the Q6 branch with no caller; every measured claim labelled).
- `docs/tech-debt.md`: the logged items in §8, each with a trigger and owner.
- `docs/reviews/agency-restyle-plan.md` §7: strike "Campaign status counts" and "Discover age filter" as done.
- `docs/04-API-DESIGN.md` if it documents the two endpoints (A1 and A2 will have checked).
- Prettier on every `.md` and source file touched; links resolve.

### D2 — Deploy-log entries

Two `PENDING` entries, API first, in the file's template shape (§7). Nothing is deployed by this chunk; the colleague deploys from the mirror.

---

## 3. i18n keys, for approval

**Rule of the chunk (`WORKING-PROCESS.md` §4, ruling 6, ruling 16):** every new `en` string is regenerated across all 24 locales; the flaky ten (`bg`, `el`, `et`, `fi`, `ga`, `hu`, `lt`, `lv`, `mt`, `ro`) get **real machine translation at merge, never English fallback**; parity green; the vocabulary grep passes; the new strings carry no em dash.

**New keys: 9** (216 locale strings), in the order the steps add them.

| #   | Key                                         | Step | English (proposed)                                                              | Notes                                                         |
| --- | ------------------------------------------- | ---- | ------------------------------------------------------------------------------- | ------------------------------------------------------------- |
| 1   | `app.discover.emptyFiltered.ageNote`        | W2   | Creators who haven't entered a birthday aren't included when you filter by age. | Shown only with an age bound active and an empty result (Q3). |
| 2   | `app.campaigns.summaryActive`               | W1   | `{count} campaign, {active} active \| {count} campaigns, {active} active`       | **Plural**, two named parameters.                             |
| 3   | `app.campaigns.detail.files.title`          | W6   | Files                                                                           | Overview strip and the chat strip.                            |
| 4   | `app.campaigns.detail.files.viewAll`        | W6   | View all files                                                                  | Overview strip only; goes to the Attachments tab.             |
| 5   | `app.campaigns.detail.files.more`           | W6   | +{count} more                                                                   | Over the 4-chip cap.                                          |
| 6   | `app.campaigns.detail.files.internal`       | W6   | Internal                                                                        | The tag on a `visible_to_creators: false` row (Q11).          |
| 7   | `app.campaigns.detail.files.download`       | W6   | Download {name}                                                                 | Accessible name of a chip.                                    |
| 8   | `app.campaigns.applications.message.action` | W7   | Message                                                                         | The visible label.                                            |
| 9   | `app.campaigns.applications.message.label`  | W7   | Message {name}                                                                  | Accessible name.                                              |

**Deleted keys: 3** (72 locale strings), W3: `app.roster.affordances.followers.label`, `app.roster.affordances.engagement.label`, `app.roster.affordances.metrics.tooltip` (Q4).

**Reused, not new** (point P5 if Pedram prefers own namespaces, which would add 5 keys):

- `app.roster.filters.age.min` and `.max`: Discover's two age inputs (W2).
- `app.roster.filters.country` and `.language`: the pool detail pills (W4).
- `app.roster.emptyFiltered.heading` and `.body`: the pool detail filtered-empty state (W4).
- `app.campaigns.summary`: kept as the fallback when `status_counts` is absent (W1).
- `dashboard.kpi.paymentsDue`: kept (Q9).

---

## 4. Specs edited by name (consolidated)

**Existing specs edited** (anything not listed here is not edited):

| Spec                                                        | Test or block edited                                                                                                                                                                               | Step | Kind                                          |
| ----------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---- | --------------------------------------------- |
| `modules/roster/pages/CreatorRosterPage.spec.ts`            | `renders metrics as DISABLED affordances that issue no query (D-4)` (`:472-495`) and its comment `:154-156`                                                                                        | W3   | **Deliberate replacement** (absence pin).     |
| `modules/discover/api/discovery.api.spec.ts`                | `discoveryApi.list` block (`:33-66`)                                                                                                                                                               | W2   | Additions.                                    |
| `modules/discover/pages/DiscoverPage.spec.ts`               | `re-queries with each structured filter` (`:236`), URL resume (`:297`), hand-edited URL (`:312`)                                                                                                   | W2   | Additions.                                    |
| `modules/campaigns/pages/CampaignListPage.spec.ts`          | `reads the header summary off the list total…` (`:404`), `uses the singular for one campaign` (`:411`), `draws the status segments without counts or check marks` (`:499`), the `mountList` helper | W1   | **Edits and one inversion.**                  |
| `modules/dashboard/pages/DashboardPage.spec.ts`             | `renders the real KPI values and muted placeholders once loaded` (`:84`), `renders the KPI cards in the locked order (D-c1-4)` (`:104`), the module mock                                           | W1   | **Edits.**                                    |
| `modules/pools/pages/PoolDetailPage.spec.ts`                | `loads the pool + members scoped to agency and route ulid` (`:177`, the `members` call pin `:182-185`)                                                                                             | W4   | **Edit** (call shape).                        |
| `modules/roster/pages/CreatorRosterPage.spec.ts`            | `loads scoped to the current agency and renders a rich, non-navigating row` (`:194`, the `SPAN` pin `:234-238`)                                                                                    | W5   | **Edit** (SPAN becomes a link, test renamed). |
| `modules/pools/pages/PoolDetailPage.spec.ts`                | `renders the member roster` (`:189`)                                                                                                                                                               | W5   | Additions.                                    |
| `modules/campaigns/pages/CampaignDetailPage.spec.ts`        | the `vi.mock('../api/campaigns.api')` factory (`:33`) and the `open` helper; glance tests re-run, not edited                                                                                       | W6   | Setup edit and additions.                     |
| `modules/boards/components/BoardCardDrawer.spec.ts`         | setup of `mounts the Messages tab (default)…` (`:340`)                                                                                                                                             | W6   | Setup edit and additions.                     |
| `modules/campaigns/components/ApplicationsTab.spec.ts`      | module mock (adds `messageableCreators`)                                                                                                                                                           | W7   | Setup edit and additions.                     |
| `modules/boards/components/BoardApplicationsColumn.spec.ts` | module mock (adds `messageableCreators`)                                                                                                                                                           | W7   | Setup edit and additions.                     |

**Existing specs deliberately not edited, and why they matter:** `ApplicationsTab.spec.ts:289-322`, `BoardApplicationsColumn.spec.ts:288-322`, `CampaignDetailPage.spec.ts:629-659` (the dialog surfaces stay); `api-wrapper-params-serialized.spec.ts`, `creator-label-single-reader.spec.ts`, `i18n-used-keys-resolve.spec.ts`, `i18n-locale-parity.spec.ts`, `agency-theme-scoping.spec.ts`, `agency-theme-isolation.spec.ts`, `creator-no-agency-theme.spec.ts`, `shared-components-frozen.spec.ts`, `shared-tokens-frozen.spec.ts` (the guards); `DashboardSummaryTest.php` and `AgencyCreatorDiscoveryTest.php` (the API pins that must not move).

**New specs and tests:** Pest in `CampaignCrudTest.php` (A1, 8 tests) and `TalentPoolMembershipTest.php` (A2, 9 tests); `talentPools.api.spec.ts`; `ChatDialog`, `CampaignMessagesPanel`, `CampaignFilesStrip` specs; the additions listed per step.

---

## 5. Playwright specs touched

The suite runs against the real API with the dev stack down and the isolated E2E DB (`WORKING-PROCESS.md` §4). Each touched spec is run at its step; **the full suite runs once before the push** of the web series.

| Spec (`apps/main/playwright/specs/`)                                        | Step       | What changes or what is verified                                                                                                                                                        |
| --------------------------------------------------------------------------- | ---------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `roster-search-and-affordances.spec.ts`                                     | W3         | **Edited:** the disabled-affordance loop and tooltip hover (`:88-101`) removed; header comment updated. Search and availability cases verified green.                                   |
| `helpers/selectors.ts`, `fixtures/test-helpers.ts`                          | W3         | **Edited:** four ids removed (`:269-272`), one comment updated (`:703`).                                                                                                                |
| `creator-detail.spec.ts`                                                    | W5         | **Verified, expected no edit:** `table.getByText('Ada Lovelace').click()` (`:72`) now hits a link whose navigation matches the row's; asserts it lands on the detail page once.         |
| `brands.spec.ts`                                                            | A3, W5     | **Verified:** `/brands` list (`:122`, `:189`) with the name now a link; the detail page (`:198`) unchanged for an active brand.                                                         |
| `talent-pools.spec.ts`                                                      | W4, W5     | **Verified:** the pool detail flow (`:113-139`) under URL-state filters and the member name link; `table.getByText('Acme Q3')` on the list (`:75`) unchanged (brand stays plain there). |
| `jobs-board-full-lifecycle.spec.ts`                                         | W1, W5, W6 | **Verified:** `/campaigns` list (`:119`) with counts and the title link; the detail pages (`:170`, `:256`) with the files aside.                                                        |
| `notification-deep-link.spec.ts`                                            | W1, W5     | **Verified:** loads `/campaigns` (`:85`).                                                                                                                                               |
| `campaign-applications.spec.ts`                                             | W6, W7     | **Verified and extended if the fixture has a rostered applicant:** accept and reject (`:76-114`) with the new Message beside them; the detail page (`:70`) with the files aside.        |
| `hand-off-at-approval-lifecycle.spec.ts`, `creator-campaign-status.spec.ts` | W6         | **Verified:** both load `/campaigns/:ulid` (`:91`, `:158`; `:91`), now with the Overview files request.                                                                                 |

No Playwright spec visits `/discover` (READ: none in the list), so W2 has no E2E exposure; it is covered by Vitest only, and that is said, not hidden.

---

## 6. Proof

### 6.1 The standing gate, every step

1. **Tip green at every commit:** Vitest `apps/main`, `apps/admin`, `packages/*`; `vue-tsc` (main and admin); ESLint; Prettier on touched files. API steps: full backend Pest serial (`-d memory_limit=2G`), Pint `--all`, PHPStan. `verify-locale` 24/24 and `i18n-locale-parity.spec.ts` on every step that touches a key (W1, W2, W3, W6, W7).
2. **Break-revert** for every new spec and pin (named per step above); the result goes in the commit message.
3. **Alarm rule:** if a frozen file's hash, a creator walk hash, or a step's need for an edit in the forbidden list appears, stop and report.

### 6.2 The creator-shell proof, per web commit (W3, W2, W1, W4, W5, W6, W7)

Every web commit runs all of:

1. **Spec 5 `shared-components-frozen`** green and unedited. Content hashes of the 22 shared `.vue` files unchanged.
2. **Spec 3 `shared-tokens-frozen`** green and unedited.
3. **Spec 2 `agency-theme-isolation`** green: no creator, onboarding, auth or admin file reaches agency code. New agency files (`CampaignFilesStrip`, `useCampaignFiles`, `useMessageableCreatorIds`) are reached only from agency pages.
4. **Spec 7 `creator-no-agency-theme`** green: no `v-theme--agency-*` class in any creator tree.
5. **Spec 1 `agency-theme-scoping`** green on any commit that edits `agency.css` (W1, W5, W6, and W4 if the pills need a margin): every new selector sits under the scope form.
6. **Forbidden-path diff empty:** `git diff --stat 3acdbbbe..HEAD -- <the 22 spec-5 files> packages/design-tokens packages/ui apps/main/src/plugins/vuetify.ts apps/main/src/App.vue apps/main/src/composables/useTheme.ts apps/main/src/composables/useThemePreference.ts apps/admin` prints nothing.
7. **`packages/api-client` is types-only** (W1, W2, W4): `git diff -U0 3acdbbbe..HEAD -- packages/api-client/src` shows only added optional interface members, no runtime line; api-client tests and `apps/admin` `vue-tsc` and Vitest green.
8. **The creator walk:** from `apps/main`, `node tools/walk/walk.mjs --set creator --compare ../../docs/reviews/ah-114-creator-baseline/baseline.json` prints `COMPARE OK: 142 targets identical to baseline.json` (DOM hash, computed-style hash, theme stylesheet hash, thumbnails; the same 142 targets as the AH-114 close-out). Expected to be a no-op for every step here, because no creator-reachable file changes; it is run anyway because that is the check that would catch a leak.
   - **Stated limit (the same as AH-114):** the walk uses the stub harness with no API and covers the creator, onboarding, auth, error and app routes. It does not open agency pages. A signed-in creator comparison against production remains the one check that needs a session from Pedram. The guarantee for signed-in creator pages is specs 2, 5 and 7 plus the empty forbidden-path diff.
9. **Agency proof for the touched routes:** the agency walk tooling (`tools/walk/shots-l3.mjs`, `walk-l4.mjs`, fixtures `l3-fixtures.mjs` and `l4-fixtures.mjs`) re-shoots the step's routes in both themes at 1280, each screenshot confirmed against a DOM read from the same step (the AH-114 stale-frame rule). W1 and W3 update `l4-fixtures.mjs` for the dashboard and roster.

**Per-step additions to the above:** W3 adds the 24-locale deletion check; W2 adds `api-wrapper-params-serialized.spec.ts` as the named guard (red then green on the break-revert); W1 adds the absent-`status_counts` run (the page against a fixture that omits the key); W4 adds a run of the page against an API fixture that ignores filters, to show what the deploy-order note means; W6 adds the "no shared file touched" check specifically for `ChatPanel.vue` (hash unchanged); W7 adds the isolation check on `useMessageableCreatorIds` (agency folder only).

### 6.3 At the end of the series (before the push)

- Full Playwright E2E (§5) with the dev stack down; counts read first and recorded.
- A **production build** of `apps/main` served locally, the creator walk re-run against it, and the agency pages for W1 to W7 loaded against it. `git diff --stat` over the forbidden paths empty.

---

## 7. Deploy

**Two deploys, fixed order: API, then main web.** Neither carries a migration, a one-shot, a flag, a worker change, an env change or a route change. Both come from the mirror by Pedram's colleague.

**Why the order is fixed:** the web build tolerates an old API for item 1 (absent `status_counts` is read as null and the page shows today's summary), but a web build against an old API for item 4 sends `country` and `language` to an endpoint that ignores them, so the pool page would show a selected filter over an unfiltered list.

### Entry 1 (opened `PENDING` in D2): API

```
### YYYY-MM-DD · AH-116 API: campaign status counts, pool member filters, archived brand read — PENDING

- **Status:** PENDING
- **Range:** 066ff29c (production API) → <A3's commit>. Docs-only commits between them are listed, not counted.
- **AH entries carried:** AH-116 (steps A1, A2, A3)
- **Migrations run:** none
- **Pre-deploy reads:** none (no schema or data read applies). `GET .../campaigns` and `GET .../talent-pools/{pool}/creators` envelopes before the deploy: not read (recorded as such).
- **Snapshot ID:** none: no migration, no data change, no one-shot. Not applicable.
- **Infra:** none required. HTTP controllers only: no worker restart is needed by this change, no cron or scheduler change, no env change, no `composer` change, one route-definition option changes (`withTrashed(['show'])` on `brands`, A3; no route is added and no URI or name changes), so a box that runs `route:cache` rebuilds it in its standard deploy: recorded as run or not read. Whatever the box's standard code-deploy steps are, they are recorded as run or not read; they are not asked for by this change.
- **One-shot commands:** none
- **Post-deploy verification:** `/up` 200; an authenticated agency `GET /api/v1/agencies/{agency}/campaigns?per_page=1` returns `meta.status_counts` with the five keys, all integers, summing to `meta.total` when unfiltered; the same call with `?status=active` returns an identical `status_counts`; an authenticated `GET .../talent-pools/{pool}/creators?country=<code>` returns only matching members and a smaller `meta.total`; `GET .../brands/{an archived brand's ULID}` in the caller's own agency returns 200 with `status: archived`, and the same ULID from another agency's session returns 404. Each item is written as read or "not read".
- **Flags armed:** none: no behaviour to arm.
- **Operator:** the colleague, from the mirror
- **Anything unexpected:** (the honest field)
- **Rollback:** redeploy the previous API. The web build in production (`1bc398e8`) neither sends nor reads either addition, so a rollback breaks nothing.
```

### Entry 2 (opened `PENDING` in D2, flipped only after Entry 1 reads `DEPLOYED`): main web

```
### YYYY-MM-DD · AH-116 web: agency UX batch (main SPA only) — PENDING

- **Status:** PENDING
- **Range:** 1bc398e8 (production main SPA) → <W7's commit>. The mirror's SHA for the deployed commit is recorded beside GitHub's (two pairs already differ: a60ffbbf = 5d2ecdbc and c295185 = 1bc398e8).
- **AH entries carried:** AH-116 (steps W3, W2, W1, W4, W5, W6, W7). No other entry rides this deploy.
- **Migrations run:** none
- **Pre-deploy reads:** **Entry 1 is DEPLOYED** (the precondition; its post-deploy `status_counts` read is quoted here). The creator-walk result (`COMPARE OK: 142 targets identical to baseline.json`), the full Playwright counts and the production-build probe are quoted from the review doc, as reported, not read from the server.
- **Snapshot ID:** none: no migration, no data change. Not applicable.
- **Infra:** none. No worker restart, no cron, no env, no `composer`, no `artisan`, no container recreate. Builds: the **main** SPA build and its Netlify deploy only. The **admin** build is not rebuilt: no file under `apps/admin` changed, and the `packages/api-client` change is type-only (the diff check in §6.2 item 7 is the evidence). If Pedram prefers a belt-and-braces admin rebuild, it is harmless.
- **One-shot commands:** none
- **Post-deploy verification:** the served main bundle hash equals the production-build run's; an agency sign-in; the Campaigns list shows per-status counts and "N total, M active"; the dashboard shows Active campaigns from the list and no Payments due card; Discover's age inputs filter and an age-filtered empty result shows the note; a pool detail's country and language filters narrow the list; the sweep surfaces link (roster name, pool member, thread header, campaign title, brand name on every listed surface, including an archived brand's page showing its Archived pill) and the dialog surfaces still open the dialog; the Overview files strip and the chat strip render and download; a pending application whose creator is on the roster shows Message and it opens the thread; the roster no longer shows Followers or Engagement; a creator web sign-in with the S0.2 pages walked against the baseline screenshots. Each item is written as read or "not read".
- **Flags armed:** none: no behaviour to arm.
- **Operator:** the colleague, from the mirror
- **Anything unexpected:** (the honest field)
- **Rollback:** redeploy the previous main `dist` (Netlify). The API additions are inert to the old web build. No migration or queue to undo.
```

**What stays on production until then:** API `066ff29c`, main SPA `1bc398e8`. Creator native build 3 is a bundled binary and cannot be reached by a web deploy.

---

## 8. Logged for later (not in this chunk; written to `tech-debt.md` in D1)

1. **Dashboard activity rows carry no entity ids**, so creator, campaign and brand names in the feed cannot link. Trigger: the next dashboard chunk. Needs an additive field on `DashboardActivityFeed` (ids and names in the allowlisted metadata) and the PII posture decided first.
2. ~~**Archived-brand route.**~~ **Closed by A3** (P1 ruling): `brands.show` resolves archived brands in the caller's own agency, and every brand name links. Left as a line so the numbering of this list stays stable.
3. **`can_message` on `CampaignApplicationListItemResource`.** Replaces the paged `messageable-creators` lookup in W7 with one additive boolean on the row (the resource's keyset is exact-pinned, so it needs the pin and the client type edited together).
4. **Roster age-filter empty state.** The roster has the same unknown-age exclusion with no explanation (`tech-debt.md:2711-2733`); this chunk adds the line to Discover only.
5. **An "include creators with unknown age" bound.** Still the named option in the AH-088 row; its trigger is unchanged.
6. **Pool members `per_page`.** The endpoint ignores it (25 fixed).
7. **A `useBackToList` for the new route links** (roster name, pool member, thread header), so leaving to a profile and returning lands on the same filtered page. Discover and the roster already do this for their own lists.
8. **Discover has no Playwright coverage**; W2 is covered by Vitest only.

---

## 9. Points for the plan review

Answer by number. A recommendation is in brackets.

- **P1. Brand links: OVERRULED by Pedram (2026-11-02).** Add A3 (archived brands resolve on `show`), link brand names on every listed surface, close the archived-brand follow-up. Applied in A3, W5 and §8 item 2.
- **P2. Drafts tab name.** I left it plain: it is a campaign work surface whose row already opens the review drawer, so there is no dialog to "keep" and no list to link from. [Leave. If you want a link there, it is one `roster.detail` link in W5.]
- **P3. Q6's `discover.detail` branch has no caller.** Every route-link surface knows the relation, so all go to `roster.detail`; I did not build a helper for a branch nothing uses. [Confirm; the helper appears when a surface without a relation is added.]
- **P4. The age note is a new key**, not a reuse, because the roster has no such line (`app.roster.emptyFiltered` is generic). [Confirm one new key; roster gets the same line in the follow-up.]
- **P5. Label reuse across namespaces.** Discover's age inputs and the pool pills reuse `app.roster.filters.*`, and the pool filtered-empty reuses `app.roster.emptyFiltered.*`, so the key count stays at nine. The convention elsewhere is a namespace per page (Discover and the roster each carry their own country and language labels today). [Reuse. If you prefer per-page namespaces, add five keys: `app.discover.filters.age.min`, `.age.max`, `app.pools.detail.filters.country`, `.language` and `app.pools.detail.membersEmptyFiltered`.]
- **P6. Two deploy-log entries, not one.** The API and web deploys are separate sittings with separate rollbacks and a fixed order. [Two entries, as drafted in §7.]
- **P7. The messageable lookup is capped at 10 pages (1000 creators) and fails closed.** An agency with a larger roster sees Message only for creators in the first thousand it loads, and none on an error. [Accept for now; `can_message` is the real fix and is logged.]
- **P8. The thread header link needs the creator ULID.** I expect the thread view to have it (the thread is keyed by it); if it does not, W5 passes it down from `AgencyRelationshipThreadPage` (both agency-only). No decision needed unless that turns out to touch a shared file, in which case W5 stops and reports.

**No code is written. Stopping for plan review.**

### 9.1 Stop point after the build (Pedram, 2026-11-02)

The build stops before D1 with: the tip; the gate results per step; the creator walk result; and dark, 1600 px screenshots of Campaigns with counts, Discover with the age filter, pool members filtered, Campaign detail with the files strip, the chat with the pinned strip, and an application row with Message. Results are recorded in the build notes, not in this plan.
