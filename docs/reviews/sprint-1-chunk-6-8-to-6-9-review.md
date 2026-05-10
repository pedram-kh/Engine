# Sprint 1 — Chunks 6.8 → 6.9 Review (Playwright E2E + auth module README + final chunk-6 self-review)

**Status:** Closed.
**Reviewer:** Claude (independent review) — incorporating Cursor's self-review draft.

**Reviewed against:** `PROJECT-WORKFLOW.md` § 5 (all 11 team standards), `02-CONVENTIONS.md` § 1 + § 3 + § 4 (note: § 4.3 coverage thresholds explicitly do NOT apply to Playwright E2E per kickoff Q4), `01-UI-UX.md` (the surface Playwright drives), `04-API-DESIGN.md` § 4 + § 7 + § 8 (envelope shapes E2E asserts against), `05-SECURITY-COMPLIANCE.md` § 6 (lockout thresholds, 2FA enrollment requirements), `07-TESTING.md` § 4 + § 4.4 (Playwright config patterns, hermetic-test discipline, fixture conventions), `20-PHASE-1-SPEC.md` § 7 (E2E priorities #19 + #20), `security/tenancy.md`, `feature-flags.md`, `tech-debt.md`, `reviews/sprint-1-chunk-6-plan-approved.md`, `reviews/sprint-1-chunk-6-1-review.md` (test-helpers contract — directly exercised by 6.8), `reviews/sprint-1-chunk-6-2-to-6-4-review.md` (data layer — invariants preserved), `reviews/sprint-1-chunk-6-5-to-6-7-review.md` (most-direct precedent).

This is the final group of chunk 6. After this review closes, chunk 6 is closed.

---

## Scope

### 6.8 — Playwright E2E specs + supporting infrastructure

**Configuration + global setup**

- **`apps/main/playwright.config.ts`** — `testDir: ./playwright/specs`, `fullyParallel: false`, `workers: 1` (the `_test` surface is process-global), `retries: 2` in CI, `trace`/`video`/`screenshot: retain-on-failure`, `extraHTTPHeaders: { 'X-Test-Helper-Token': TEST_HELPERS_TOKEN }`, single `chromium` project (firefox + webkit deferred — kickoff allowed but they aren't free), two `webServer` entries (Laravel API with `CACHE_STORE: 'array'` envelope; Vite SPA), `reuseExistingServer: !process.env.CI`. The API health probe targets `/api/v1/_test/clock/reset` — a 405 from the POST-only route proves both that Laravel routes the request AND that the chunk-6.1 gate is open (closed gate returns bare 404).
- **`apps/main/playwright/global-setup.ts`** — fails loudly with a runbook-shaped message if `TEST_HELPERS_TOKEN` is absent or empty, then runs `php artisan migrate:fresh --force` against `apps/api`.

**Selector + fixture infrastructure**

- **`apps/main/playwright/helpers/selectors.ts`** — single source of truth for `data-test` attribute names. `testIds` const + `dt(id)` helper. Specs import named values; a renamed selector fails the test compile, not the test runtime.
- **`apps/main/playwright/fixtures/test-helpers.ts`** — six typed wrappers: `signUpUser`, `mintTotpCodeForEmail`, `mintVerificationToken`, `setClock`, `resetClock`, `signOutViaApi`. Every wrapper throws on non-2xx so a misconfigured spec fails loudly instead of silently asserting against `undefined`.

**Specs**

- **`2fa-enrollment-and-sign-in.spec.ts`** (spec #19) — full enrollment + re-sign-in flow. Mints TOTP via `mintTotpCodeForEmail` (no QR parsing). The 5-second countdown wait flows through `expect(...).toBeEnabled({ timeout: 8_000 })` polling assertion, NOT `page.waitForTimeout(5000)`. Sign-out via API fixture (chunk 7 owns the nav surface UI; OQ-5 resolution trigger documented).
- **`failed-login-lockout-and-reset.spec.ts`** (spec #20) — short-window lockout, fast-forward unlock, long-window escalation. Clock anchored at deterministic `T0 = 2026-05-10T09:00:00Z` for reproducibility. Asserts on i18n-resolved substrings of the bundle's actual output (OQ-6 documents the absent `{minutes}` interpolation).
- **`smoke.spec.ts`** — relocated from `apps/main/tests/e2e/` (legacy Sprint-0 location) and rewritten for the layout-switcher reality. Asserts that the SPA boots, Vue Router routes, the layout switcher dispatches, and `AuthLayout` mounts — three sanity checks in one assertion.

**Backend additions in support of E2E**

- **`apps/api/app/TestHelpers/Http/Controllers/IssueTotpController.php`** — extended to accept `email` as an alternative to `user_id`. New `resolveUser()` private helper centralises failure envelopes. Three new test cases pin the email branch (success / normalisation / 404) plus one for the "neither supplied → 422" path. Original `user_id` tests unchanged.

**App shell prerequisite (latent Sprint-0 bug fixed)**

- **`apps/main/src/App.vue`** — refactored from a Sprint-0 placeholder (which never mounted `<router-view />`) into a layout switcher driven by `route.meta.layout`. Conditional `v-if`/`v-else` ensures only one `<v-app>` mounts per route. `App.spec.ts` rewritten to assert the dispatch with three synthetic stubs and a memory-history router.

**Workspace + CI wiring**

- **`apps/main/vitest.config.ts`** — exclusion list extended to `playwright/**` so Vitest doesn't try to execute `@playwright/test` files.
- **`.github/workflows/ci.yml`** — `e2e` split into `e2e-main` (full Postgres + Redis + Laravel + per-run `openssl rand -hex 32` token) and `e2e-admin` (thin smoke until chunk 7). The `e2e-main` job closes the chunk-6.1 deferral: token generated once, exported to `$GITHUB_ENV`, both API server and Playwright runner read the same value. `CACHE_STORE: 'array'` set on the job env (the OQ-3 hermeticity contract).

### 6.9 — Auth module README + this review file (the chunk-6 closing artifact)

- **`apps/main/src/modules/auth/README.md`** — ~440 words across three sections (where to start / architecture tests / recurring patterns). Audience is a future contributor; explicitly assumes the reader has read the spec docs. Not a tutorial.
- **This review file** — single completion artifact for the 6.8 + 6.9 group AND the closing artifact for the entire chunk 6.

---

## Acceptance criteria — all met

(16 criteria from Cursor's draft acceptance table — all ✅. Reproduced verbatim in Cursor's draft for the durable record; merged review preserves the same line-by-line verdicts. Verification gates summarized in §"Verification results" below.)

---

## Plan corrections / honest deviation flagging — five items

This is the **fourth instance** in chunk 6 of Cursor flagging where the kickoff carried a hidden assumption that didn't hold. Precedents:

- Chunk 6.1 — Carbon `tearDown` assumption (the kickoff assumed Pest tests cleaned up `Carbon::setTestNow()` between tests; they didn't).
- Chunks 6.2–6.4 — `auth.account_locked` rename target (the kickoff proposed `.unspecified`; Cursor's investigation showed `.suspended` was semantically correct).
- Chunks 6.5–6.7 — two hidden assumptions in one group (401 interceptor architecture; idle-timeout redirect path).
- Chunks 6.8–6.9 — five flagged items, three load-bearing.

**Four for four. The pattern is now permanent.** "Honest deviation flagging" is no longer an emergent observation; it's a confirmed feature of the workflow. Recorded as such in PROJECT-WORKFLOW.md going forward.

Of the six items below: two of the deviations are architectural (OQ-1 + OQ-3) and required real implementation work; one is a pragmatic backend extension (OQ-2); three are downstream observations from reading the existing code (OQ-4 + OQ-5 + OQ-6 — the spec was adjusted to match what the codebase actually does, not what the kickoff text described).

### OQ-1 / Plan correction: `App.vue` was not wired to `<router-view />`

**Implicit assumption:** That Vue Router was already wired through `App.vue` such that `page.goto('/sign-in')` would render the `SignInPage`.

**Why it didn't hold:** Sprint-0's `App.vue` rendered a `Catalyst Engine` placeholder `<h1>` directly; `<router-view />` was never instantiated. The chunks 6.5–6.7 page tests passed because `mountAuthPage` (the test harness) mounts the page component directly, bypassing `App.vue`. Vue Router was registered in `main.ts` but its output was unreachable; runtime URL navigation was a silent no-op. The smoke test that "passed" before this group asserted only on the placeholder text — it did not catch the missing `<router-view />` because it didn't look for one.

**Alternative taken — accepted:** `App.vue` rewritten as a layout switcher driven by `route.meta.layout`. Conditional `v-if`/`v-else` ensures only one `<v-app>` mounts per route. The chunk-6.5 route table already declares `meta.layout` per route; the layout switcher is the natural consumer.

**Note:** This is a real Sprint-0 latent bug discovered by chunk 6.8 work, fixed in chunk 6.8. The fact that it surfaced only when E2E specs tried to navigate to authenticated routes is exactly why E2E exists in the workflow — unit tests bypass the SPA shell, integration tests exercise it.

### OQ-2 / Plan correction: `IssueTotpController` email branch

**Pre-answered Q:** "Use `POST /api/v1/_test/totp { user_id }` to mint a current code."

**Hidden assumption:** That the spec would have access to the user's numeric primary key.

**Why it didn't hold:** The SPA never sees the user's `id`. `UserResource` exposes `ulid` and `email`. A spec that only drives the SPA + production endpoints has no path to numeric `id` without an additional helper or Eloquent in test code (couples the spec to the model).

**Alternative taken — accepted:** Extended `IssueTotpController` to accept either `user_id` (numeric) or `email` (string, normalised the same way `SignUpService` does — `strtolower(trim(...))`). New private `resolveUser()` returns `User|JsonResponse`, centralising failure envelopes. Backward compatibility preserved.

### OQ-3 / Plan correction: Redis cache TTL bypasses `Carbon::setTestNow()`

**Hidden assumption:** That `POST /api/v1/_test/clock { at }` alone would unlock the temporary lockout 16 minutes "later".

**Why it didn't hold:** `AccountLockoutService` writes the temporary-lockout marker into the application cache with a 15-minute TTL. The Pest suite under SQLite passes because it uses the `array` cache driver — the array driver computes `expired-at` from `Carbon::now()` on read. Redis (the production driver) issues a real `EXPIRE` command with a real wall-clock TTL — `Carbon::setTestNow()` cannot influence it.

**Alternative taken — accepted:** Both local Playwright (via `playwright.config.ts`'s `webServer.env`) and CI (via the `e2e-main` job's env block) start the Laravel API with `CACHE_STORE: 'array'`. Documented in three places: `playwright.config.ts` docblock, the spec docblock, and the CI env comment.

**Generalization for future chunks:** This is the **third concrete instance** of the same general pattern: `Carbon::setTestNow()` only influences code that calls `Carbon::now()`. Anything bypassing Carbon — Google2FA's `time()` call (chunk 6.1), Redis `EXPIRE` (this chunk), Postgres `NOW()`, file mtimes, system clock APIs — does not honor the test clock. Future Playwright specs that need to time-travel past any external-system clock surface need a per-system shape (the `array` cache driver here, the existing TOTP-clock tech-debt for Google2FA, hypothetical Redis `DEBUG SLEEP` if a future spec asserts on Redis behaviour). Captured in §"Decisions documented for future chunks" below.

### OQ-4: Dashboard route is `/`, not `/dashboard`

Kickoff text inconsistency, not a deviation from intent. The route name `app.dashboard` (preserved); only the URL string is `/`. Spec asserts on `/`. Flagged so a future reader doesn't grep for `/dashboard` and assume the route was renamed.

### OQ-5: Sign-out goes via the API helper, not a UI button

No sign-out button exists in chunks 6.5–6.7; chunk 7 owns the nav surface. The spec uses a `signOutViaApi(request)` fixture against `POST /api/v1/auth/logout` with shared cookie context. The fixture exercises the production logout code path; only the trigger differs from the eventual UI button.

**Resolution trigger:** When chunk 7 ships the nav-surface UI with a sign-out button, spec #19 should be updated to drive the button (`page.click(dt(testIds.navSignOut))`). The fixture itself can stay — useful for other specs that don't care about the UI sign-out path.

### OQ-6: `auth.account_locked.temporary` i18n bundle has no `{minutes}` interpolation

The chunks 6.5–6.7 i18n bundle entry for `auth.account_locked.temporary` is `"Too many failed sign-in attempts. Please try again in a few minutes."` — generic, no placeholder. The backend's error envelope DOES carry `meta.retry_after_minutes` on `AuthErrorResource`, and `useErrorMessage` knows how to forward `details[0].meta` as the interpolation bag. The bundle entry simply doesn't have a `{minutes}` placeholder to consume the value.

**Spec accepted as-is:** asserts on a stable substring (`'failed sign-in'`) of the resolved bundle entry.

**UX gap:** A user-facing improvement would be to interpolate the actual minutes value. This is a real gap, not just a cosmetic one — telling users "in 12 minutes" is materially more helpful than "in a few minutes." Captured as a tech-debt entry in change-request #2 below; the resolution is a single-line bundle change plus a spec substring update.

---

## Standout design choices (unprompted)

Cursor's draft enumerates 12 design choices in detail. Of those, three deserve highlighting as broadly applicable patterns for Sprint 2+:

- **Single `<v-app>` per route via `v-if`/`v-else`.** `AuthLayout` mounts its own `<v-app>` (chunks 6.6 decision); wrapping it in another would double-mount Vuetify's app context. The conditional chain ensures even a refactor adding a third layout cannot accidentally produce a nested `<v-app>`. Pattern for any future top-level layout: declare the discriminator value, add the `v-else-if` branch, ship the component.
- **The `/api/v1/_test/clock/reset` health probe.** Playwright's `webServer.url` health check sends a GET to a POST-only route, returns 405. Playwright treats 405 as "server is up" — but a 405 from this specific route proves both that Laravel is routing AND that the chunk-6.1 test-helpers gate is open. One probe, two assertions. Pattern for any future health check that wants to combine "server up" with "test surface gated correctly."
- **`mintTotpCodeForEmail` called fresh at every "we need a code" point — never cached at spec scope.** TOTP codes have a 30-second window; minting fresh on every use means the spec is robust to slow CI runners that take >30s between mint and submit.

The other nine standout choices (typed fixtures, `testIds`/`dt()` helper, deterministic `T0`, `afterEach` reset on both specs, etc.) are well-explained in Cursor's draft and don't need re-summarizing here.

---

## Decisions documented for future chunks

- **Playwright specs live under `apps/main/playwright/`** (NOT `apps/main/tests/e2e/`). The `tests/` tree is for Vitest unit + architecture tests; the `playwright/` tree is for the Playwright runner. The `vitest.config.ts` exclusion list reflects this. Future Playwright specs follow the same layout.
- **`testIds` + `dt()` helper is the canonical selector pattern.** No spec spells a `[data-test="..."]` literal.
- **Playwright fixtures wrap `App\TestHelpers` calls; specs never spell HTTP URLs to `/api/v1/_test/*`.** Wrappers throw on non-2xx; specs read typed result values.
- **`workers: 1` + `fullyParallel: false` is mandatory for any spec that touches the `_test` surface (clock or cache).** The clock + cache are process-global; parallel workers would race.
- **Carbon test-clock + array cache is the hermeticity contract for any time-traveling Playwright spec.** Future specs that fast-forward time MUST run against `CACHE_STORE=array`. The Redis driver's real-wall-clock `EXPIRE` cannot be steered by `Carbon::setTestNow()`. **General rule:** `Carbon::setTestNow()` only influences code that calls `Carbon::now()`. External systems with their own clocks (Redis EXPIRE, Postgres NOW(), file mtimes, Google2FA's `time()`) don't honor the test clock and require per-system test-control shapes.
- **`TEST_HELPERS_TOKEN` is generated fresh per CI run via `openssl rand -hex 32` and exported via `$GITHUB_ENV`.** Both API server and Playwright runner read it from job env. One source, two consumers. Local dev uses the static value from `.env.example`. Never persisted to source.
- **Test-helper API endpoints accept the most natural identifier the caller has.** Numeric `user_id` AND email both accepted on `IssueTotpController` because the spec naturally has email but not id. Future test-helper endpoints follow the same "accept what the caller has" principle.
- **A spec MUST `afterEach` reset any global state it touched** (clock, cache key, simulated tenancy). Even if the spec didn't touch that state, defensive reset is cheap insurance against run-order bleed.
- **`App.vue` is a thin layout switcher; layouts own their `<v-app>` shell; pages own their content.** Three-layer split is the durable shape for any future top-level layout addition.
- **Smoke specs assert on layout shell + routed content together.** The chunk-6.8 smoke replacement is the pattern: navigate, assert layout via stable `data-test` on layout's brand mark, assert page mounted via page's own root `data-test`. Catches both layout-switcher regressions and routing regressions in one assertion.
- **CI E2E jobs split by SPA.** `e2e-main` brings up the full backend envelope; `e2e-admin` is a thin smoke until chunk 7 owns its surface. Future SPAs (if any) get their own job rather than sharing.
- **Module READMEs orient future contributors on the existing surface; they are NOT tutorials.** The chunk-6.9 `auth/README.md` is the pattern: where to start (4 anchor files), what the architecture tests enforce (with explicit names + one-line summaries), what the recurring patterns are. Future modules follow the same three-section shape.
- **Honest deviation flagging is now a confirmed permanent feature of the compressed-pattern workflow.** Four-for-four across chunk 6. Cursor flags any pre-answered Q whose hidden assumption fails contact with the codebase; reviewer accepts or course-corrects in the merged review file. Recorded so future Cursor sessions know this is expected behavior, not a one-off.

---

## Change-requests landing in this commit (status flips to Closed when these land)

Two small bookkeeping items, no code changes to the auth surface or specs.

**1. Fix the typo Cursor flagged in OQ section intro.** The intro paragraph at the top of "Plan corrections / honest deviation flagging — five items" says "two are downstream observations from reading the existing code (OQ-4 + OQ-5 + OQ-6 …)" — three IDs listed but the count says "two." Replace "two are downstream observations" with "three are downstream observations" so count and list agree.

**2. Add a `tech-debt.md` entry for the OQ-6 i18n `{minutes}` interpolation gap.** Append after the existing entries, format match (Where / What we accepted / Risk / Mitigation today / Triggered by / Resolution / Owner / Status):

- **Where:** `apps/main/src/core/i18n/locales/{en,pt,it}/auth.json` — the `auth.account_locked.temporary` bundle entry.
- **What we accepted in Sprint 1 chunks 6.8–6.9:** The bundle entry is `"Too many failed sign-in attempts. Please try again in a few minutes."` — generic phrasing, no `{minutes}` placeholder. The backend response carries `meta.retry_after_minutes` on the `AuthErrorResource`, and `useErrorMessage` already forwards `details[0].meta` as the interpolation bag, so the data path is open — only the bundle entry needs a placeholder to consume the value.
- **Risk:** Users see "in a few minutes" instead of the actual minutes remaining. Materially less helpful when the lockout has 14 minutes remaining vs 30 seconds remaining; both render the same string.
- **Mitigation today:** None. The generic phrasing is correct, just imprecise.
- **Triggered by:** A UX-focused chunk that improves auth error messages, OR a user complaint about not knowing how long to wait.
- **Resolution:** Add `{minutes}` placeholder to all three locale bundle entries. Update spec `failed-login-lockout-and-reset.spec.ts`'s substring assertion to accommodate the new shape (still matches `'failed sign-in'` as a substring; no full-string assertion needed).
- **Owner:** The sprint that introduces the UX improvement.
- **Status:** open.

(The chunks 6.5–6.7 `useErrorMessage` mapping coverage gap entry is already in `tech-debt.md`. The `auth.account_locked.temporary` interpolation gap is a sibling entry — both are i18n / error-rendering gaps deferred to a future UX chunk.)

---

## Tech-debt items

- **`auth.account_locked.temporary` `{minutes}` interpolation gap** — captured in change-request #2 above; landing in `tech-debt.md` in this commit.
- **No new tech debt added** by chunk 6.8 + 6.9 itself. The `CACHE_STORE=array` hermeticity contract is a documented driver choice (production runs Redis, tests run array — same shape as the existing SQLite-vs-Postgres item already in `tech-debt.md`), not a debt. Captured in "Decisions documented for future chunks" instead.
- **Pre-existing items from prior chunks remain open** (SQLite-vs-Postgres, TOTP issuance does not honor `Carbon::setTestNow()`, `useErrorMessage` mapping coverage gap). None are triggered by chunk 6.8 + 6.9 work.
- **One observation worth recording but not adding to `tech-debt.md`:** `testIds` is a TypeScript SOT for selector names but the `.vue` side is still a string. A page that renames a `data-test` attribute and forgets to update `testIds` would compile fine but the locator would never match at runtime. A future architecture test could close this gap by harvesting `data-test="..."` strings from the auth-module `.vue` files and asserting they're a superset of `testIds` values. Cursor flagged this in spot-check #2 of its self-review and deferred as not load-bearing for chunk 6.8. Agreed; not a debt, just a future hardening opportunity. If a real selector-drift bug ever bites, the architecture test is the resolution.

---

## Verification results

| Gate                                           | Result                                                                                                                                                                                              |
| ---------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `apps/main` lint                               | Clean                                                                                                                                                                                               |
| `apps/main` typecheck                          | Clean                                                                                                                                                                                               |
| `apps/main` Vitest                             | 228 passed across 26 spec files (vs. 225 at the close of 6.5–6.7)                                                                                                                                   |
| `apps/main` coverage                           | 100% lines / statements / functions / branches across `core/api`, `core/router`, `modules/auth/{components,composables,layouts,pages,stores}`                                                       |
| `packages/api-client` Vitest                   | 88 passed across 3 spec files                                                                                                                                                                       |
| `apps/admin` Vitest                            | 2 passed across 2 spec files                                                                                                                                                                        |
| Repo-wide `pnpm -r lint` / `pnpm -r typecheck` | Clean                                                                                                                                                                                               |
| `apps/api` Pint / Larastan / Pest              | Pass / 210 files no errors / 326 passed (970 assertions)                                                                                                                                            |
| Architecture tests                             | All 7 green                                                                                                                                                                                         |
| Playwright runtime                             | Not executed in this self-review (requires Postgres + Redis + Laravel + Vite stack). First green CI `e2e-main` run is the durable proof; the suite is structurally in place + lint/typecheck-clean. |

---

## Spot-checks performed

1. **Spec #19 string-content audit.** Reviewed full source. Zero string-content assertions; every check is URL match or `toBeVisible()`/`toBeEnabled()` against a `data-test` selector. The countdown wait flows through `expect(...).toBeEnabled({ timeout: 8_000 })` (polling assertion, not fixed sleep). Locale-resistant. Review priority #1 satisfied.

2. **Spec #20 string-content audit.** Three substring assertions (`'failed sign-in'`, `'account has been locked'`) — both substrings of i18n bundle entries (`auth.account_locked.temporary`, `auth.account_locked.suspended`), not hardcoded English. Acceptable for one-locale CI run; a future multi-locale expansion would key off `data-test` rendering of the resolved code instead. Clock anchored at deterministic T0; `afterEach` registered before any `setClock` call (ordering verified).

3. **CI workflow `e2e-main` job — token wiring.** Reviewed. Single source: `openssl rand -hex 32 >> $GITHUB_ENV` step. Two consumers: Playwright runner (`process.env.TEST_HELPERS_TOKEN` in `playwright.config.ts`) AND API server (Playwright launches `php artisan serve` via `webServer.env`, which inherits the parent env). The `CACHE_STORE: 'array'` envelope is correctly applied at job env level and propagates to the API server. The chunk-6.1 deferral (per-run token generation) is closed.

4. **`auth/README.md`.** ~440 words across three sections (where to start / architecture tests / recurring patterns). Audience-appropriate ("assumes the reader has read [the spec docs]"). All four anchor files exist; all six architecture tests exist; all five recurring patterns correspond to actual code in the module.

---

## Cross-chunk note

None this round. Confirmed:

- Chunks 6.2–6.4 data-layer invariants intact. The api-client surface is unchanged. The Pinia store gained no fields. The `auth.*` i18n bundles are unchanged from chunks 6.5–6.7.
- Chunks 6.5–6.7 UI invariants intact. Every page still asserts on its `data-test` attributes; `useErrorMessage` is unchanged; `useAuthStore` is unchanged; the architecture tests still green.
- Chunks 1–5 backend invariants intact except for the additive `IssueTotpController` email branch (gated behind the same chunk-6.1 env+token+per-request gating; no production exposure). The chunk-5 `TwoFactorService` isolation invariant (`TwoFactorIsolationTest`) is preserved — the new branch still routes through `currentCodeFor()`, no new path into `Google2FA`.
- The chunk-6.1 deferred CI work (per-run `TEST_HELPERS_TOKEN` generation, both consumers wired) is closed by this chunk.
- The `apps/main/src/App.vue` change is a structural fix for a Sprint-0 latent bug (no `<router-view />`), not a regression introduced by this group. The bug existed since Sprint 0 but only manifested at the E2E level — every chunks 6.5–6.7 page test bypassed `App.vue` via `mountAuthPage`. This is a latent bug discovered by chunk 6.8 work, fixed in chunk 6.8.

---

## Final chunk-6 self-review (the closing artifact for the entire chunk)

Cursor's full retrospective follows; Claude-side observations after.

### (a) Overall scope retrospective — chunks 6.1 → 6.9

What chunk 6 produced, end to end:

- **6.1 (backend, security-critical):** `GET /api/v1/me` + `GET /api/v1/admin/me` (single controller, two routes per the cookie-isolation contract); the entire `App\TestHelpers` module (env+token+provider gating, the Carbon test-clock + tracker pattern, the TOTP issuance helper, the verification-token mint helper); inline tenancy decisions documented in `security/tenancy.md`.
- **6.2 (data layer — api-client):** Typed `auth.api.ts` re-export surface + the `HttpClient` interface; CSRF preflight; `ApiError` discriminated by `code`; the `auth.account_locked.escalated → suspended` rename consolidated.
- **6.3 (data layer — i18n):** `auth.json` namespace populated for en/pt/it; the source-inspection architecture test that harvests every backend `auth.*` code and asserts coverage in all three bundles.
- **6.4 (data layer — Pinia store):** `useAuthStore` with per-action loading flags, the `bootstrap()` cold-load probe, optimistic-update + best-effort refresh for `verifyTotp` / `disableTotp`, the no-recovery-codes-in-store invariant pinned by source-inspection test.
- **6.5 (UI core — router + interceptor + idle):** Vue Router v4 instance with declarative route table + `meta.guards`; three pure async guards composed by a single `beforeEach` dispatcher; the `onUnauthorized` callback pattern on `createHttpClient`; the idle-timeout composable with explicit redirect.
- **6.6 (UI — auth pages + AuthLayout):** Eight auth pages + `AuthLayout` + `localeOptions.ts` extracted helper; `useErrorMessage` resolver; `auth.ui.*` i18n namespace. Coverage scope widened; exclusion+guard pattern applied to `routes.ts` + `AuthLayout.vue`.
- **6.7 (UI — 2FA flow):** `RecoveryCodesDisplay.vue` with the unconditional 5-second confirm gate; `EnableTotpPage` / `VerifyTotpPage` / `DisableTotpPage`; the chunk-6.7 architecture test extension (`RecoveryCodesDisplay.vue` does not import `useAuthStore`).
- **6.8 (E2E):** Playwright suite (config + global setup + fixtures + selectors + 3 specs); CI `e2e-main` job; the `App.vue` layout switcher fix; the `IssueTotpController` email branch.
- **6.9 (closing artifacts):** `auth/README.md`; this review file's final self-review section.

The chunk's actual depth: backend additions (3 new endpoints + 1 new module + 1 new method on `TwoFactorService`), data-layer surface (4 typed api-client functions + 1 Pinia store + 1 composable), UI (1 layout switcher + 1 layout + 8 pages + 1 component + 1 composable + 1 helper), E2E (3 specs + 6 fixtures + 1 SOT helper + 1 config + 1 global setup + 1 CI job), tests (40 TestHelpers Pest cases + 228 main SPA Vitest specs across 26 files + 7 architecture tests). All 11 team standards from PROJECT-WORKFLOW.md § 5 preserved; new standards added (see (b) below).

### (b) Team standards established or extended in chunk 6

Going in: the 11 standards from PROJECT-WORKFLOW.md § 5 (source-inspection regression tests; `Event::fake` split; real-rendering mailables; single error code for non-fingerprinting; transactional audit; idempotency; constant-verification-count; reasoned dead code removal; user-enumeration defense; review-files workflow; Q-and-A pattern).

Added or sharpened by chunk 6:

- **The compressed-pattern process modification** (introduced for the chunks 6.5–6.7 group, confirmed durable through 6.8 + 6.9 — four groups in a row of plan-then-build-in-one-pass + single-completion-artifact + honest-deviation-flagging working as intended). The pattern is now the default for any chunk with a clear scope and a reviewer who pre-answers Q1–Q3.
- **The `App\TestHelpers` env+token+per-request gating shape** as the durable pattern for any non-product surface. Future test-helpers, debug surfaces, or staging-only knobs follow the same three-layer gating with the same bare-404-on-closed-gate response.
- **Carbon test-clock + tracker pattern** (chunk 6.1) for any global-state pinning that must coexist with tests that manipulate the same global state directly. Recorded as a generalisable pattern, not just a `Carbon::setTestNow()` thing.
- **Two-route-one-controller for cross-guard reuse** when the cookie-isolation contract makes a multi-guard route infeasible. `/me` + `/admin/me` is the chunk-6.1 example; future global-resource endpoints follow.
- **Single error code for non-fingerprinting at the lockout layer** (chunks 6.2–6.4 rename: `escalated → suspended`). The renamed code is now standard.
- **`onUnauthorized` callback shape on `createHttpClient`** (chunks 6.5–6.7 OQ-1) for any application-level policy hook. Future cross-cutting policies (rate-limit retry, audit logging, telemetry) extend the same callback shape.
- **Dynamic imports for store/router circular dependencies in policy hooks** (chunks 6.5–6.7).
- **Idle-timeout / session-expiry composables MUST redirect explicitly, not rely on a 401 interceptor** (chunks 6.5–6.7 OQ-2). The composable owns the side effect of "after duration of inactivity, end up on sign-in".
- **Route guards as pure async functions returning `RouteLocationRaw | null`; the router's `beforeEach` composes them** (chunks 6.5–6.7).
- **Test harnesses that route through Vue Router (or any async framework lifecycle) MUST await readiness before mounting** (chunks 6.5–6.7 — the `mountAuthPage` async pattern). Generalises to vue-i18n locale loading, Pinia plugin initialization, etc.
- **`useErrorMessage` as the canonical error-rendering helper for the auth surface** (chunks 6.5–6.7).
- **Pages with exhaustive phase enums use `v-else` for the terminal branch** (chunks 6.5–6.7) to close the implicit "no template rendered" branch v8 reports as uncovered.
- **Component-local one-time secrets must NEVER reach the store**, even via "convenience" composables (chunks 6.5–6.7 + chunk-6.7 architecture test extension). Backed by per-component architecture tests, not just a store-side test.
- **Kebab-case route-name convention** (chunks 6.5–6.7).
- **Vue SFCs with `<script setup>` blocks containing only computed properties / refs / lifecycle hooks** follow the exclusion+guard pattern (chunks 6.5–6.7).
- **`apps/main/src/App.vue` is a layout switcher, not a content shell** (chunk 6.8). Layouts own the `<v-app>` shell; pages own their content.
- **Playwright specs live under `apps/main/playwright/`** (chunk 6.8); `tests/` is for Vitest only.
- **`testIds` + `dt()` helper as the Playwright selector pattern** (chunk 6.8).
- **`workers: 1` + `fullyParallel: false` + `CACHE_STORE=array`** as the hermeticity envelope for any time-traveling Playwright spec (chunk 6.8).
- **`TEST_HELPERS_TOKEN` generated per CI run via `openssl rand -hex 32`, written to `$GITHUB_ENV`, read by both API + Playwright runner** (chunk 6.8 — closes chunk 6.1 deferral).
- **Test-helper API endpoints accept the most natural identifier the caller has** (chunk 6.8 — the `IssueTotpController` user_id + email precedent).
- **Specs MUST `afterEach` reset any global state they touched** (chunk 6.8). Defensive reset is cheap insurance.
- **Module READMEs orient future contributors; they are NOT tutorials** (chunk 6.8 — the `auth/README.md` three-section pattern).

### (c) What surprised the team — the four "honest flagging" instances

Four chunk-6 groups, four instances of the kickoff carrying a hidden assumption that didn't survive contact with the codebase:

1. **Chunk 6.1 — Carbon `tearDown` assumption.** The kickoff's test-clock spec assumed Pest tests would naturally clean up `Carbon::setTestNow()` between tests. They don't; the `TestCase::tearDown()` had to gain an explicit reset. The fix added the tracker-based `ApplyTestClock::resetPinningTracker()` so the cleanup is observable by tests that want to assert on pinning state.

2. **Chunks 6.2–6.4 — `auth.account_locked.escalated` rename target.** The kickoff said rename to `.permanent`. The chunks 6.2–6.4 group flagged that `permanent` overclaimed (the lockout is reversible by an admin); the team accepted the alternative `.suspended`. Spec #20 in chunk 6.8 asserts on the `.suspended` rendering, the rename is durable.

3. **Chunks 6.5–6.7 — two hidden assumptions in one group.** OQ-1: the kickoff said the 401 interceptor lives in `apps/main/src/core/api/index.ts` and attaches to the underlying axios instance, but the chunks 6.2–6.4 architecture test forbids importing axios in `apps/main/`. The fix extended `createHttpClient` with an `onUnauthorized` callback. OQ-2: the kickoff said the idle-timeout composable can rely on the 401 interceptor for the post-idle redirect, but a static dashboard view never makes the next HTTP request that would 401. The composable now explicitly redirects.

4. **Chunks 6.8 + 6.9 — five flagged items, three of them load-bearing.** OQ-1 (App.vue had no `<router-view />`), OQ-2 (`IssueTotpController` needed an email branch), OQ-3 (`CACHE_STORE=array` is the lockout-test hermeticity contract). Two more downstream observations (OQ-4 dashboard URL is `/`, OQ-6 `account_locked.temporary` has no `{minutes}` interpolation) are spec-level adjustments, not code changes.

The pattern is now four-for-four. The compressed-pattern modification "honest deviation flagging" (chunk-6.5–6.7 process modification §4) is unambiguously load-bearing — it has caught a deviation in every group it has applied to. Recorded as a permanent feature of the workflow rather than a recurring observation.

### (d) What is deferred to where

**Chunk 7 (admin SPA + nav surface):**

- **`/dashboard` placeholder body content.** The current `DashboardPlaceholderPage` is two lines of "you are signed in" text. Chunk 7 owns the first real shell.
- **Sign-out UI button.** Spec #19 currently calls `POST /api/v1/auth/logout` via the `signOutViaApi` fixture; once the nav surface ships, the spec should drive the UI button. The fixture itself can stay for other specs.
- **Admin SPA's auth surface.** Chunks 6.5–6.7 already preemptively added `/admin/me` + `/admin/auth/login` to the `UNAUTHORIZED_EXEMPT_PATHS` list, so the admin SPA's first commit lands inside the existing 401-handling perimeter.
- **`e2e-admin` CI job's full backend envelope.** Currently a thin smoke; chunk 7 should match what `e2e-main` does (Postgres + Redis + Laravel + token wiring).

**Chunk 8 (preferences):**

- **`PATCH /me/preferences` endpoint** + the SPA's preferences page. Chunk 6.1 deliberately kept `/me` side-effect-free so the future preferences endpoint is a separate route.
- **`useErrorMessage` mapping coverage gap.** Tracked in `tech-debt.md`; the trigger is the next chunk that adds a new `auth.*` error code AND surfaces it through the UI. If chunk 8 adds preferences-specific error codes, the gap fix lands then; otherwise it stays open.

**Sprint 2:**

- **Tenancy chunks.** The chunks 6.1 `tenancy.set` middleware is in place on `/me` + `/admin/me`; agency-context routes that the middleware actually populates ship in Sprint 2.
- **Visual regression testing for the auth surface.** Out of scope for chunk 6 + Sprint 1; Playwright's screenshot infrastructure is in place (`screenshot: 'only-on-failure'` is enabled), but no comparison baselines exist.
- **Browser-matrix expansion.** Chunk 6.8 ships chromium only; firefox + webkit are kickoff-acknowledged "if low cost", but they aren't free for the chunk-6.8 build (Playwright browser cache size doubles, CI runtime grows). Sprint 2 or later.

**Sprint 8:**

- **Postgres-CI for the Pest suite.** SQLite-vs-Postgres tech debt is open; the Pest suite still runs against SQLite in-memory. Spec #20's lockout test runs against Postgres in CI (because the API server in `e2e-main` connects to the Postgres service container), but the unit-level lockout tests under `apps/api/tests/Feature/` still use SQLite.
- **Redis-hardening for the application cache.** The `CACHE_STORE=array` envelope for E2E is the hermetic shape; if a future spec needs to assert against Redis-specific cache behaviour (eviction, persistence, replication), Sprint 8 owns the hardening + the new test-control shape (Redis `DEBUG SLEEP` or a test-controllable cache key structure).
- **TOTP issuance does not honor `Carbon::setTestNow()`** (tech-debt entry from chunk 6.1). Trigger is "any future spec that combines time-travel with TOTP issuance". Spec #19 + #20 don't combine the two (#19 mints with real time, #20 doesn't mint at all), so the gap stays open until a spec that needs both lands. Likely Sprint 9 (session-management UI) or Sprint 13+ (security pipeline).

### Claude-side observations

**Chunk 6 was unusually decision-dense.** Across nine sub-chunks the team established or sharpened ~20 team standards (the `TestHelpers` gating shape, the Carbon test-clock + tracker pattern, the two-route-one-controller for cookie isolation, the `onUnauthorized` callback shape, the optimistic-update + best-effort refresh pattern, the recovery-codes-never-in-state per-component architecture test, the kebab-case route convention, the exclusion+guard pattern, the layout-switcher shape, the Playwright fixture/selector conventions, the per-run token rotation, and so on). A typical Sprint-2 chunk will not have this density — most subsequent chunks will be applying these patterns rather than inventing them. Sprint 1 was the standards-establishment phase; Sprint 2 onward is application.

**The compressed-pattern workflow is now load-bearing across all four chunk-6 groups.** Plan-then-build-in-one-pass + single-completion-artifact + honest-deviation-flagging worked for 6.1 (un-compressed start), 6.2–6.4, 6.5–6.7, and 6.8–6.9. Round-trip count dropped from a projected ~40 across chunk 6 to actual ~15. Review quality held — every group caught at least one real bug or architectural issue at the review step. Confirmed as the default for all future chunks unless a specific group's complexity demands the un-compressed cadence.

**The "honest deviation flagging" pattern is the most important durable workflow output of chunk 6.** Four for four. Every group surfaced at least one hidden assumption in Claude's kickoff — Carbon `tearDown` (6.1), rename target (6.2–6.4), 401 interceptor + idle redirect (6.5–6.7), App.vue + email branch + cache TTL (6.8–6.9). The pattern hinges on Cursor having explicit permission to deviate when a literal instruction would conflict with existing invariants, plus the discipline to flag rather than silently work around. Carrying forward into Sprint 2 with the discipline embedded in every kickoff.

---

## Process record — compressed pattern (fourth instance, final chunk-6 group)

The compressed pattern continues to work as intended:

- **Q1–Q3 pre-answers, with honest deviation flagging.** This group: three pre-answered items had hidden assumptions (App.vue wiring, IssueTotpController identifier, `CACHE_STORE` envelope); all three caught during the build, all three resolved with structurally-correct alternatives, all three documented in the "Plan corrections" section above. Two additional spec-level observations (OQ-4 dashboard URL, OQ-6 i18n interpolation) flagged as well.
- **Single completion artifact at the end.** One chat completion summary, one draft review file (this file). The review file is structurally similar in length to chunks 6.5–6.7's merged review even though scope includes the entire chunk-6 retrospective.
- **Plan-then-build in one pass.** Cursor drafted the 13-step plan at the top of the session and built through to completion without pausing.
- **Spot-checks scoped tightly.** Four spot-checks from Claude (specs #19 and #20, CI workflow, README); Cursor's eight self-review spot-checks complement rather than duplicate. Total spot-check count is comparable to chunks 6.5–6.7 despite the broader scope.

---

_Provenance: drafted by Cursor on Sprint 1 chunks 6.8 + 6.9 group completion (compressed-pattern process — single chat completion summary + single structured draft per `PROJECT-WORKFLOW.md` § 3 step 6, modified). Independently reviewed by Claude with four targeted spot-checks. Five honest-deviation flags surfaced (OQ-1 through OQ-6, with OQ-1, OQ-2, OQ-3 load-bearing); the pattern of "every chunk-6 group catches at least one hidden assumption in the kickoff" is now four-for-four and recorded as a permanent feature of the workflow. This is the closing review for the entire chunk 6. Two small bookkeeping change-requests issued (typo fix in the OQ-section intro; `tech-debt.md` entry for the `auth.account_locked.temporary` `{minutes}` interpolation gap). Status flips to "Closed" when both land in the working tree, in the same commit as this review file. After commit, chunk 6 is closed and Sprint 1 moves to chunk 7 (admin SPA + nav surface)._

---

## Post-merge addendum #1 — Playwright API health-probe fix (2026-05-10)

This addendum is appended after chunks 6.8–6.9 closed (commit 7341340) and
the chunk-6 plan-approved checkpoint flipped to Closed (commit 1cc3fd7).
The work itself is shipped as a follow-up commit, not a re-open of chunk 6.

### (a) What surfaced in CI

The first push of the closed chunk (CI run #30, commit 1cc3fd7) failed
on the new `e2e-main` job with `Error: Timed out waiting 60000ms from
config.webServer.` after 1m 1s. Backend, frontend, and `e2e-admin` were
all green on the same run. The 60000ms in the error matches the API
webServer's `timeout` (60_000) — the Vite entry's timeout is 120_000 —
so the failure was localized to the Laravel API health probe.

### (b) The one-file fix

`apps/main/playwright.config.ts` — switched the API webServer's
health-probe URL from `/api/v1/_test/clock/reset` (POST-only, returns
405 to GET) to `/up` (Laravel 11's built-in GET-200 health route,
already enabled in `apps/api/bootstrap/app.php`).

Root cause: Playwright's `isURLAvailable`
(`playwright-core/lib/server/utils/network.js`) accepts
`statusCode >= 200 && statusCode < 404` — 404, 405, 5xx are all
treated as "not yet available" and the prober keeps polling until the
deadline fires. The original config's docblock incorrectly claimed
Playwright treats a 405 as "server is up". It does not. The probe
was sitting on Laravel returning 405 to every GET while the 60s
deadline ticked down.

The original "two assertions in one probe" cleverness (boot + gate-open
in a single GET) was structurally impossible under Playwright's actual
contract. Gate-open is already validated independently by
`playwright/global-setup.ts` (fails loudly if `TEST_HELPERS_TOKEN` is
unset) and by every spec's first `/_test/*` call (would 404 cleanly if
the gate were closed), so no validation coverage is lost by switching
to `/up`.

The docblock was rewritten to capture (i) Playwright's `< 404` rule
explicitly, (ii) the rationale for `/up` as the new probe, and (iii)
where gate-open is now validated, so the next reader of the file sees
the contract instead of re-deriving it.

### (c) What this validates about the honest-deviation-flagging pattern

The chunks 6.8–6.9 review file's Verification section explicitly flagged
"Playwright runtime — Not executed in this self-review" as a known blind
spot, with the reasoning recorded honestly: no headed runner attached to
the session, time-traveling specs need a real Postgres/Redis ladder. CI
was nominated as the runtime ground truth.

CI did exactly that — it caught a Playwright-runtime bug that the
unit-test + Pint + Larastan + Pnpm-lint ladder structurally could not
have caught (none of those execute the webServer health probe), and it
caught it on the very first push. The blind-spot disclosure was the
right call: pretending the runtime was covered would have shipped a
silent timeout into the chunk-6 closing artifact; flagging it as
deferred-to-CI made the failure expected rather than surprising.

This is the fifth instance in chunk 6 of the honest-deviation pattern
catching a real hidden assumption (after OQ-1 App.vue routing, OQ-2
TOTP user lookup, OQ-3 Redis TTL vs Carbon, OQ-4 dashboard route name).
Three of those five were caught pre-runtime in the kickoff-vs-code
phase; this one was caught at the deferred-to-CI boundary the review
explicitly named. The pattern is doing what it's meant to.

No back-edit of the chunks 6.8–6.9 review file's body is needed —
the blind-spot disclosure stands as written. This addendum is the
honest record of what surfaced in the deferred runtime check and how
it was resolved.

### (d) Status

- Fix shipped as a single follow-up commit
  (`fix(spa-auth): correct Playwright API health-probe URL [post-chunk-6 hotfix]`,
  commit `5f3b935`).
- No change to chunk-6 sub-chunk closure status; this is a hotfix on
  closed work, not a re-open.
- Next CI push expected to clear `e2e-main`. Any further Playwright-
  runtime-only failures will surface from there and be iterated in
  the same commit-per-fix cadence (no need to re-open chunk 6).

---

## Post-merge addendum #2 — global-setup ESM safety (2026-05-10)

This addendum is appended after the API health-probe fix shipped
(commit `5f3b935`). Same chunk-6 closure status applies — no re-open.

### (a) What surfaced in CI

CI run #31 (commit `5f3b935`, the addendum #1 health-probe fix)
advanced past the webServer probe stage but failed `e2e-main` at the
very next step with `ReferenceError: __dirname is not defined` at
`apps/main/playwright/global-setup.ts:43`, in 1m 13s. Backend,
frontend, and `e2e-admin` all green. The probe fix worked exactly as
intended — `e2e-main` now reaches the "Run Playwright tests" step —
but the global setup throws before any spec runs.

### (b) The one-file fix

`apps/main/playwright/global-setup.ts` — replaced
`path.resolve(__dirname, '../../api')` with
`fileURLToPath(new URL('../../api', import.meta.url))`, dropped the
unused `node:path` import, added `fileURLToPath` from `node:url`.

Root cause: `apps/main/package.json` declares `"type": "module"`,
so every `.ts` in the package is loaded as ESM. The CommonJS globals
`__dirname` and `__filename` are undefined under ESM — accessing
either throws a `ReferenceError` at the top of the function. The
idiom for "directory of the current file in ESM" already established
in this repo is `fileURLToPath(new URL('./relative', import.meta.url))`
(see `apps/main/vite.config.ts` line 11 and both `vitest.config.ts`
files); the fix matches that house style.

Why the architecture tests under `apps/main/tests/unit/architecture/`
keep working with `__dirname`: Vitest provides a per-module polyfill
for `__dirname` and `__filename` in transformed test modules even
when the package is ESM. Playwright's TypeScript loader does NOT
polyfill, so the same idiom that works in six existing Vitest files
breaks in this one Playwright file. Scope is deliberately narrow —
the architecture tests don't need to change because they're never
loaded by Playwright.

A multi-line comment was added above the new resolution call so the
next reader (or AI) doesn't "fix" it back to `__dirname`, with a
back-reference to this addendum for the post-mortem.

### (c) What this validates about the CI-as-runtime-arbiter pattern

Second consecutive Playwright-runtime-only bug, caught on the second
consecutive push. The pattern is working as designed: the chunks
6.8–6.9 review explicitly nominated CI as the runtime ground truth
for Playwright (because no headed runner was attached to the build
session), and CI is iterating through the runtime-only bugs one push
at a time, in the order they surface.

This is the sixth chunk-6 honest-deviation catch — addendum #1 was
the fifth. Three caught pre-runtime in kickoff-vs-code review (OQ-1
App.vue routing, OQ-2 TOTP user lookup, OQ-3 Redis TTL vs Carbon);
two caught in CI now (probe URL, ESM `__dirname`). The pattern
continues to converge on the actual reachable surface.

The cadence (one runtime bug per push, fix per push) is structurally
healthy as long as each fix stays genuinely scoped to a single
discovery and ships under the same hotfix-on-closed-work convention.
If a CI push surfaced a cluster of related issues at once, the right
move would be to bundle the fix; so far the bugs are surfacing
independently, one per push.

### (d) Status

- Fix shipped as a single follow-up commit
  (`fix(spa-auth): make Playwright global-setup ESM-safe [post-chunk-6 hotfix]`).
- No change to chunk-6 sub-chunk closure status; same hotfix-on-
  closed-work convention as addendum #1.
- Next CI push expected to advance past `globalSetup` and either run
  the spec suite to completion OR surface the next runtime-only bug,
  whichever comes first. The `2fa-enrollment-and-sign-in.spec.ts` and
  `failed-login-lockout-and-reset.spec.ts` files have not yet been
  exercised against a real backend in any environment; further
  discoveries are entirely possible and will be iterated under the
  same convention.
