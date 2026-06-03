# Sprint 6.6c — Review: Creator-side connection requests (the inbox)

**Status:** Closed. Spot-check passed (no PMC) — every anchor holds. The load-bearing one is the **approved-only gating (D-d1)**: the inbox section is absent AND `list` is never called in pending/rejected/incomplete (not merely visually hidden — proving no always-on creator-side fetch for creators with no relations). The binding matches the pinned 6.6b shape (flat `data` no meta; `id` = the relation ULID; `agency_id` bound as a ULID); the types are genuinely net-new (the agency `ConnectionRequestResponse` left untouched); accept/decline POST the row's `id` + re-fetch drops the row (the error path correctly keeps the row for retry); and the connected toast names the agency. The gated test-helper is the right call + the right pattern (`App\TestHelpers`, token-gated, 404-when-closed, mirroring `CreateRosterCreatorsController`) — D-d6's no-production-backend-change holds, and the §5.36 asymmetry (backend test infra to prove a FE feature) is named transparently with its own gate-closed Pest test. The Playwright round-trip passed locally end-to-end.

**Reviewer:** drafted by Cursor (build pass); spot-checked + closed.

**Reviewed against:** the Sprint 6.6c kickoff (locked decisions D-d1…D-d7 + the out-of-scope set + the honest-deviation triggers + the spot-check anchors), the pinned 6.6b endpoint contract ([`CreatorConnectionRequestController`](../../apps/api/app/Modules/Creators/Http/Controllers/CreatorConnectionRequestController.php) + [`CreatorConnectionRequestTest`](../../apps/api/tests/Feature/Modules/Creators/CreatorConnectionRequestTest.php)), the [6.6b review](sprint-6-6b-review.md) (the agency send-side `ConnectionRequestResponse` this chunk must NOT reuse), and the named precedents (`availability.api.ts`, `AvailabilityCalendar`'s `onMutated → load()` + `CEmptyState` usage, `DiscoverProfilePage`'s `meta.code`-keyed snackbar, the `CreateRosterCreatorsController` test-helper pattern, `creator-dashboard.spec.ts`).

**The last discovery chunk — the marketplace is now end-to-end.** 6.6b built the creator-side endpoints; 6.6c builds the UI that calls them: a connection-requests inbox **section on the creator dashboard** (approved branch) where a creator sees incoming agency requests and accepts/declines. Agencies discover the pool → send requests → creators accept/decline from their own surface.

**No production backend change (D-d6 holds).** The 6.6b row shape was sufficient to bind; the net-new TS types are FE-only. The one backend addition is **gated test infra** (an `App\TestHelpers` endpoint) needed for the Playwright round-trip — see the flagged divergence below.

---

## What shipped

### D-d1 — IA: a dashboard section, not a dedicated route

The inbox is a `<section>` on [`CreatorDashboardPage.vue`](../../apps/main/src/modules/creators/pages/CreatorDashboardPage.vue), rendered **only in the approved branch** (`v-if="status === 'approved'"`), placed after the completeness bar. No new route, no third topbar item, no new layout label. The page's vertical flex accepted the inserted section **without restructuring the banner logic** — honest-deviation trigger #2 did not fire. The fetch is likewise approved-gated (it never fires for pending/rejected/incomplete creators, who have no agency relations — and so the inbox introduces no always-on creator-side fetch).

### D-d2 — binding the list (the pinned contract)

[`connectionRequestsApi.list()`](../../apps/main/src/modules/creators/connectionRequests.api.ts) → `GET /creators/me/connection-requests`, bound to the new [`ConnectionRequestListResponse`](../../packages/api-client/src/types/agency.ts): a **flat `data: [...]`** with **no `meta`/pagination** (the UI does not expect an availability-style `meta.window`). Each row binds `id` (the relation ULID), `attributes.agency_name` (the title), and `attributes.invitation_sent_at` (the "Sent {date}" subtitle, nullable → "Sent recently" fallback). `agency_id` is typed + documented as **the agency's ULID despite the `_id` suffix** (D-d2 quirk) — it is bound only as the agency identifier, never as a numeric key. Newest-first ordering is the backend's.

### D-d3 — accept/decline wire the row's `id` ULID

[`accept(relationUlid)`](../../apps/main/src/modules/creators/connectionRequests.api.ts) / `decline(relationUlid)` POST to `…/{relation}/accept|decline`, where `{relation}` is the **row's `id`** (the relation ULID), not the agency id. Both bind the new [`ConnectionRequestActionResponse`](../../packages/api-client/src/types/agency.ts) (`data.attributes.relationship_status` + `meta.code: 'connection.accepted' | 'connection.declined'`). The server-side fail-closed responses (422 `connection.not_pending`, 404 `connection.not_found`) surface as the generic error toast — they are stale-list edge cases.

### D-d4 — net-new creator-self api wrapper

[`connectionRequests.api.ts`](../../apps/main/src/modules/creators/connectionRequests.api.ts), mirroring `availability.api.ts`: base `/creators/me/connection-requests`, no path id, `http.get`/`http.post`, `list()` / `accept(ulid)` / `decline(ulid)`. It is **not** the agency-path-scoped `discovery.api.ts::sendConnectionRequest` (the other side of the lifecycle) — the docblock makes the distinction explicit.

### D-d5 — net-new types; the agency `ConnectionRequestResponse` is NOT reused

Three net-new types in [`agency.ts`](../../packages/api-client/src/types/agency.ts): `ConnectionRequestListItem` (`type: 'connection_request'`), `ConnectionRequestListResponse` (flat, no meta), `ConnectionRequestActionResponse` (creator-side `meta.code` union). The existing `ConnectionRequestResponse` is the **agency send-side** shape (`type: 'agency_connection_request'`, the send-side code union) and was deliberately left untouched. `relationship_status` reuses the existing `DiscoveryRelationshipStatus`.

### D-d6 — post-action UX: toast naming the agency + row removal, no click-through

A `<v-snackbar>` keyed on `meta.code` (mirroring `DiscoverProfilePage`): accept → "You're now connected with {agency_name}." (names the agency); decline → "Request declined." (does not). On both, the actioned row leaves the list. **No click-through** — there is no creator-side connections surface to link to, so "row disappears + toast" is the honest v1 (the creator-side connections page is logged as a future surface — see Out of scope).

### D-d7 — refresh by re-fetching after a mutation

Accept/decline call `loadRequests()` after a successful POST (the `AvailabilityCalendar` `onMutated → load()` precedent) so the actioned row drops from the `pending_request` set and the list reflects server truth (also covering the stale-list edge cases). Component-local refs, **no global store**. A per-row `actioningId` drives the in-flight button loading state and disables the sibling actions during a mutation.

### The section itself

Header (`h2`), a light `v-list` (each row "agency_name" / "Sent {date}" + Accept/Decline buttons in the `#append` slot), [`CEmptyState`](../../packages/ui/src/components/CEmptyState.vue) with an `mdi-account-multiple-outline` icon slot for none (mirroring the `AvailabilityCalendar` empty-state usage), and a `v-skeleton-loader` on the initial fetch.

**Decline is a direct action (no confirm)** — your call per D-d3; decline is reversible via an agency re-request, matching the established direct-action pattern. Flagged here per the kickoff's "flag if you add a confirm" (I did not).

### i18n

New keys under **`creator.ui.dashboard.requests.*`** in **en/pt/it** (the existing non-code UI convention — NOT a new top-level prefix, so no architecture-test churn): `title`, `sent` / `sent_unknown`, `accept` / `decline`, `empty.{title,body}`, `toast.{accepted,declined,error}`.

---

## Flagged divergence: the Playwright seeder needed gated backend test infra (honest-deviation trigger #3 fired)

The Playwright round-trip needs an approved creator with a seeded `pending_request`. The inbox renders **only in the approved branch**, and **no production path approves a self-signed-up creator** (admin-only) or sends a request from an agency the spec controls — and the existing `creator-dashboard.spec.ts` only ever exercised the _incomplete_ branch (there was no approved-creator E2E path). So the seeder is genuinely more than a thin helper.

**Resolution (confirmed with the owner before building):** a net-new **gated `App\TestHelpers`** endpoint — [`CreatePendingConnectionRequestController`](../../apps/api/app/TestHelpers/Http/Controllers/CreatePendingConnectionRequestController.php) (`POST /_test/creators/pending-connection-request`) — that, given the creator's email, approves the creator + provisions an agency + a `pending_request` relation in one call (the same one-shot-provisioning pattern as `CreateRosterCreatorsController`). This is **test infra only** (token-gated by `VerifyTestHelperToken`, 404 when closed, no production wiring), so **D-d6's "no production backend change" still holds** — but it is, transparently, a backend file addition + its Pest gating test + a Playwright fixture. The other two backend-touch triggers did **not** fire: the `agency_id`-is-a-ULID quirk is bound correctly (no numeric assumption), and the section lives in the approved branch without restructuring.

---

## Coverage

| Area                          | Coverage                                                                                                                                                                                                                                                                                                                             |
| ----------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| **api-client wrapper (D-d4)** | [`connectionRequests.api.spec.ts`](../../apps/main/src/modules/creators/connectionRequests.api.spec.ts) — `list` GETs the creator-self path (no id/query); `accept`/`decline` POST the row ULID to the right path + verb.                                                                                                            |
| **List render (D-d1/d2)**     | [`CreatorDashboardPage.spec.ts`](../../apps/main/src/modules/creators/pages/CreatorDashboardPage.spec.ts) — rows render from a mocked api (agency name + "Sent" date); the section + fetch appear in the **approved** branch and **NOT** in pending/rejected/incomplete (asserted both the section absence AND `list` never called). |
| **Accept (D-d3/d6/d7)**       | accept → `accept('01R1')` → re-fetch (`list` called twice) → the row is gone + a `connection.accepted` toast naming the agency ("You're now connected with Alpha.").                                                                                                                                                                 |
| **Decline (D-d3/d6/d7)**      | decline → `decline('01R1')` → re-fetch → row gone + the "Request declined." toast.                                                                                                                                                                                                                                                   |
| **Empty state**               | `CEmptyState` (`data-test="dashboard-requests-empty"`) renders when no requests; the `v-list` does not.                                                                                                                                                                                                                              |
| **Error path**                | an error response surfaces the error toast and keeps the row (no re-fetch on failure, so the creator can retry).                                                                                                                                                                                                                     |
| **Backend test-helper**       | [`CreatePendingConnectionRequestTest`](../../apps/api/tests/Feature/TestHelpers/CreatePendingConnectionRequestTest.php) — approves the creator + seeds a `pending_request` in one call; defaults the agency name; 422s when no creator matches the email / the email is missing; **404 when the helper gate is closed**.             |
| **E2E round-trip**            | [`creator-connection-requests.spec.ts`](../../apps/main/playwright/specs/creator-connection-requests.spec.ts) — sign up via API helper → sign in through the SPA UI → seed a `pending_request` → land on `/creator/dashboard` → see the request naming the agency → accept → the row clears + the snackbar names the agency.         |

**Test runs (local):**

- **Frontend (Vitest):** full suite **764 passed (87 files)** — incl. the new `connectionRequests.api.spec.ts` (3) + the extended `CreatorDashboardPage.spec.ts`. `vue-tsc --noEmit` (main) + `tsc --noEmit` (api-client): **clean**. ESLint (main): clean (2 pre-existing `v-html` warnings in unrelated onboarding files only).
- **Backend (Pest):** `CreatePendingConnectionRequestTest` — **5 passed (16 assertions)**. PHPStan: **clean** (514 files). Pint: **clean**.
- **E2E (Playwright):** `creator-connection-requests.spec.ts` — **1 passed** (chromium, reusing the running dev servers; global-setup `migrate:fresh`).

---

## Spot-check anchors

- The list binds the pinned 6.6b shape — **flat `data`, no meta**; `id` = the relation ULID; **`agency_id` is a ULID** (no numeric assumption). ✓
- Accept/decline POST the **row's `id`** + re-fetch → the row drops. ✓
- The snackbar is keyed on `meta.code`; the connected toast **names the agency**. ✓
- The section renders in the **approved branch only** (and the fetch never fires otherwise). ✓
- **Net-new types** — NOT the agency `ConnectionRequestResponse`. ✓
- i18n under **`creator.ui.dashboard.requests.*`** (en/pt/it), no new top-level prefix. ✓
- `CEmptyState` for none. ✓
- The Playwright round-trip + its net-new **gated** `pending_request` seed helper. ✓

---

## Out of scope (logged at close)

- **The pending-count badge** on the creator nav → deferred nicety (no reusable badge primitive; would add the first always-on creator-side fetch) — logged in [`tech-debt.md`](../tech-debt.md).
- **A creator-side connections/roster page** (post-accept has no click-through destination) → future surface — logged in [`tech-debt.md`](../tech-debt.md).
- **A dedicated `/creator/requests` route.** No 6.6b backend touch-up. **Agency-notified-on-accept/decline** (deferred in 6.6b — the agency learns the outcome via the discovery annotation's status flip).

---

## Files touched

**Frontend**

- `packages/api-client/src/types/agency.ts` — net-new `ConnectionRequestListItem` / `ConnectionRequestListResponse` / `ConnectionRequestActionResponse` (D-d5; the agency `ConnectionRequestResponse` left untouched).
- `apps/main/src/modules/creators/connectionRequests.api.ts` — new creator-self wrapper (D-d4).
- `apps/main/src/modules/creators/connectionRequests.api.spec.ts` — new (path/verb pins).
- `apps/main/src/modules/creators/pages/CreatorDashboardPage.vue` — the approved-branch inbox section + accept/decline + snackbar + re-fetch (D-d1/d3/d6/d7).
- `apps/main/src/modules/creators/pages/CreatorDashboardPage.spec.ts` — extended (render / accept / decline / empty / approved-only gating / error toast).
- `apps/main/src/core/i18n/locales/{en,pt,it}/creator.json` — `creator.ui.dashboard.requests.*` keys.

**Backend (test infra only — D-d6 holds)**

- `apps/api/app/TestHelpers/Http/Controllers/CreatePendingConnectionRequestController.php` — new gated helper (approve creator + seed `pending_request`).
- `apps/api/app/TestHelpers/Routes/api.php` — the `_test/creators/pending-connection-request` route.
- `apps/api/tests/Feature/TestHelpers/CreatePendingConnectionRequestTest.php` — new (happy path + validation + gate-closed 404).

**E2E**

- `apps/main/playwright/fixtures/test-helpers.ts` — new `seedPendingConnectionRequest` fixture.
- `apps/main/playwright/specs/creator-connection-requests.spec.ts` — new round-trip spec.

**Docs**

- `docs/tech-debt.md` — the deferred pending-count badge + the creator-side connections page.
- `docs/reviews/sprint-6-6c-review.md` — this review.
- `services.md` — no change (per the kickoff).
