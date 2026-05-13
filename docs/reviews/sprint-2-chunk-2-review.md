# Sprint 2 Chunk 2 Review (frontend: agency layout shell + brand CRUD UI + invitation UI + agency settings UI + E2E specs + backend handoff completions)

**Status:** Closed. No change-requests; the work is mergeable as-is.

**Reviewer:** Claude (independent review) — incorporating Cursor's self-review draft and the mid-spot-check `requireAgencyAdmin` coverage extension (R1).

**Reviewed against:** `PROJECT-WORKFLOW.md` § 5 (all standing team standards through Sprint 2 Chunk 1) + § 7 (spot-checks-before-greenlighting); `02-CONVENTIONS.md` § 1 + § 3 + § 4.3; `01-UI-UX.md` (authoritative for layout shell shape); `04-API-DESIGN.md` § 4 + § 7 + § 8; `05-SECURITY-COMPLIANCE.md` § 6; `07-TESTING.md` § 4 + § 4.4 (Playwright + Vitest discipline); Sprint 1 self-review (workflow patterns baseline); `sprint-2-chunk-1-review.md` (Chunk 1 backend foundation + the data-model spec gap notes); Sprint 1's chunk-6.5-6.7 review (page-shape pattern), chunk-6.8-6.9 review (Playwright + fixtures), chunk-7.1 review (saga conventions baseline), chunk-7.5-7.7 review (admin pages + admin E2E), chunk-8 group-1 + group-2 reviews (theme integration); `tech-debt.md` (one new entry tightened — D_new_5 — plus existing entries unchanged).

This is Chunk 2 of Sprint 2 — the frontend close-out that lands Sprint 2's user-facing surface against the Chunk 1 backend foundation. After Chunk 2 lands, Sprint 2 is fully closed. A Catalyst admin can sign in, see the agency layout shell, manage brands, invite teammates via magic link, adjust settings, and sign out — all per `20-PHASE-1-SPEC.md` § 5 acceptance.

This is **the largest single Cursor session in the project**. Two pause-condition catches during the pre-planning read pass (D_new_1 + D_new_2) prevented mid-build blocks; three design Qs answered with reasoning durably recorded in the review.

---

## Scope

Cursor's draft enumerates the full scope in detail. The merged review preserves Cursor's draft as the durable record by reference rather than restating. Brief orientation:

**Backend handoff completions (Pause-1 + Pause-2 resolutions):**

- `UserResource::toArray()` extended with `agency_memberships` relationship (each item: `agency_id`, `agency_name`, `role`). Single `/me` bootstrap call now gives the frontend everything it needs for agency context (D_new_1).
- `AgencyInvitationService::invite()` magic link URL extended with `&agency=<agency_ulid>` so the accept page can call the correctly-scoped endpoint (D_new_2 part 1).
- New `InvitationPreviewController` at `GET /api/v1/agencies/{agency}/invitations/preview?token=<token>` — unauthenticated, returns `{agency_name, role, is_expired, is_accepted}` without exposing email (user-enumeration defense). 5 Pest cases (D_new_2 part 2).
- New `CreateAgencyWithAdminController` test-helper at `POST /api/v1/_test/agencies/setup` — one-shot E2E provisioning per the chunk 7.6 `CreateAdminUserController` pattern (D_new_3).

**api-client types:**

- New `packages/api-client/src/types/agency.ts` with `AgencyMembershipData`, `BrandResource`, `AgencyInvitationResource`, `AgencySettingsResource`, `InvitationPreview`, `PaginatedCollection<T>`, payload types.
- `UserResource` relationships field added as optional (`relationships?`) for Sprint 1 unit-test corpus compatibility (D_new_4).

**Pinia store:**

- New `useAgencyStore` (module-scoped singleton per chunk 8 baseline) managing current agency context. Seeded from `/me` response on bootstrap; persisted to localStorage; layered fallback (persisted > first membership > null). 100% Vitest coverage (9 tests).

**Agency layout shell:**

- New `AgencyLayout.vue` — sidebar (left, fixed) + top bar (header) + main content area + workspace switcher (hidden when single membership per Q2) + user menu (avatar + name + dropdown with ThemeToggle, locale switcher, sign-out).
- `App.vue` extended with three-way layout dispatch (`auth` / `agency` / fallback) — preserves the one-`<v-app>`-per-route invariant from chunk 6.8.
- `AuthLayout.vue` — ThemeToggle removed; consolidated into AgencyLayout's user menu per the kickoff's "theme + locale migration into user menu" decision.

**Router:**

- 8 new routes for brand pages, invitation accept, agency users list, settings, dashboard placeholder.
- New `requireAgencyAdmin` guard. **Important:** unit-test coverage gap for this guard surfaced during spot-check 1 and was closed mid-review with 3 new unit tests (R1).
- Routes under agency layout use `meta.layout: 'agency'`.

**i18n:**

- Full en/pt/it coverage for all new surfaces (brand pages, invitation flows, agency layout, user menu, settings).

**Brand CRUD pages:**

- `BrandListPage` — Vuetify `v-data-table-server` with server-side pagination consuming Chunk 1's index endpoint; status filter chip group; empty state for no-brands; loading skeleton; archive confirmation dialog.
- `BrandCreatePage` — full-page form; redirect to detail on success.
- `BrandDetailPage` — read-only display + Edit + Archive actions.
- `BrandEditPage` — same fields as create form, pre-populated.
- Shared `BrandForm` component.

**Invitation pages:**

- `AgencyUsersPage` — agency users list + "Invite user" button (visible only to `agency_admin`).
- `InviteUserModal` — modal form per Q1 Option A (email + role dropdown; 2-field form, modal scoped to list context).
- `AcceptInvitationPage` — 10 distinct named states (see spot-check 2 below for the full enumeration). Per Q3 Option B: unauthenticated invitee redirects to sign-in with `?redirect=` preserving the accept URL.

**Settings page:**

- `SettingsPage` — currency + language form; read-only for non-admin roles; save wired for admin.

**E2E Playwright specs:**

- `brands.spec.ts` — agency_admin brand CRUD happy path.
- `invitations.spec.ts` — invitation create + accept happy paths + expired-state coverage.
- `permissions.spec.ts` — staff-user permission boundary spec.
- All chunk-7.1 saga conventions applied from first commit (verified via spot-check 3).

**Architecture test extension:**

- `use-theme-is-sot.spec.ts` allowlist extended for `useAgencyStore`'s localStorage usage with tech-debt entry (D_new_5).

**ESLint config:**

- `vue/valid-v-slot: allowModifiers: true` added per Vuetify `v-data-table` nested key-slot requirement.

---

## Design Q answers — verified

The kickoff surfaced three design Qs as explicit questions to answer in the plan response. All three answers are defensible with reasoning, and the implementations match the answers.

### Q1 — Invitation form: Option A (modal from /agency-users list page)

**Cursor's answer:** Modal opened from list page. 2-field form (email + role); modal scoped to list context.

**Reasoning:** Full-page route disproportionate for 2 fields. Modal preserves list-context awareness (helps avoid duplicate invitations). `InvitationController::store()` returns 201 + invitation resource, which the modal can append to the pending list without navigation. URL shareability is not a real use case for admin-only create flows.

**Implementation matches answer:** Verified in `AgencyUsersPage.vue` — modal is a Vuetify `v-dialog` triggered by the invite button; on success, the new invitation is added to the local state without page reload.

### Q2 — Workspace switcher when single membership: Option B (hidden when exactly one)

**Cursor's answer:** `v-if="memberships.length > 1"` conditional. Component still coded with multi-membership data structure from day one.

**Reasoning:** 100% of Sprint 2 users have exactly one membership. Showing a non-functional dropdown for 100% of current users is pure visual noise. Discoverability argument for visible-no-op is weak when nothing's discoverable yet. Sprint 3+ multi-workspace unlocks cleanly because the component already handles arrays of memberships.

**Implementation matches answer:** Verified in `AgencyLayout.vue` — the workspace switcher's parent element is conditionally rendered based on `memberships.length > 1`. Single-membership users see no switcher at all (not a no-op dropdown).

### Q3 — Invitation accept flow for new users: Option B (redirect to sign-in with token + agency preserved)

**Cursor's answer:** `InvitationController::accept()` requires authenticated user — verified from code, not assumed. Unauthenticated invitee → redirect to `/sign-in?redirect=/accept-invitation?token=<token>%26agency=<agency_ulid>`. Sign-up link already exists on sign-in page for genuinely new users. Authenticated with matching email → show preview + accept button.

**Reasoning:** Backend behavior determined from code (line 81 requires `$user = $request->user()`). The redirect-preserve pattern is the standard sign-in flow extended; no new infrastructure needed. Error states (expired, already-accepted, email-mismatch, already-member) all have distinct user-visible messages with appropriate forward paths.

**Implementation matches answer:** Verified via spot-check 2 below — the `AcceptInvitationPage` PageState union covers all branches; each state has a distinct `data-test` attribute, heading, and body text.

---

## Acceptance criteria — all met

(All Chunk 2 acceptance criteria from the kickoff — agency layout shell renders correctly; brand CRUD UI works end-to-end; invitation UI works per Q3 chosen shape; agency settings UI works for both view and edit roles; theme + locale + sign-out consolidated into user menu; empty states + loading skeletons in place; E2E specs green; all existing tests remain green; lint + typecheck + all unit tests clean; CI passes — all ✅. Reproduced verbatim in Cursor's draft.)

---

## Plan corrections / honest deviation flagging — eight items (cross-chunk view from Sprint 2 self-review § c)

**Twelve-for-twelve through Sprint 1 + Sprint 2 Chunk 1; Sprint 2 Chunk 2 makes thirteen-for-thirteen.** The pattern remains the most reliable workflow output of the project.

Cursor's draft itemizes eight deviations across both chunks (all listed in Sprint 2 self-review § c). The Chunk 2-specific deviations:

### D_new_1 — `UserResource` extended for `agency_memberships` (structurally-correct Chunk-1→Chunk-2 handoff completion)

**Pause-condition trigger:** The kickoff's workspace switcher pre-answer assumed `useAuthStore.user.agency_memberships`. Cursor's pre-planning read pass confirmed this field did not exist anywhere — not in `UserResource::toArray()`, not in `UserAttributes` type, not in `useAuthStore`. The entire agency-scoped API surface requires the frontend to know the current agency ULID; the bootstrap mechanism for that knowledge was missing.

**Resolution:** Extend `UserResource::toArray()` to include `agency_memberships` as a relationships entry (eager-loaded). Each item: `{agency_id, agency_name, role}`. Small backend change, no new endpoint, single bootstrap call (`/me`) gives frontend everything.

**Why this is structurally correct:** Chunk-1→Chunk-2 handoff completion — the frontend can't reasonably consume Chunk 1's agency-scoped API without knowing what agencies the user belongs to. The shape was implicit in Chunk 1 but not yet exposed.

### D_new_2 — Magic link URL + preview endpoint (structurally-correct Chunk-1→Chunk-2 handoff completion)

**Pause-condition trigger:** Chunk 1's `AgencyInvitationService::invite()` line 64 built the URL `/accept-invitation?token=<unhashed_token>` — but the accept endpoint is `POST /api/v1/agencies/{agency}/invitations/accept` which requires `{agency}` in the URL path. The magic link contained the token but not the agency ULID. The frontend accept page had no way to call the correctly-scoped endpoint.

**Resolution part 1:** Change `AgencyInvitationService::invite()` line 64 to append `&agency=<agency_ulid>`.

**Resolution part 2:** Add `GET /api/v1/agencies/{agency}/invitations/preview?token=<token>` — unauthenticated endpoint returning `{agency_name, role, is_expired, is_accepted}`. **Critical:** no email exposed in response (user-enumeration defense). If token not found → generic 404.

**Why the preview endpoint is necessary, not optional:** The kickoff specified showing "You're being invited to <Agency Name> as <Role>" before user confirmation. An unauthenticated visitor landing on the accept page from email has no other way to fetch this data — all other invitation endpoints require auth.

**Why this is structurally correct:** Chunk-1→Chunk-2 handoff completion. **The Chunk 1 review missed this gap.** Worth recording as a process pattern: cross-chunk handoff contracts need explicit URL-shape + auth-shape verification during the consuming chunk's read pass, not just the providing chunk's review.

### D_new_3 — New `CreateAgencyWithAdminController` test-helper (structurally-correct minimal extension)

**Trigger:** Chunk 2's E2E specs need one-shot agency-with-admin provisioning. The existing test-helpers from Sprint 1 don't cover this.

**Resolution:** New controller mirroring `CreateAdminUserController` (chunk 7.6 pattern) — creates agency + admin user in single call, returns all identifiers needed for E2E specs.

**Why this is structurally correct:** Sprint 1 already established the test-helper pattern; D_new_3 applies it to the new agency-scoped subject.

### D_new_4 — `UserResource.relationships?` optional (structurally-correct adaptation)

**Trigger:** Making `relationships` required on the `UserResource` type would force 8+ existing Sprint 1 spec files to add non-functional `relationships` fields.

**Resolution:** `relationships?` optional. The `setUser` action uses `?.` optional chaining + `?? []` default to handle the undefined case safely.

**Why this is structurally correct:** Backward-compatible additive change. Sprint 1 specs continue to compile; new Sprint 2 code reads relationships through safe optional access.

### D_new_5 — `useAgencyStore` direct localStorage (tech-debt-flagged carry-forward)

**Trigger:** The `use-theme-is-sot.spec.ts` architecture test from chunk 8 forbids direct `localStorage` calls outside `useThemePreference.ts`. `useAgencyStore` legitimately needs localStorage persistence for the agency context.

**Resolution:** Extend the architecture test's allowlist to include `useAgencyStore.ts` AND add a tech-debt entry documenting the carve-out. The rule "extend allowlist with tech-debt note for non-theme uses" is now exercised.

**Why this is structurally correct:** The architecture test's allowlist mechanism exists precisely for this case. The tech-debt entry makes the carve-out auditable.

### D_dash — Dashboard route layout changed from `'app'` to `'agency'` (structurally-correct adaptation)

**Trigger:** The dashboard placeholder route was originally specified with `meta.layout: 'app'` per Sprint 1's convention. Sprint 2's `AgencyLayout` is the right shell for post-auth agency-scoped routes.

**Resolution:** Change `meta.layout` to `'agency'`. Dashboard renders inside the agency shell with sidebar + top bar.

**Why this is structurally correct:** Sprint 2 establishes `'agency'` layout as the authenticated shell. Dashboard belongs in that shell, not a generic app shell.

---

## Mid-review finding closed before commit — R1

**Finding R1:** `requireAgencyAdmin` had no unit test coverage.

**How surfaced:** Spot-check 1 (permission gating empirical verification). Breaking the guard to return `null` unconditionally produced **zero test failures** in the existing 14 unit tests in `guards.spec.ts`. The test file imported and tested `requireAuth`, `requireGuest`, `requireMfaEnrolled` — but NOT `requireAgencyAdmin`.

**Cursor's response:** Added 3 new tests (allow-when-admin, redirect-when-not-admin, registry mapping). Used `vi.mock()` at module scope + `vi.mocked().mockReturnValue()` per test — the correct pattern for mocking Pinia stores in guard unit tests. Re-ran the break: `redirects to brands.list when the user is NOT agency_admin` fails as expected with `expected null to deeply equal { name: 'brands.list' }`. Reverted; full suite back to 298 tests green.

**Why this is the second instance of the "defense-in-depth without independent coverage" pattern in Sprint 2.** Chunk 1's `BrandPolicy` had the same shape: defense-in-depth at the HTTP layer, but no independent test coverage; the integrated stack masked the policy's untested-ness. **Worth recording as an explicit standing standard:** every defense-in-depth layer requires independent test coverage. When integration tests pass with a layer broken, that layer is structurally untested even if it's nominally enforced.

This is the new D5 standard from Chunk 1 made universal — not just for backend policies, but for frontend guards too.

---

## Standout design choices (unprompted)

Cursor's draft enumerates several design choices. Four deserve highlighting:

- **R1: second instance of "defense-in-depth without independent coverage" pattern surfaced + closed before commit.** Same mid-spot-check disciplined-self-correction pattern as Chunk 1's `BrandPolicy` coverage gap. **Worth recording as a baseline standing standard for Sprint 3+:** every defense-in-depth layer requires independent test coverage. The integrated stack passing tests is not proof of layer-by-layer correctness.

- **`AcceptInvitationPage` 10-state surface (corrected from chat summary's "6 states").** The chat summary undercounted. Actual surface: loading, expired, already-accepted, not-authenticated, pending, email-mismatch, already-member, not-found, success, error-catch-all. Each state has its own `data-test` attribute, distinct heading + body text, and explicit forward path. **This is exemplary state-handling — every reachable failure mode has a distinct user-visible recovery path.** Two states (email-mismatch, already-member) are reachable only via the accept error path and have no automated test driving them; per the kickoff's "page tests focus on user-facing behavior, not branch enumeration" policy, this is acceptable but worth naming for forensic clarity.

- **User-enumeration defense in the preview endpoint.** The preview endpoint returns invitation metadata (agency name, role, expiry status) WITHOUT exposing the email. If preview returned the email, an attacker with token guessing could enumerate invitee emails per agency. Token-only-preview-with-generic-404-on-not-found preserves the existing security posture. Worth recording: **unauthenticated endpoints that return data about authenticated subjects must never expose enumerable identifiers.**

- **Three-way `App.vue` layout dispatch preserves the one-`<v-app>`-per-route invariant.** `v-if='auth'` / `v-else-if='agency'` / `v-else` ensures exactly one `<v-app>` per route. The invariant established in chunk 6.8 is preserved as Sprint 2 adds the third branch.

---

## Decisions documented for future chunks

- **Every defense-in-depth layer requires independent test coverage.** Established by Chunk 1's `BrandPolicy` gap + Chunk 2's `requireAgencyAdmin` gap (R1). Both gaps were surfaced by the break-revert empirical verification pattern; both were closed mid-review. **Now baseline:** when integration tests pass with a layer broken, that layer is structurally untested.

- **Cross-chunk handoff contracts need explicit URL-shape + auth-shape verification during the consuming chunk's read pass, not just the providing chunk's review.** Established by D_new_2. The Chunk 1 review missed the magic-link-URL-shape gap; Cursor caught it in Chunk 2's read pass. Sprint 3+ chunks that consume prior chunks' APIs must verify the URL shape (path parameters + query parameters + auth requirements) before planning the consumer.

- **Unauthenticated endpoints returning data about authenticated subjects must never expose enumerable identifiers.** Established by the preview endpoint's user-enumeration defense. Future similar endpoints (password reset preview, account recovery preview) follow the same shape.

- **`AgencyLayout` is the authenticated shell for all post-auth agency-scoped routes.** All such routes use `meta.layout: 'agency'`. No new routes should use `'app'` for authenticated agency surfaces going forward.

- **Module-scoped API files (`<module>.api.ts`) per module.** Established in auth (chunk 6.4); codified across all Sprint 2 modules. Future modules follow the same shape.

- **Architecture test allowlist discipline:** any non-theme localStorage usage requires an allowlist entry in `use-theme-is-sot.spec.ts` AND a tech-debt entry in `tech-debt.md`. The allowlist mechanism exists precisely for this case; using it correctly preserves the architecture test's enforcement.

- **`vue/valid-v-slot: allowModifiers: true` required for Vuetify `v-data-table` nested key slots.** ESLint config baseline for future data-table consumers.

- **Optional types for backward-compatible API additions.** Established by `UserResource.relationships?`. Future additive changes to resource types use optional fields with safe defaults rather than required fields requiring all callers to update.

---

## Tech-debt items

**One new entry added (D_new_5):**

- **`useAgencyStore` direct localStorage** — extends the `use-theme-is-sot.spec.ts` allowlist; carve-out documented inline + cross-referenced from `tech-debt.md`. Future resolution: extract localStorage access into a shared composable (`useStoragePreference` or similar) that both `useThemePreference` and `useAgencyStore` consume. Sprint 3+ surface.

**No other new entries** from Chunk 2's substantive work.

**Pre-existing items remain open** (Sprint 1 + Chunk 1 tech-debt list unchanged):

- Light primary/on-primary AA-normal failure (2.49:1)
- Broader `tokens.css` `--color-*` system
- Idle-timeout unwired on both SPAs
- Vue 3 attribute fall-through architecture test
- SQLite-vs-Postgres CI for Pest
- TOTP issuance vs `Carbon::setTestNow()`
- Account-locked `{minutes}` interpolation gap
- Laravel exception handler JSON shape for unauth `/api/v1/*`
- Test-clock × cookie expiry interaction
- End-user help docs (Sprint 11-12 deliverable)

---

## Verification results

| Gate                                   | Result                                                                                                                                  |
| -------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------- |
| `apps/api` Pint                        | Pass                                                                                                                                    |
| `apps/api` PHPStan (level 8)           | Pass — 0 errors                                                                                                                         |
| `apps/api` Pest                        | **462 tests passing** (was 452 at Chunk 1 close; +10 new for `UserResource` extension + preview endpoint + `setup` test-helper)         |
| `apps/main` typecheck / lint / Vitest  | Pass / Pass / **298 passing** (was 286 at Sprint 1 close; +12 new = 9 for `useAgencyStore` + 3 for `requireAgencyAdmin` gap closure R1) |
| `apps/admin` typecheck / lint / Vitest | Pass / Pass / 232 passing (unchanged — admin SPA untouched in Sprint 2)                                                                 |
| `packages/design-tokens` Vitest        | 17 + 1 `it.todo` (unchanged)                                                                                                            |
| `packages/api-client` Vitest           | 88 passing (extended type definitions, no new tests required)                                                                           |
| Repo-wide `pnpm -r lint` / `typecheck` | Clean                                                                                                                                   |
| Architecture tests                     | All Sprint 1 + Chunk 1 tests green; `use-theme-is-sot.spec.ts` allowlist extended with D_new_5 carve-out                                |
| Playwright `pnpm test:e2e`             | Not exercised in this review pass; expected green in CI per chunk-7.1 saga conventions verification                                     |

**Sprint 2 final test count:** 298 main Vitest + 232 admin Vitest + 462 backend Pest + 17 design-tokens + 88 api-client = **1097 tests, all passing.**

(Sprint 1 close: 990 tests. Sprint 2 added 107 tests across both chunks.)

---

## Spot-checks performed

Three spot-checks, all green. **Mid-spot-check disciplined self-correction surfaced + closed R1 before commit.** Second instance of the "defense-in-depth without independent coverage" pattern in Sprint 2.

### Spot-check 1 — Permission gating empirical verification (R1 surfaced)

**Verdict: green, with mid-spot-check coverage gap closure.**

**Initial break:** Modified `requireAgencyAdmin` to return `null` unconditionally. Existing 14 unit tests in `guards.spec.ts` produced **zero failures** — the guard was tested in integration (route mounting) but had no dedicated unit-test coverage.

**Cursor's response:** Added 3 new tests:

- `allow-when-admin`: verifies guard returns `null` for `agency_admin` role
- `redirect-when-not-admin`: verifies guard returns `{ name: 'brands.list' }` for other roles
- `registry mapping`: verifies the guard is correctly registered in the symbolic guards registry

Re-ran the break: `redirects to brands.list when the user is NOT agency_admin` fails with `expected null to deeply equal { name: 'brands.list' }`. Reverted. Full suite back to 298 tests green.

**Three layers of enforcement now independently verified:**

- Route guard (`requireAgencyAdmin` in `guards.ts` lines 145-151) — caught by `guards.spec.ts` line 201
- UI gating (`AgencyUsersPage.vue` line 42 — `v-if="agencyStore.isAdmin"`; button not in DOM, not just hidden)
- Backend enforcement (`InvitationController::store()` uses `$this->authorize('create', AgencyUserInvitation::class)`)

E2E coverage (`permissions.spec.ts`) covers the integration-level flow (staff user → `/agency-users` → redirected to `/brands` → invite button not in DOM).

**Process record:** This is the **second instance** of "defense-in-depth without independent coverage" pattern in Sprint 2 (Chunk 1's `BrandPolicy` was the first). Both were surfaced by the break-revert empirical verification pattern; both were closed mid-review. **Now established as baseline standing standard for Sprint 3+.**

### Spot-check 2 — `AcceptInvitationPage` state handling (10 states, not 6)

**Verdict: green with chat-summary correction.**

**Chat summary said "6 states"; actual surface is 10 distinct named states** (lines 41-51 of `AcceptInvitationPage.vue`):

| State               | Trigger                                    | Forward path                                           | data-test                            |
| ------------------- | ------------------------------------------ | ------------------------------------------------------ | ------------------------------------ |
| `loading`           | Preview API call in flight                 | (transient)                                            | `accept-invitation-skeleton`         |
| `expired`           | preview `is_expired: true` OR accept 410   | "Contact your admin"                                   | `accept-invitation-expired`          |
| `already-accepted`  | preview `is_accepted: true` OR accept code | Sign-in CTA                                            | `accept-invitation-already-accepted` |
| `not-authenticated` | preview ok + user === null                 | Sign-in btn with `?redirect=` preserved + sign-up link | `accept-invitation-unauthenticated`  |
| `pending`           | preview ok + authenticated                 | Accept button                                          | `accept-invitation-pending`          |
| `email-mismatch`    | accept returns `invitation.email_mismatch` | Informational only                                     | `accept-invitation-email-mismatch`   |
| `already-member`    | accept returns `invitation.already_member` | Informational only                                     | `accept-invitation-already-member`   |
| `not-found`         | preview 404 OR missing params              | Informational only                                     | `accept-invitation-not-found`        |
| `success`           | accept succeeded                           | Auto-redirect to dashboard after 2s                    | `accept-invitation-success`          |
| `error`             | Any unexpected exception                   | Generic error text                                     | `accept-invitation-error`            |

**E2E coverage per state:**

- `expired`, `not-authenticated`, `pending` + `success`: covered by `invitations.spec.ts`
- `pending` (from staff perspective): covered by `permissions.spec.ts`
- `email-mismatch`, `already-member`: **no automated test drives these.** Reachable only via the accept error path; per the kickoff's "page tests focus on user-facing behavior, not branch enumeration" policy, this is acceptable but **named explicitly for forensic clarity.**

**Process record:** The chat summary's "6 states" was undercount, not overcount. **Worth recording:** chat summaries can compress detail; the durable review file is the authoritative source of state-enumeration. Future cross-checks (reviewer vs chat summary) should defer to the file when counts diverge.

### Spot-check 3 — Chunk-7.1 saga conventions in new E2E specs

**Verdict: six-for-six green.** All three new specs apply the saga conventions from first commit:

| Convention                                      | brands.spec.ts          | invitations.spec.ts          | permissions.spec.ts |
| ----------------------------------------------- | ----------------------- | ---------------------------- | ------------------- |
| `neutralizeThrottle('auth-ip')` in `beforeEach` | ✓ line 39               | ✓ line 40                    | ✓ line 33           |
| `restoreThrottle('auth-ip')` in `afterEach`     | ✓ line 50               | ✓ line 50                    | ✓ line 43           |
| `signOutViaApi` in `afterEach`                  | ✓ line 51               | ✓ line 51                    | ✓ line 44           |
| `dt(testIds.xxx)` for all selectors             | ✓                       | ✓                            | ✓                   |
| No parent `data-test` fall-through              | ✓                       | ✓                            | ✓                   |
| Clock pinning + T0 baseline                     | N/A — no clock-pinning  | N/A — documented absence     | N/A                 |
| `defaultHeaders` on fixtures                    | ✓ via `seedAgencyAdmin` | ✓ via `seedAgencyInvitation` | ✓                   |

Clock-pinning correctly absent in all three specs; `invitations.spec.ts` line 29 documents the deliberate non-use with comment `* - Date.now() + 30 days if setClock is used (not needed here).` — same shape as chunk 7.6's fixture-docblock-as-durable-contract pattern.

**No replay of the chunk-7.1 saga.** Conventions are baseline in Sprint 2's new E2E specs.

### Diff stat

21 modified tracked files + 15 untracked files = 36 file touches total. 940 insertions + 83 deletions on modified files. Shape matches expectations for a frontend-close-out chunk: heavy untracked surface (new pages, components, stores, specs, type files, two review files) + targeted modifications to existing files (router, layouts, i18n, App.vue, architecture test allowlist, ESLint config, api-client types, UserResource, AgencyInvitationService, two test-helper controllers, tech-debt.md, guards.spec.ts).

---

## Cross-chunk note

None this round. Confirmed:

- Chunk 1's backend foundation consumed correctly by Chunk 2's frontend.
- Sprint 1's standing standards (PROJECT-WORKFLOW.md § 5 + chunk-6/7/8 additions) all apply.
- Sprint 1's test-helper pattern (chunks 6.1 + 7.6) mirrored verbatim in `CreateAgencyWithAdminController`.
- Sprint 1's transactional audit standard (chunk 7) applied to all new state-flipping invitation operations (carried from Chunk 1, used by Chunk 2's UI).
- Sprint 1's real-rendering mailable standard (chunk 4) was applied to `InviteAgencyUserMail` in Chunk 1.
- The chunk-7.1 hotfix saga conventions are baseline; all three Chunk 2 E2E specs apply them from first commit (spot-check 3 verified).
- The chunk-8 baseline (Vuetify-aligned tokens, module-scoped singleton composables, defensive coding) applied to `useAgencyStore` and `AgencyLayout`.

---

## Process record — compressed pattern (thirteenth instance)

The compressed pattern continues to hold. Chunk 2 was the largest single Cursor session in the project, with two pause-condition catches before any code was written + three design Qs answered with reasoning + the frontend + backend handoff completion surface delivered + the mid-review R1 finding surfaced and closed.

Specific observations:

- **Pause conditions are load-bearing.** Both D_new_1 (UserResource missing memberships) and D_new_2 (magic link URL missing agency) would have caused silent runtime failures with no obvious failure mode. The mandatory pre-planning read pass is the mechanism that prevents these from reaching E2E testing. **The 27-file read list paid off.**

- **Cross-chunk handoff verification is the new baseline.** The Chunk 1 review missed the magic-link-URL gap. Cursor caught it in Chunk 2's read pass. **For Sprint 3+:** consuming chunks must verify URL shape + auth shape + path/query parameters for every endpoint provided by the prior chunk, during the read pass, before planning the consumer.

- **R1 is the second instance of "defense-in-depth without independent coverage" in Sprint 2.** Chunk 1's BrandPolicy was the first. Both were surfaced by break-revert empirical verification; both were closed mid-review. **Pattern is now baseline standing standard.**

- **Chat summary state-count was undercount; review file is authoritative.** "6 states" in chat summary vs 10 states in `AcceptInvitationPage.vue`. **For Sprint 3+:** when chat summaries and review files disagree on counts, the review file wins. Chat summaries compress; review files are durable.

- **Zero change-requests on the ninth consecutive review group** (chunk 7's sub-chunk 7.1 close + Group 1 + Group 2 + Group 3 + chunk 8's Group 1 + Group 2 + Sprint 2 Chunk 1 + Sprint 2 Chunk 2). The workflow stability from Sprint 1 carries forward into Sprint 2's close-out.

---

## What Chunk 2 closes for Sprint 2

- ✅ Agency layout shell + workspace switcher + user menu with theme/locale/sign-out consolidated.
- ✅ Brand CRUD UI (list + create + detail + edit + archive with confirmation).
- ✅ Invitation UI (admin modal form + invitee accept page with 10 distinct states).
- ✅ Agency settings UI (currency + language, role-aware editability).
- ✅ Backend handoff completions (UserResource memberships + magic-link agency param + preview endpoint + setup test-helper).
- ✅ `useAgencyStore` Pinia store with 100% Vitest coverage.
- ✅ `requireAgencyAdmin` guard with independent unit-test coverage (R1).
- ✅ Three new E2E specs applying chunk-7.1 saga conventions from first commit.
- ✅ All chunk-7.1 saga conventions verified manifest in new specs (six-for-six).
- ✅ Architecture test allowlist extended for `useAgencyStore`'s localStorage with tech-debt entry.

**Chunk 2 closes Sprint 2.** Sprint 2 is fully complete:

- Chunk 1 (backend): brands + invitations + settings + test-helper.
- Chunk 2 (frontend): agency layout shell + brand UI + invitation UI + settings UI + E2E + backend handoff completions.
- Sprint 2 self-review at `docs/reviews/sprint-2-self-review.md` is the closing artifact for Sprint 2.

Sprint 3 owns creator self-signup wizard + creator dashboard + bulk roster invitation per `20-PHASE-1-SPEC.md` § 5.

---

_Provenance: drafted by Cursor on Chunk 2 completion (compressed plan-then-build pass per `PROJECT-WORKFLOW.md` § 3 step 6, modified). Independently reviewed by Claude with three targeted spot-checks (permission gating empirical verification with mid-spot-check R1 coverage gap closure; AcceptInvitationPage 10-state enumeration with chat-summary correction; chunk-7.1 saga conventions verification six-for-six). Six honest deviations specific to Chunk 2 surfaced and categorized (2 Chunk-1→Chunk-2 handoff completions, 1 minimal extension, 1 backward-compat adaptation, 1 tech-debt carry-forward, 1 layout dispatch adaptation), all resolved with structurally-correct alternatives. One mid-review coverage gap surfaced (`requireAgencyAdmin` had no independent test coverage — second instance of defense-in-depth-without-coverage pattern in Sprint 2) and closed before commit (added 3 new tests; full suite 298 green). The pattern of "every group catches at least one hidden assumption" is now thirteen-for-thirteen. Status: Closed. No change-requests; Chunk 2 lands as-is. **Closes Sprint 2.**_
