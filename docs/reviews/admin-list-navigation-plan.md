# AH-117 — admin list navigation: PLAN (plan-pause)

> **Status:** **PLAN CLEARED by Pedram, 2026-11-04**, with these rulings: **P1** yes, the S0 probe gates the table-render fallback; **P2** confirmed (search URL updates after the pause); **P3** a route-driven re-seed scrolls to the top (the Q5/Q6 edge is resolved, §2 S1/S2); **P4** confirmed, no admin list E2E in this chunk and a tech-debt row for the gap; **P5** the admin SPA is at the **2026-10-29 build** (reported: Pedram used the Erasure queue page in production on 2026-11-01), the bundle hash is read by Pedram before the deploy (§7); **P6** Checkpoint A is the assistant's own Approvals browser walk after S3, reported with a screenshot; the build continues unless it fails. Build S0 to S7 one commit each, stop before D1.
> **Mode:** A (full loop, plan-pause kept per ruling Q8). Strictly nothing here is a Mode A trigger (no API, validation, gate, route table, i18n key or state-machine edge), but five list pages and the router change, so the plan-pause stays.
> **Inventory:** accepted by Pedram 2026-11-03 (chat report; not a file). Findings are restated where a step depends on them. Rulings Q1 to Q10 and the two extras are applied in §1.
> **Baseline:** `origin/main` = `ad9f2f79` (clean tree). Production: API `066ff29c`, main SPA `1bc398e8`, **admin SPA: reported at the 2026-10-29 build (P5); bundle hash not read** (§7).

**PROD-DATA RISK: NONE.** SPA-only change in `apps/admin`. No API, migration, data write, backfill, one-shot, queue, env, flag or route-table change. The URL carries only list state (page, page size, a status chip, a search term on two pages). Nothing is written to the server and nothing new is stored in the browser except the history entry's own URL. A step that would need any of those stops and reports (alarm rule).

**Evidence labels.** `READ` = file and line read at `ad9f2f79` (or the Vuetify source named). `INFERRED` = follows from what was read, not run. `VERIFY AT BUILD` = the step's first action reads or measures it before any edit.

---

## 0. Summary

1. **Eight build steps and a docs step.** S1 composable, S2 `scrollBehavior`, S3 Approvals, S4 KYC queue, S5 All creators, S6 Agencies (end of sub-step 1), S7 Creator app (sub-step 2), D1 docs. Each step ends green and is its own commit.
2. **Browser back needs no button.** List updates use `router.replace`, so the history entry behind a detail page is the list's own URL. URL state plus `scrollBehavior` is the whole fix. No "Back to list" control, so no i18n (ruling Q3).
3. **One composable, copied and trimmed.** `apps/admin/src/composables/useListQueryState.ts`, a mirror of main's, four codecs, two-way sync, owns the page-1 reset rule (§2, S1).
4. **One request per user action.** Each list gets a single load trigger over its URL-backed refs, the table's mount echo is guarded, and a page beyond `meta.last_page` clamps and refetches once.
5. **Deploy: the admin Netlify site only**, no API dependency (§7).

---

## 1. Rulings applied

| Ruling                                                    | Applied as                                                                                                                                                                                                                                                                                                                                        |
| --------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Q1** staged single chunk                                | Sub-step 1: S1, S2, S3 to S6. Sub-step 2: S7 (Creator app). Follow-up (logged, not built): Audit log, Impersonation log, Push deliveries (cursor and Apply-button pages) and the Erasure queue tab.                                                                                                                                               |
| **Q2** copy, trimmed, no parity spec                      | `apps/admin/src/composables/useListQueryState.ts` with the header "mirror of `apps/main/src/composables/useListQueryState.ts`, diverges in: (1) two-way sync with `route.query`, (2) the page-1 reset rule lives here, (3) four codecs only (no `boundedIntParam`, `isoDateParam`, `oneOfParam`), (4) …". No parity spec. `packages/*` untouched. |
| **Q3** no button                                          | No visible control, no key. `useBackToList` is **not** copied.                                                                                                                                                                                                                                                                                    |
| **Q4** two-way re-seed                                    | S1: a `watch` on `route.query` re-seeds the refs.                                                                                                                                                                                                                                                                                                 |
| **Q5** query-only same-route changes skip scroll-to-top   | S2.                                                                                                                                                                                                                                                                                                                                               |
| **Q6** bare sidebar URL resets                            | Falls out of Q4: the sidebar link is `{name}` with no query (`AdminLayout.vue:121`, `:156`), so it navigates to a bare URL and the re-seed resets the refs. **No nav change.**                                                                                                                                                                    |
| **Q7** search persisted for Agencies and Creator app only | `q` on those two. User search and the Impersonation log `q` are not touched.                                                                                                                                                                                                                                                                      |
| **Q8** plan-pause kept                                    | This file.                                                                                                                                                                                                                                                                                                                                        |
| **Q9** Creator app → push deliveries → back covered       | S7 and walk item W9. The dialog link is a `v-btn :to` push (`CreatorAppSignInsDialog.vue:194`).                                                                                                                                                                                                                                                   |
| **Q10** clamp to `meta.last_page`, refetch once           | In each page's load (S3 to S7), one shared pure helper, `exceedsLastPage`.                                                                                                                                                                                                                                                                        |
| **Extra** echo guard on the four pages                    | S3 to S6. `CreatorAppPage` has it already (`:128-135`).                                                                                                                                                                                                                                                                                           |
| **Extra** `perPageParam([10,25,50,100], 25)`              | S1 codec; S3 to S7 set the table's `items-per-page-options` to the same four numbers, which removes Vuetify's "All" (-1) option. The backend clamps `per_page` to 1..100 (`AdminCreatorController.php:83-84`), so "All" sent -1 and got 1 row; that stops being possible.                                                                         |

---

## 2. The plan: steps in build order

**Ground rules for every step**

- **Files that may not be edited:** every admin page not named below (Audit log, Impersonation log, Push deliveries, Erasure queue, User search, Automations, Feature flags, Alerts, Export requests, Dashboard, System health, both detail pages), `AdminLayout.vue`, `navItems.ts`, all of `apps/main`, `apps/api`, `packages/*`, and every locale file. Checked at every commit with `git diff --stat` against that list; the expected result is empty.
- **No new i18n key.** `git diff --stat -- '**/locales/**'` is empty at every commit.
- **The detail-navigation call is unchanged:** `router.push({ name: 'app.creators.detail', params: { ulid } })` and the agency twin. That keeps the four "navigates to the detail page" specs valid as they are.
- **One commit per step, tip green at every commit** (gate in §6). Commit messages carry the break-revert results and the evidence labels.
- **Standards applied:** break-revert (§5.35) on every new behaviour; §5.34 negative sets for every codec (each junk value is valid in every respect except one); alarm rule; no push without Pedram's word.

### S0 — Baseline reads (no commit, nothing kept)

Before any edit, read and record in the S1 commit message:

1. **Mount fetch count today** on Approvals, KYC, All creators and Agencies: a throwaway spec that counts `adminCreatorsApi.list` calls after mount and `flushPromises`. `INFERRED` 2 (`onMounted` plus the table's `update:options` echo; `CreatorAppPage.vue:129-132` says the echo happens on mount). The number is the baseline for "one fetch on mount".
2. **A deep-linked page survives the table's mount.** `READ`: Vuetify 3.12.5 `VDataTable/composables/paginate.js` clamps `page` to `pageCount` in a `watch([page, pageCount])` that is **not** immediate, and `pageCount` is 1 while `itemsLength` is 0. `INFERRED`: a seeded `page=3` is held through the empty-table mount and is not clamped when the first response arrives (`pageCount` rises, the page is within it). **Measure it** with a throwaway mount of `v-data-table-server :page="3" :items-length` going 0 → 120 and record the `update:options` events. If the table does clamp or echo a different page, S3 gains one line (render the table only after the first response, as main's roster does, `CreatorRosterPage.vue:333-335`) and the plan is amended before S4.
3. **The dev DB row counts** (creators by status, agencies) so the walk (§5) knows which pages have three or more pages at 10 per page.

### S1 — The composable (`apps/admin/src/composables/useListQueryState.ts`, new, plus `.spec.ts`)

**Structural intent.** A list page holds its browse context in the URL, validated, replace-only, in sync both ways, with exactly one place deciding when the page number goes back to 1.

**What it contains.**

- **Codecs (four):** `pageParam` (positive integer, else 1; page 1 omitted), `perPageParam(allowed, fallback)`, `textParam` (null-able because Vuetify `clearable` writes null; trims on serialize), `oneOfParamWithFallback(allowed, fallback)` (a chip group whose default is one of its values; the default is omitted).
- **`useListQueryState(schema)`:** returns one `Ref` per declared param, seeded from `route.query` at setup, as main's does. Writes are `router.replace`, never push; foreign query keys are carried through; nothing is written when the serialized query already equals the route's.
- **Divergence 1: two-way.** A `watch` on `route.query` parses each declared key and sets the ref **only when `serialize(ref) !== serialize(parse(route value))`**. Comparing serialized forms, not parsed values, is what keeps a trailing space typed into a text ref from being eaten by its own write-back (the URL holds the trimmed text; the ref holds what was typed).
- **Divergence 2: the page-1 reset rule.** When the schema has a `page` key, a change to any other declared key (not `per_page`) that differs from the route, **while `page` does not differ from the route**, sets `page` to 1. The rule is computed from the difference to the route, not from a flag, so a re-seed (ref equals route by construction) can never trigger it: a link to `?status=approved&page=3` keeps page 3, and a user chip click from page 3 lands on page 1. The reset happens before the page's load trigger runs, so a chip click is one request.
- **Divergence 3 (trimmed):** no `boundedIntParam`, `isoDateParam`, `oneOfParam`. The follow-up adds `isoDateParam` for the Audit and Impersonation date inputs.
- **Divergence 4 (P3): a route-driven re-seed that changes a ref scrolls the window to the top** (`window.scrollTo({ top: 0 })`, once per re-seed). A re-seed is by definition not a user edit of the list (a sidebar click, a pasted link), so the reset list starts at its top. User edits write the URL themselves and never re-seed, so paging and chips are unaffected (Q5 holds). Setup-time seeding does not scroll (back onto a list restores through `scrollBehavior`).
- **`exceedsLastPage(page, lastPage): boolean`** (pure, exported): true when `lastPage >= 1 && page > lastPage`. Used by S3 to S7 for ruling Q10.

**Spec (`useListQueryState.spec.ts`, mounted through a real component and a memory router like main's `useListQueryState.spec.ts`).** New cases, by name:

1. seeds each declared ref from the URL; absent keys read as defaults.
2. **junk reads as default, one case per codec** (`?page=0`, `?page=-2`, `?page=2.5`, `?page=abc`, `?per_page=100000`, `?per_page=-1`, `?per_page=abc`, `?status=bogus`, `?q=` empty) — the §5.34 negatives.
3. `perPageParam([10,25,50,100], 25)`: 10, 25, 50, 100 pass, everything else is 25, and 25 is omitted from the URL.
4. a ref at its default is omitted from the URL; an untouched list stays on a clean path.
5. writes use `replace` and never `push` (the memory history's entry count is unchanged after five writes).
6. foreign keys survive a write.
7. **re-seed:** `router.replace` to the bare path resets every ref to its default; to `?status=approved&page=3` sets both and **keeps page 3** (the reset rule does not fire on a route-driven change).
8. **no feedback loop:** one user change produces exactly one `replace`, and the re-seed that follows sets nothing.
9. **a trailing space typed into a text ref is not eaten** (the ref stays `'foo '`, the URL holds `foo`).
10. **the reset rule:** a chip change from page 3 sets page 1 and the URL loses `page`; a chip change and a page change in the same tick keep the page change; a `per_page` change alone does not reset.
11. `exceedsLastPage`: page 9 of 3 true; page 3 of 3 false; page 1 of 0 false; `lastPage` 0 false.
12. **(P3)** a route-driven re-seed that changes a ref calls `window.scrollTo` with `top: 0` once; a re-seed that changes nothing does not; a user edit (the ref's own write) does not; setup-time seeding does not.

**Break-reverts (§5.35), each must fail the named case:** `replace` → `push` (5); drop the foreign-key spread (6); re-seed compares parsed values instead of serialized (9); remove the `route.query` watch (7); reset rule fires on a re-seed (7); reset rule removed (10); `perPageParam` drops the allow-list (3); `pageParam` accepts 0 (2); serialize emits the default (4); drop the re-seed scroll (12); scroll on a user edit (12).

### S2 — Router `scrollBehavior` (`apps/admin/src/core/router/index.ts`)

**Structural intent.** Back and forward return the operator where they were; a new page starts at the top; a query-only change on the same list leaves the scroll alone.

**What.** Export `scrollBehavior` (the module's only new export) and add it to `createVueRouter({ history, routes, scrollBehavior })` (`index.ts:80-83`). A copy of main's (`apps/main/src/core/router/index.ts:56-102`: poll `documentElement.scrollHeight - clientHeight >= top` every 50 ms, give up after 1,200 ms) with one divergence, in this order:

1. `savedPosition` present → wait for height, return it (back and forward, including a back that lands on a list with a query).
2. `to.name != null && to.name === from.name && to.path === from.path` → `false` (query-only change on the same route: paging, a chip, the sidebar's own link; no scroll). `from.name` is `undefined` for the first navigation, so the first navigation never matches.
3. otherwise `{ top: 0 }`.

`READ`: the window scrolls (no `overflow` or `100vh` container in `AdminLayout.vue` or `App.vue`; the only `v-main` is `AdminLayout.vue:238`), so `documentElement` is the right element. `INFERRED` and walk-checked (W11): today, with no `scrollBehavior`, a detail page opened from a scrolled list stays at the list's offset.

**Router spec (`tests/unit/core/router/index.spec.ts`, new `describe('scrollBehavior')`, mirror of main's `:140-`: `document.documentElement` `scrollHeight` and `clientHeight` set through `Object.defineProperty`, fake timers).** New cases, by name:

1. a fresh navigation to a different route starts at the top (`{ top: 0 }`).
2. **the first navigation** (`from` = `START_LOCATION`) to the dashboard at `/` starts at the top (the `from.path === '/'` trap).
3. a saved position is returned at once when the page is already tall enough.
4. a saved position **waits** for the page to grow, then returns it.
5. a saved position that never becomes reachable is returned after the timeout (the navigation does not hang).
6. a saved position wins over the query-only rule (back onto the same route restores).
7. **a query-only change on the same route returns `false`** (`/creators?status=approved` → `/creators?status=approved&page=2`).
8. a bare sidebar click on the same route (`/creators?page=3` → `/creators`) returns `false` from `scrollBehavior` (same rule; the scroll to top for that case is the composable's, S1 case 12, ruling P3).
9. a different **param** on the same route name (`/creators/A` → `/creators/B`) starts at the top.
10. a different route name with the same query starts at the top.
11. wiring pin: `createRouter().options.scrollBehavior === scrollBehavior`.

**Break-reverts:** drop the saved-position branch (3, 4, 6); drop the height wait (4); drop the timeout (5); compare `name` only (9); compare `path` only (10); drop the `from.name != null` guard (2); remove the query-only branch (7, 8); remove the option from `createVueRouter` (11).

### S3 — Approvals (`CreatorListPage.vue`)

**State (URL keys):** `page`, `per_page` (`perPageParam([10,25,50,100], 25)`), `status` (`oneOfParamWithFallback(['pending','approved','rejected','incomplete','all'], 'pending')`). The three replace `statusFilter` (`:33`) and `tableOptions` (`:39`).

**Load trigger (the pattern S4 to S7 copy).** One `watch([page, per_page, status], load)`; `onMounted(load)`; the old `watch(statusFilter, …)` (`:101`) and the unconditional `onTableUpdate` (`:106`) go. `onTableUpdate` becomes: return when `opts.page === page.value && opts.itemsPerPage === per_page.value`, else set both. The page-1 reset is the composable's (S1), so the page has no reset code. A `requestSeq` guard drops a stale response (as `CreatorAppPage.vue:81-96` does).
**Q10:** after a response, `if (exceedsLastPage(page.value, res.meta.last_page)) { page.value = res.meta.last_page; return }` — the watcher refetches once; the empty response is not rendered. Terminates: after the refetch `page <= last_page`.
**Table:** `:items-per-page-options="[10, 25, 50, 100]"`, `:page="page"`, `:items-per-page="per_page"`.
**Unchanged:** the row click, the headers, the template, `adminCreatorsApi.list` (the request body is the same `{status, page, per_page}`).

**Spec edits, `CreatorListPage.spec.ts`, by name:**

- _loads the pending queue on mount and renders the rows_: **stays**; add `expect(adminCreatorsApi.list).toHaveBeenCalledTimes(1)` (the "one fetch on mount" proof).
- _drops the status param when the "all" filter is selected_: **stays**; add that the URL now reads `?status=all` (`all` is a non-default here).
- _re-queries with the chosen status when a filter chip is clicked_: **stays**; add the URL `?status=rejected` and no `page`.
- _navigates to the detail page when a row name is clicked_ (`:144-148`): **stays unchanged** (the push shape is unchanged).
- _surfaces the API error code…_, _reads an erased creator and a leaving creator through the reader (AH-104c C2-4)_: stay.
- **New:** seeds from the URL (`?status=approved&page=3&per_page=10` → first request `{status:'approved', page:3, per_page:10}`, one request); junk URL (`?status=bogus&page=-2&per_page=100000` → the defaults); a chip click from page 3 → page 1, URL without `page`, **one** request; the table's page change → URL `page=2`, one request, **`router.push` not called** (`replace` spy); the echo guard (no request on mount beyond the first); re-seed (`router.replace({ name: 'app.creators.list' })` from `?status=approved&page=3` → defaults, one request); **Q10** (`?page=9`, first response `last_page: 2` → exactly one refetch with `page: 2`, URL `page=2`, the empty first response not rendered); a stale response is dropped.

**Break-reverts (page level):** reset rule removed in S1 is already covered; here: drop the echo guard (mount count 2); drop the clamp (Q10 case); clamp without `return` (renders the empty page); drop `requestSeq` (stale case); `status` default changed (seed case).

**Checkpoint A: after S3, Pedram walks Approvals only (§5, W1 to W7) on the dev stack before S4 to S6 copy the pattern.**

### S4 — KYC queue (`KycQueuePage.vue`)

S3's pattern. URL keys: `page`, `per_page`, `kyc_status` (`oneOfParamWithFallback(['pending','verified','rejected','none','all'], 'pending')`). The nav-badge write stays (`:77-79`, only when the filter is `pending`).
**`KycQueuePage.spec.ts`, by name:** _defaults to the pending KYC filter and feeds the nav badge_: stays, plus `toHaveBeenCalledTimes(1)`; _drops the kyc_status param when the "all" chip is selected_: stays, plus URL `?kyc_status=all`; _re-queries with the chosen kyc_status when a chip is clicked_: stays, plus URL; _reads an erased creator…_: stays. **New:** seeds and junk (one case), a chip click from page 3, the badge is not written for a seeded non-pending filter (`?kyc_status=verified` → no `setCounts`), Q10, one request on mount. (This file has no navigation test today; none is added.)

### S5 — All creators (`AllCreatorsPage.vue`)

URL keys: `page`, `per_page`, `status` (`['all','pending','approved','rejected','incomplete']`, default `all`), `kyc_status` (`['all','pending','verified','rejected','none']`, default `all`), `connected` (`['all','yes','no']`, default `all`). The header comment at `:14-17` ("No URL-state persistence … so this doesn't start now") is **replaced** with the AH-117 wording; the AH-079 log entry stays as written and AH-117's entry records that it supersedes that decision.
**`AllCreatorsPage.spec.ts`, by name:** _loads with no filter params on mount…_: stays, plus once; _sends the status param…_, _sends the kyc_status param…_, _sends connected=true…_, _sends connected=false…_: stay, plus URL assertions; _AND-composes all three chips at once (D4)_: stays, plus the URL carries all three; **_resets to page 1 when a filter changes after paging forward_**: **edited**: seeds page 2 through the URL, asserts the request and that the URL loses `page`; _navigates to the detail page…_ (`:251-255`): stays unchanged; _surfaces the API error code…_, _reads an erased creator…_: stay. **New:** seeds and junk (all three chips), re-seed, Q10, one request on mount.

### S6 — Agencies (`AgencyListPage.vue`)

URL keys: `page`, `per_page`, `status` (`['all','active','suspended']`, default `all`), `q` (`textParam`). **The search is a draft and an applied value:** `searchInput` (a local ref bound to the text field, seeded from `q`) and `q` (URL-backed). `watch(searchInput)` debounces 300 ms, then writes `q`; `watch(q)` writes `searchInput` back when it differs (re-seed). The URL and the load trigger see only the applied term, so typing makes no per-keystroke URL entry and no request until the pause (the same behaviour as today, `AgencyListPage.vue:100-107`). The `clearable` null is coerced when the debounce writes `q` (the existing `(search.value ?? '')` guard moves to that line).
**`AgencyListPage.spec.ts`, by name:** _loads with the "all" filter on mount and renders rows_: stays, plus once; _re-queries with status=suspended when the suspended chip is clicked_: stays, plus URL; _navigates to the detail page…_ (`:127-131`): stays unchanged; **_reloads with no search term (not a crash) when the clearable search is cleared to null_** (`:137`): **edited**: the clear now travels through `searchInput` → debounce → `q`; the assertions (no `search` param, no crash) are unchanged; _surfaces the API error code…_: stays. **New:** seeds `?q=acme&status=suspended&page=2` (the text field shows `acme`), a typed term writes `q` once after the pause and **resets to page 1 (one request)**, the re-seed clears the text field, junk, Q10.

### S7 — Creator app (`CreatorAppPage.vue`) — sub-step 2

URL keys: `page`, `per_page`, `status` (`['all','active','signed_out','never']`, default `all`), `platform` (`['all','ios','android']`), `push` (`['all','registered','dead','none']`), `q` (`READ` at `creatorApp.api.ts:15-19`; each list is the page's own chip vocabulary, re-read at build). Same pattern as S3 and S6. **The sign-ins dialog stays local** (open/closed state is not in the URL; back from push deliveries lands on the list with the dialog closed). The existing echo guard and `requestSeq` stay.
**`CreatorAppPage.spec.ts`, by name:** _loads with no filter params and renders a row_: stays; **_issues ONE request on mount (the table echo does not double-fetch)_**: **stays unchanged and must still pass** (it is the proof the composable did not add a request); _renders the three states as chips_, _renders each push state…_, _shows the empty state and the error surface_, _an unknown/transport error code…_: stay; **_filters AND-compose and a filter change resets paging to page 1_**: **edited**: seeds page 2 through the URL, asserts the URL after the click; **_the search is debounced: many keystrokes, ONE request after the pause, from page 1_**: **edited**: also asserts `q` in the URL after the pause and none before it; the `sign-ins dialog` block, including _the deep link to push deliveries carries the creator ULID (and the display name)_: stays unchanged; _`/creators/app` is registered BEFORE `/creators/:ulid`…_: stays. **New:** seeds and junk, re-seed, Q10, and the round trip: from a seeded list state, `router.push` to push deliveries then `router.back()` returns the same query (the real memory-history round trip the browser does).

### D1 — Docs

AH-117 ad-hoc entry (Proposed → Landed, real SHAs, Why / What / Touched / commits); `docs/reviews/admin-list-navigation-review.md` (proof, break-revert outputs verbatim, "Production posture" section: reads only); tech-debt rows (§8); the deploy-log `PENDING` entry (§7); the `RESUMPTION-TEMPLATE.md` Part 2 refresh at session close. The AH-079 decision is recorded as superseded, not edited.

---

## 3. Specs: the full by-name list

- **Edited (assertions changed):** `AllCreatorsPage.spec.ts` _resets to page 1 when a filter changes after paging forward_; `AgencyListPage.spec.ts` _reloads with no search term (not a crash) when the clearable search is cleared to null_; `CreatorAppPage.spec.ts` _filters AND-compose and a filter change resets paging to page 1_ and _the search is debounced…_.
- **Stay, with added assertions (a URL read or a request count):** the first-load and chip cases of the four pages named in S3 to S6.
- **Stay unchanged:** the four _navigates to the detail page…_ cases (`CreatorListPage.spec.ts:144`, `AllCreatorsPage.spec.ts:251`, `AgencyListPage.spec.ts:127`; the KYC spec has none), `CreatorAppPage.spec.ts` _issues ONE request on mount_, the sign-ins dialog block and the route-order case, every _reads an erased creator…_ case, `PushDeliveriesPage.spec.ts` _deep link: ?creator prefills…_ (page untouched).
- **New files:** `src/composables/useListQueryState.spec.ts`.
- **New cases in an existing file:** `tests/unit/core/router/index.spec.ts` (`scrollBehavior`, eleven cases, S2).
- **Architecture specs:** `no-direct-router-imports.spec.ts` passes (the new file uses `useRouter` and `useRoute`, not the singleton). No spec needs the harness changed: `mountCreatorPage.ts` and `mountAgencyPage.ts` build a real memory-history router, so `replace` and `back` work.

---

## 4. Gates and Playwright

- **Per step:** the page's spec and the composable spec; `vue-tsc`; ESLint; Prettier on touched files.
- **Before any push:** full Vitest on `apps/admin` (run `pkill -f vitest` first: loaded-machine flakes, AH-116 trap 3), `vue-tsc`, ESLint, Prettier. `apps/main`, `packages/*` and `apps/api` are untouched, so their suites are not part of the board; CI runs them at the pushed tip and its run URL is cited (§5.41). Locale parity is unchanged (no locale file in the diff).
- **Playwright admin: both existing specs run before the push** (`admin-sign-in.spec.ts`, `admin-mandatory-mfa-enrollment.spec.ts`), because the router instance changed and both traverse it (sign-in redirects, the MFA redirect chain). Setup traps from AH-116 apply (dev stack down, `catalyst_e2e` created and migrated by hand, `TEST_HELPERS_TOKEN` exported, output into the app's `playwright-report/`, never `/tmp`). **No new admin Playwright spec is proposed** (P4).
- **Forbidden-path diff** (§2 ground rules) and the **empty locale diff** are part of the review file.

---

## 5. Browser walk for Pedram

Run on the dev stack at Checkpoint A (W1 to W7 on Approvals only), again at the end of S7 (every page), and a third time on production after the deploy (§7). Use per-page **10** so three pages exist. Record pass or fail per line; "not run" is a valid answer.

| #    | Page                                                 | Do                                                                                | Expected                                                                                                                         |
| ---- | ---------------------------------------------------- | --------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------- |
| W1   | Each of Approvals, KYC queue, All creators, Agencies | Open the list, click a row, press the browser back                                | Back on the same list, same filter, page 1                                                                                       |
| W2   | Same four                                            | Pick a non-default chip, go to page 3, scroll to the lower rows, open a row, back | Same chip, page 3, **scrolled near the row you opened**, the URL reads `?…&page=3`                                               |
| W3   | Approvals                                            | Sidebar → Approvals while on `?status=approved&page=3`                            | URL becomes bare `/creators`; chip back to Pending, page 1, **one** request                                                      |
| W4   | Approvals                                            | Reload the page at `?status=approved&page=3&per_page=10`                          | Same state after the reload (scroll best effort)                                                                                 |
| W5   | Approvals                                            | Paste the URL in a new tab; edit it to `?status=bogus&page=-2&per_page=100000`    | Defaults: Pending, page 1, 25 per page; no error                                                                                 |
| W6   | Approvals                                            | `?page=99`                                                                        | Lands on the last page; the URL is corrected to that page; the table is not blank; two requests at most (the first, the refetch) |
| W7   | Approvals                                            | Footer per-page menu                                                              | 10, 25, 50, 100 and **no "All"**; choosing 50 writes `per_page=50`                                                               |
| W8   | Agencies                                             | Type a term, wait; clear it with the X                                            | URL gets `q=…` **after** the pause (not per keystroke), page 1, one request; clearing removes `q`, no error                      |
| W9   | **Creator app**                                      | Open a row's sign-ins dialog → **View deliveries** → browser back                 | Back on Creator app with the same chips, search and page; the dialog is closed                                                   |
| W10  | Every converted page                                 | Network tab, open the page cold                                                   | **One** list request on load                                                                                                     |
| W11  | Approvals                                            | Scroll down a long page, open a row                                               | The detail page opens at the **top**                                                                                             |
| W12  | Approvals                                            | Click a chip while scrolled down; page forward at the bottom of the table         | The scroll does not jump to the top (Q5), the ruled behaviour                                                                    |
| W12b | Approvals                                            | Scroll down on `?status=approved&page=3`, then click Sidebar → Approvals          | The list resets **and the window scrolls to the top** (P3)                                                                       |
| W13  | Approvals                                            | Page 1 → 2 → 3, then browser back                                                 | Leaves the list (previous app page), **not** page 2: paging adds no history                                                      |
| W14  | Any                                                  | Sign out and in, or open `/sign-in`                                               | Sign-in and the MFA redirect still land correctly (router change)                                                                |

---

## 6. Risks named

1. **Vuetify's page clamp** (S0 item 2). `READ` that it is not immediate; **measured at S0**. Mitigation ready: render the table after the first response.
2. **Re-seed and the load trigger.** A route-driven change sets several refs in one tick; the single `watch` over them loads once (specs: S3 _re-seed_, S1 case 7). If a page ever shows two requests for one action, the page's `toHaveBeenCalledTimes` case fails.
3. **Main has the same one-way gap** (a sidebar click on a filtered roster leaves the refs stale). **Not touched** (out of scope, `apps/main` is a forbidden path); logged as tech-debt (§8).
4. **`history.state.scroll` after a reload** is browser-dependent; W4 scroll is best effort and said so.

---

## 7. Deploy: the shape of the `deploy-log.md` entry (admin Netlify site only)

Opened `PENDING` in the D1 docs commit, newest first, above `AH-116 API`.

```
## AH-117 — admin list navigation (admin SPA only) — PENDING

> **No API dependency.** The build works against the API that is live now; it reads the same
> list endpoints with the same parameters. It can be deployed before, after or without the
> AH-116 entries. Do not combine it with them: their web build is the **main** site.

- **Status:** PENDING. Opened <date>. Pushed (`origin/main`); **not deployed.**
- **Range:** <admin production tip: reported as the 2026-10-29 build, bundle hash read by Pedram
  before the deploy> → <AH-117's last runtime commit>.
- **AH entries carried:** AH-117 (S1 <sha>, S2 <sha>, S3 <sha>, S4 <sha>, S5 <sha>, S6 <sha>,
  S7 <sha>). Any other admin change found by `git log <admin tip>..HEAD -- apps/admin packages`
  is listed here by name. No other entry rides this deploy.
- **Migrations run:** none
- **Pre-deploy reads:** the admin bundle name served at admin.catalyst-growth.com
  (`assets/index-<hash>.js`) and which commit it was built from; the figures from the review
  file, quoted as measured locally, not read from the server.
- **Snapshot ID:** none: no migration, no data change. Not applicable.
- **Infra:** none. No worker restart, no cron, no env, no `composer`, no `artisan`, no
  container recreate. **Builds: the admin SPA build and its Netlify deploy only**
  (`unique-taffy-3d2553.netlify.app`, served as admin.catalyst-growth.com). The main SPA is not
  rebuilt (no `apps/main` or `packages/*` file changes in AH-117). TestFlight and the native
  binary are not touched.
- **One-shot commands:** none
- **Post-deploy verification (to fill, each as read or "not read"):** the admin sign-in and MFA
  land correctly; walk W1, W2, W3, W4, W6, W7, W9 and W10 on production (§5 of the plan);
  `/up` is not applicable (no API change).
- **Flags armed:** none. There is no behaviour to arm.
- **Operator:** to be recorded: the colleague, from the mirror (record both SHAs; the mirror
  rewrites them).
- **Anything unexpected:** to be recorded after the deploy.
- **Rollback:** redeploy the previous admin `dist` (Netlify). No API, migration or queue to undo.
```

**Admin production state (P5, ruled 2026-11-04).** Recorded **as reported**: the admin SPA is at the **2026-10-29 build**, because Pedram used the Erasure queue page in production on 2026-11-01. That page exists only in builds from 2026-10-25 on, so it is consistent with the 10-29 sitting and not with the 10-12 build. **Not read by the assistant; the deployed bundle hash (`assets/index-<hash>.js` at admin.catalyst-growth.com) and the commit it was built from are read by Pedram before this deploy** and written into the entry's Range. With the admin at the 10-29 build, the deploy carries AH-117 only (the seven admin commits listed in the earlier draft predate that build); `git log <admin tip>..HEAD -- apps/admin packages` at deploy time confirms the list and is pasted into the entry, because the mirror rewrites SHAs and the build tip is the thing to be sure of.

---

## 8. Follow-ups (tech-debt rows opened in D1, not built here)

1. Cursor and Apply-button lists: Audit log, Impersonation log, Push deliveries (needs `isoDateParam`, a draft/applied split and a decision on the cursor stack: a cursor token can sit in the URL but "previous" cannot survive a reload).
2. Erasure queue tab (six tabs, append-style "load more" cannot restore its depth).
3. **No admin Playwright spec covers list → detail → back, URL restore or scroll restore (P4, ruled).** The admin suite is two sign-in specs and has no list fixtures; a list spec needs seeded creators. Row opened in D1.
4. Main's one-way seeding gap (a sidebar click on a filtered roster or Discover leaves the refs stale while the URL goes bare).
5. **Resolved in this chunk (P3):** the sidebar-click scroll edge. A route-driven re-seed scrolls to the top (S1 divergence 4, case 12). Recorded as resolved, not as debt.
6. User search and Impersonation log free-text search deliberately not in the URL (may contain emails).
7. `ErasureQueue`, `Audit` and the three-chip pages' `Apply`/chip patterns are not shared; a shared draft/applied helper is the next extraction if the follow-up needs it.

---

## 9. Questions for the plan review (all ruled 2026-11-04, see the status line)

- **P1.** Is the S0 measurement (a throwaway probe, nothing committed) acceptable as the gate for the table-render fallback, with a plan amendment before S4 if it fails?
- **P2.** The search term is a draft and an applied value, so the URL changes **after** the 300 ms pause, not per keystroke (main's roster writes per keystroke). Confirm.
- **P3.** Rulings Q5 and Q6 meet at one edge: a sidebar click on the same list (query-only) leaves the scroll where it was, so the reset list can appear mid-page. Accept as ruled and log it (§8.5), or have a route-driven re-seed scroll to top?
- **P4.** No new admin Playwright spec in this chunk (the admin suite has two sign-in specs and no list fixtures; a list spec needs seeded creators). Confirm, or name it as a sub-step.
- **P5.** The admin deploy range: who reads the live admin bundle before the entry is written (Pedram or the colleague), given the discrepancy in §7?
- **P6.** Checkpoint A after S3 (Pedram walks Approvals before S4 to S6): confirm, or fold it into the end-of-sub-step-1 walk.

**Stopping here for plan review. No code until it is cleared.**
