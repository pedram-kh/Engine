# Sprint 1 — Chunks 6.2 → 6.4 Review (data layer of the SPA auth surface)

**Status:** Approved with change-requests — flips to **Closed** when the three items in §"Change-requests landing in this commit" below are implemented in the working tree.

**Reviewer:** Claude (independent review) — incorporating Cursor's self-review draft.

**Reviewed against:** `02-CONVENTIONS.md` § 1 + § 3 + § 4, `04-API-DESIGN.md` § 4 + § 7 + § 8, `05-SECURITY-COMPLIANCE.md` § 6, `07-TESTING.md` § 4 + § 4.3, `01-UI-UX.md` (i18n bundle structure, error-code conventions), `20-PHASE-1-SPEC.md` § 5 + § 7, `security/tenancy.md` § 3 (cross-tenant allowlist for chunk 6), `feature-flags.md`, `tech-debt.md`, `PROJECT-WORKFLOW.md` § 5 (all 11 team standards) + § 7 (spot-checks-before-greenlighting), `reviews/sprint-1-chunk-6-plan-approved.md`, `reviews/sprint-1-chunk-6-1-review.md` (most-recent precedent).

This review covers all three sub-chunks (6.2, 6.3, 6.4) per the compressed-pattern process modification on the kickoff.

---

## Scope

This is the data layer of the SPA auth surface: TypeScript types, the api-client wrapper, the i18n bundle, and the Pinia store. UI consumption (router guards, auth pages, 2FA UI) is the next group (6.5–6.7).

### 6.2 — `@catalyst/api-client` auth surface

- **`packages/api-client/src/errors.ts`** — `ApiError` class extending `Error`. Single error type thrown by every typed function. Exposes `status`, `code`, `message`, `details: readonly ApiErrorDetail[]`, `raw`, `requestId`. Two static factories: `ApiError.fromEnvelope(status, body)` parses the JSON:API envelope from `04-API-DESIGN.md § 8`; `ApiError.fromNetworkError(cause)` produces `status: 0`, `code: 'network.error'` for transport failures.
- **`packages/api-client/src/http.ts`** — `createHttpClient({ baseUrl, ... })` returns a thin `HttpClient` (`get` / `post` / `patch` / `delete`). Every state-changing call (`POST` / `PATCH` / `DELETE`) issues a `GET csrfCookieUrl` preflight first, reads the resulting `XSRF-TOKEN` cookie, and forwards it on the subsequent request as `X-XSRF-TOKEN`. `withCredentials: true` is hard-pinned even when an injected axios instance defaults to `false`. Network and HTTP failures are normalized through `ApiError`.
- **`packages/api-client/src/auth.ts`** — `createAuthApi(http, { variant })` returns a typed `AuthApi`. Twelve typed functions covering every auth endpoint shipped in chunks 3–5: `me`, `login`, `logout`, `signUp`, `verifyEmail`, `resendVerification`, `forgotPassword`, `resetPassword`, `enrollTotp`, `verifyTotp`, `disableTotp`, `regenerateRecoveryCodes`. The `variant: 'main' | 'admin'` discriminator routes the `/me` and `/auth/login` paths to their admin twins (`/admin/me`, `/admin/auth/login`); chunk 7's admin store will pass `'admin'` and consume the same package.
- **`packages/api-client/src/types/`** — `user.ts` mirrors `UserResource::toArray()` verbatim with `snake_case` keys. `auth.ts` covers every request DTO and the 2FA response shapes (`EnableTotpResponse`, `RecoveryCodesResponse`). The `UserType` union is non-optional with all four enum values from `App\Modules\Identity\Enums\UserType` (`creator | agency_user | brand_user | platform_admin`); `brand_user` is reserved for Phase 2 but carried so the union matches the backend exactly.
- **`packages/api-client/src/index.ts`** — barrel exporting `createHttpClient`, `createAuthApi`, `ApiError`, and all wire types. The shared package is the only place axios is imported anywhere in the workspace (verified by source-inspection — see § "Spot-checks performed" below).
- **Wiring**: `apps/main/src/core/api/index.ts` and `apps/admin/src/core/api/index.ts` build the singleton `http` + `authApi`. Each Vite config now proxies both `/api` and `/sanctum` to `http://127.0.0.1:8000`. `axios` is dropped from each SPA's direct dependencies (it's only reachable as a transitive of `@catalyst/api-client`).
- **Tests** (`packages/api-client/src/{errors,http,auth}.spec.ts`) — 82 Vitest cases. Coverage: 100% lines / 100% statements / 100% functions / 100% branches on the three module files. The two genuinely-defensive guards (SSR `typeof document === 'undefined'`, JSDOM-impossible `document.cookie ?? ''`, regex group always-present cast, defaulted method on `request()`) carry `/* c8 ignore */` markers with `@preserve` rationale.

### 6.3 — i18n auth namespace + error-code coverage test

- **`apps/main/src/core/i18n/locales/{en,pt,it}/auth.json`** — covers every `auth.*` string literal harvested from `apps/api/app/Modules/Identity/**/*.php`: error codes, backend `trans()` keys, mailable subjects, and request-validator messages. Three locales: en, pt, it. Strings borrow from `apps/api/lang/{en,pt,it}/auth.php` for parity.
- **`_default` sentinel — to be removed in this commit** (change-request #1). The bundle as currently shipped uses a `_default` sentinel to handle the path collision where the backend ships both `auth.account_locked` (a leaf code) AND `auth.account_locked.temporary` (a deeper leaf). The change-request below renames the parent leaf on the backend, eliminating the collision and the sentinel.
- **`apps/main/src/core/i18n/index.ts`** — wires the auth bundle in alongside the existing `app` bundle via per-locale spread. Typed schema is `typeof enApp & typeof enAuth` so the i18n typing surface picks up the new namespace.
- **`apps/main/tests/unit/architecture/i18n-auth-codes.spec.ts`** — regex-walks every `*.php` file under `apps/api/app/Modules/Identity/`, harvests every `auth.*` literal, and asserts each appears as a key in all three locale bundles. Five Vitest cases (sanity-check on harvested codes, one per locale, allowlist verification). The walk uses Node `fs` from the SPA test runner. An explicit `CONFIG_KEY_ALLOWLIST` excludes the two Laravel framework config keys that happen to live under `auth.` (`auth.admin_mfa_enforced`, `auth.passwords.users.expire`) — adding to the allowlist requires a code review.
- **Verifiable failure mode demonstrated**: a temporary file declaring `const STRAY = 'auth.synthetic_test_code'` was added; the test reported three failures (one per locale, naming the missing key); the file was removed; the test returned to green.

### 6.4 — `useAuthStore` (Pinia)

- **`apps/main/src/modules/auth/api/auth.api.ts`** — module-local re-export of the singleton `authApi` from `core/api`. The store imports through here so unit tests mock the module without touching `core/api` (which depends on `import.meta.env`). Excluded from coverage thresholds because it's a pure re-export — but a file-size guard is being added in this commit (change-request #3) so the exclusion can't drift.
- **`apps/main/src/modules/auth/stores/useAuthStore.ts`** — Pinia setup-syntax store. State: `user`, `bootstrapStatus: 'idle' | 'loading' | 'ready' | 'error'`, `mfaEnrollmentRequired`, plus eleven per-action loading flags. Getters: `isAuthenticated`, `userType`, `isMfaEnrolled`. Actions: `bootstrap`, `login`, `logout`, `setUser`, `clearUser`, `signUp`, `verifyEmail`, `resendVerification`, `forgotPassword`, `resetPassword`, `enrollTotp`, `verifyTotp`, `disableTotp`, `regenerateRecoveryCodes`.
- **`bootstrap()` semantics**:
  - **200**: stores user, `bootstrapStatus = 'ready'`, `mfaEnrollmentRequired = false`.
  - **401**: clears user, `bootstrapStatus = 'ready'`, `mfaEnrollmentRequired = false`. Does NOT throw and does NOT set `'error'`. 401 on cold load is "not signed in", which is normal (review priority #6).
  - **403 `auth.mfa.enrollment_required`**: sets `mfaEnrollmentRequired = true`, `bootstrapStatus = 'ready'`. The `user` field is left untouched because the backend does not include a payload on this branch (admin-SPA territory; chunk 7).
  - **Any other rejection**: sets `bootstrapStatus = 'error'` and rethrows.
  - **Concurrent calls** dedupe via an in-flight promise cache (`inFlightBootstrap`). Two `bootstrap()` calls fired simultaneously result in exactly one `apiClient.me()` invocation. The cache is cleared in a `finally` so a subsequent (post-401) bootstrap reissues a fresh request, even if the first call rejected.
- **Recovery codes never enter Pinia state.** `verifyTotp()` and `regenerateRecoveryCodes()` return `RecoveryCodesResponse` from the action — the chunk-6.7 component will hold the codes in component-local state for one-time display. The recovery-code values themselves are never assigned to a state field. This is enforced by the source-inspection regression test below.
- **`verifyTotp()` / `disableTotp()` follow-up `me()` — to be reshaped in this commit** (change-request #2). Currently the actions call `me()` after the primary call and silently swallow any failure. The change-request below switches to optimistic local update of `user.two_factor_enabled` before the follow-up call, so a failed `me()` is invisible to the UI rather than leaving stale state for the chunk-6.5 router guard to read.
- **Tests** (`useAuthStore.spec.ts`) — 52 Vitest cases. Coverage on `useAuthStore.ts`: 100% lines / 100% statements / 100% functions / 100% branches.

### 6.2 / 6.3 / 6.4 — wiring + tooling

- **Vitest configs**: `apps/main/vitest.config.ts` and `apps/admin/vitest.config.ts` updated to include `src/**/*.{spec,test}.ts` so co-located tests run alongside the existing `tests/unit/**` tree. Architecture-level source-inspection tests live under `tests/unit/architecture/` (separate from module-level co-located tests).
- **Coverage gating**: `packages/api-client/vitest.config.ts` thresholds at 100% over `src/**/*.ts` (excluding `src/index.ts` barrel and `src/types/**`). `apps/main/vitest.config.ts` thresholds at 100% scoped to `src/modules/auth/**/*.ts` (auth-flow standard from `02-CONVENTIONS.md § 4.3`).
- **Source-inspection: no axios/fetch outside `packages/api-client/`** (`apps/main/tests/unit/architecture/no-direct-http.spec.ts`, mirrored under `apps/admin/`) — walks the entire `src/` tree and rejects any `import 'axios'`, `require('axios')`, dynamic `import('axios')`, or `fetch(` call. Continues standard 5.1.

---

## Acceptance criteria — all met (modulo change-requests)

| #   | Sub-chunk | Priority                                                                                                                | Status                                                                                                                                       |
| --- | --------- | ----------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | 6.2       | Typed wrapper for every auth endpoint (login, logout, me, signUp, verifyEmail, resendVerification, forgot/reset, 2FA×4) | ✅ `auth.ts` exposes 12 typed functions; full envelope unwrap on every 2xx                                                                   |
| 2   | 6.2       | TypeScript types for `UserResource` and DTOs                                                                            | ✅ `types/user.ts` mirrors backend `toArray()` verbatim; `types/auth.ts` covers every request DTO and 2FA response shape                     |
| 3   | 6.2       | Sanctum SPA cookie auth — CSRF preflight + `withCredentials` + `X-XSRF-TOKEN` header                                    | ✅ `createHttpClient` issues `GET /sanctum/csrf-cookie` before every state-changing call; reads the `XSRF-TOKEN` cookie; forwards the header |
| 4   | 6.2       | Error envelope unwrap into `ApiError`; backend codes preserved verbatim                                                 | ✅ `ApiError.fromEnvelope` reads `errors[0].code`; `errors.spec.ts` pins the verbatim contract on five codes                                 |
| 5   | 6.2       | api-client is the only place that knows about HTTP                                                                      | ✅ Source-inspection test `no-direct-http.spec.ts` enforces this on both SPAs; `axios` removed from each app's direct deps                   |
| 6   | 6.2       | Vitest unit tests covering success / 422 / 401 / 403 `auth.mfa.enrollment_required` / 419 / network for each function   | ✅ `auth.spec.ts` covers each path; CSRF mismatch (419) and admin /me 403 included                                                           |
| 7   | 6.2       | 100% line coverage on the auth module                                                                                   | ✅ `auth.ts`, `errors.ts`, `http.ts` all at 100% (lines / statements / functions / branches)                                                 |
| 8   | 6.2       | Source-inspection: no axios/fetch in `apps/main/src/` or `apps/admin/src/` outside `packages/api-client/`               | ✅ Two architecture tests (one per SPA), both green                                                                                          |
| 9   | 6.3       | Three-locale coverage of every auth API error code (en, pt, it)                                                         | ✅ Bundle covers every harvested literal (post-rename in this commit)                                                                        |
| 10  | 6.3       | Source-inspection regression test walks backend source                                                                  | ✅ `i18n-auth-codes.spec.ts` regex-walks `apps/api/app/Modules/Identity/**/*.php` from the SPA test runner                                   |
| 11  | 6.3       | Verifiable failure mode demonstrated                                                                                    | ✅ Stray `auth.synthetic_test_code` flipped the test red on all three locales; removal returned green                                        |
| 12  | 6.4       | State, actions, getters per the kickoff                                                                                 | ✅ `user`, `bootstrapStatus`, `mfaEnrollmentRequired`, eleven per-action flags; thirteen actions; three getters                              |
| 13  | 6.4       | `bootstrap()` dedupes concurrent calls                                                                                  | ✅ Two and three-call concurrent dedup tests pass with exactly one `me()` invocation                                                         |
| 14  | 6.4       | `bootstrap()` 401 path does NOT throw or set `status=error`                                                             | ✅ Dedicated test `on 401 clears the user and ends with status=ready (NOT error)`                                                            |
| 15  | 6.4       | 403 `auth.mfa.enrollment_required` exposes the derived flag                                                             | ✅ `mfaEnrollmentRequired` ref flips true; `user` left untouched                                                                             |
| 16  | 6.4       | Recovery codes NEVER assigned to Pinia state                                                                            | ✅ Source-inspection regression test `no-recovery-codes-in-store.spec.ts` enforces this; runtime sanity checks in store tests verify         |
| 17  | 6.4       | Source-inspection regression test for recovery codes — verifiable failure mode                                          | ✅ Stray `const recoveryCodes = ref(...)` flipped the test red; removal returned green                                                       |
| 18  | 6.4       | 100% line coverage on `useAuthStore.ts`                                                                                 | ✅ 100% lines / statements / functions / branches; `auth.api.ts` excluded with a file-size guard added in this commit (change-request #3)    |

---

## Standout design choices (unprompted)

- **`HttpClient` interface decouples consumers from axios.** `auth.ts` depends on the `HttpClient` interface, not on `AxiosInstance`. The `auth.spec.ts` doubles use `vi.fn()` directly without `axios-mock-adapter`. The HTTP module's own tests use `axios-mock-adapter` against a real axios instance. This keeps the transport implementation swappable (a future fetch-based backend would only touch `http.ts`) and keeps the domain tests fast and assertion-clear. Strong precedent for future API surfaces (campaigns, creators) — they'll consume the same `HttpClient` interface.
- **Explicit CSRF preflight, manual cookie read.** Axios's built-in `xsrfCookieName` / `xsrfHeaderName` only fire inside a real browser AND when the request URL matches the cookie's origin exactly. Under JSDOM (Vitest) the auto-attach silently no-ops, leaving the cookie-mode tests asserting against zero behaviour. The implementation explicitly disables axios's xsrf handling and reads `document.cookie` itself, so the same code path runs in JSDOM and in the browser. **This is a sharp catch** — the alternative would have been a green test suite over a CSRF preflight that silently doesn't fire in production.
- **`absolutize()` vs `resolveCsrfUrl()` — separate concerns.** Application calls go through `absolutize(path, baseUrl)` which prepends `baseUrl` (the versioned API path). The CSRF preflight goes through `resolveCsrfUrl(csrfCookieUrl, baseUrl)` which anchors at the host root, ignoring the API path prefix — Sanctum's endpoint conventionally lives at `/sanctum/csrf-cookie` not `/api/v1/sanctum/csrf-cookie`.
- **`Object.defineProperty` for `Error.cause` instead of the ES2022 options bag.** The api-client's tsconfig is on ES2022 lib, but `apps/main`'s `@vue/tsconfig/tsconfig.dom.json` pins `lib: ["ES2020", "DOM", "DOM.Iterable"]`. The `Error` constructor under ES2020 lib doesn't accept the `{ cause }` options bag. Setting `cause` as an own property post-construction works at runtime AND type-checks under both lib levels.
- **Two-route variant for the api-client (`'main'` vs `'admin'`).** The api-client function is shared, but `me()` and `login()` paths differ between the main and admin SPAs (`/me` vs `/admin/me`, `/auth/login` vs `/admin/auth/login`). The `variant` discriminator on `createAuthApi()` defaults to `'main'`; chunk 7's admin store will pass `'admin'`. Sign-up, email verification, and password reset paths are NOT variant-switched — those endpoints only exist on the main SPA's surface (admin onboarding is invite-driven and out-of-scope for Phase 1). Mirrors the chunk-6.1 `MeController` two-route shape one layer up.
- **Per-action loading flags, not one coarse `isLoading`.** A user who fires `logout()` while a slow `me()` is still in flight should see the logout's spinner without the bootstrap's spinner toggling alongside it. Eleven flags is verbose, but each one drives independent UI; collapsing them invites cross-action UI bleed when concurrent actions land.
- **`ALLOWED_REF_NAMES` allowlist in the recovery-codes test, not regex narrowing.** A regex that excludes `isRegeneratingRecoveryCodes` while catching `recoveryCodes` and `recovery_codes` is brittle (every variant of "is...RecoveryCodes" needs an explicit anchor). A small `Set<string>` allowlist documents the exception explicitly. Adding to the allowlist requires a code review; loosening the regex doesn't. Same pattern as the i18n test's `CONFIG_KEY_ALLOWLIST`.
- **`harvestAuthCodes()` regex anchored on string-literal quotes.** The walker matches `['"]auth\.[a-z_][a-zA-Z0-9_.]*['"]` — only literal string forms. PHP variable accesses like `$auth->foo` and array index syntax like `$config['auth']['foo']` don't match. The two Laravel framework config keys that DO appear as string literals are explicitly allowlisted with an inline comment naming each one.
- **Architecture test landed in `apps/admin/` even though chunk 7 hasn't started building admin code yet.** The no-direct-http test is mounted on the admin SPA from day zero, so when chunk 7's first commit lands, axios bypass attempts fail before merge. Belt-and-suspenders against a future Cursor session forgetting the contract.

---

## Decisions documented for future chunks

- **The `HttpClient` interface is the canonical transport surface for every future API package.** Future surfaces (campaigns, creators, applications, audit) consume `HttpClient` and produce typed wrapper functions following the same shape as `createAuthApi`. New surfaces do NOT instantiate axios directly; they build on `createHttpClient`.
- **JSON envelope unwrapping is the api-client's job, not the consumer's.** Consumers (stores, components, future composables) see typed responses or `ApiError` exceptions. They never touch `errors[0].code` directly.
- **Backend error codes are preserved verbatim end-to-end.** No re-mapping in the api-client, no re-mapping in stores, no re-mapping in i18n keys. `auth.invalid_credentials` is `auth.invalid_credentials` everywhere. The non-fingerprinting rule from chunks 4–5 (standard 5.4) carries forward unchanged — adding a new internal failure mode that should map to an existing public code goes through the backend, not the client.
- **State-changing requests issue a CSRF preflight regardless of perceived freshness.** Caching the cookie's "freshness" introduces a state machine the api-client doesn't need; the preflight is cheap, the alternative is a 419 retry path that has to be tested. One round-trip per state-changing request is the cost of doing business with Sanctum SPA cookie auth.
- **Pinia stores own user-scoped state and orchestration; never sensitive credentials, never one-time secrets.** Recovery codes are the canonical example. Future analogues (TOTP secret strings during enrollment, password reset tokens, sign-up confirmation tokens) follow the same rule — they ride the action's return value and live in component-local state for the duration of one screen.
- **Architecture-level source-inspection tests live under `apps/{main,admin}/tests/unit/architecture/`.** Module-level co-located tests live next to the source they cover (`useAuthStore.ts` → `useAuthStore.spec.ts` in the same directory). The split is intentional: architecture tests are repository-wide invariants; module tests are scoped to one file.
- **Coverage exclusions require a guard.** Excluding a file from coverage thresholds (e.g. `auth.api.ts`) is acceptable when the file's contract is verified by typecheck alone, but the exclusion must come with a guard that catches drift — typically a file-size or content-shape assertion in the architecture-tests directory. The pattern is "exclusion + guard," never bare exclusion. Established by change-request #3 below.
- **Per-action loading flags, not one coarse `isLoading`.** Future stores follow the same pattern. Concurrent actions with shared loading state cause cross-action UI bleed; the verbosity of separate flags is the cost of correctness.
- **`bootstrap()`-style dedup is the canonical pattern for any "fire on cold load" action.** Every store action that's expected to be called from multiple consumers (router guards, lifecycle hooks, retry logic) caches the in-flight promise and clears it in `finally`. The pattern: `if (inFlight) return inFlight; const p = (async () => { try {...} finally { inFlight = null } })(); inFlight = p; return p;`
- **Optimistic local update + best-effort refresh** is the canonical pattern for any action that modifies a single field on the stored user. Established by change-request #2 below: instead of waiting for `me()` to confirm a new state, set the field directly from the primary action's success path, then fire `me()` as a refresh whose failure is invisible. Future analogues (PATCH preferences in chunk 8, profile edits, role changes) follow the same shape.
- **Audit enum strings stay coupled to the response error code they record** until a deliberate refactor justifies the split. Established by change-request #1 below: `AuditAction::AuthAccountLockedSuspended` and the response code `'auth.account_locked.suspended'` rename in lockstep. Future enum-and-code pairs follow the same coupling rule unless a sprint explicitly justifies divergence.

---

## Change-requests landing in this commit (status flips to Closed when these land)

Three items, none design-blocking. One is a backend rename + downstream propagation, two are frontend-only.

**1. Rename the colliding backend `auth.account_locked` error code; eliminate the `_default` sentinel from the i18n bundle.**

The collision driving the `_default` sentinel exists because the backend ships both `auth.account_locked` (a leaf code, indefinite suspension) AND `auth.account_locked.temporary` (a deeper leaf, 15-minute cooldown). The semantically correct fix is to give the parent leaf a sibling under the dotted path so both sit at the same depth.

**Rename target:** `auth.account_locked.suspended`. Mirrors `LoginResultStatus::AccountSuspended` and the `users.is_suspended` column it derives from. Pairs naturally with the existing `.temporary` sibling — readers see "suspended" (admin/long-window, row in `users`) vs "temporary" (cache TTL, row in cache) and the dimension is immediately clear.

**Audit enum coupling:** rename in lockstep. `AuditAction::AuthAccountLocked = 'auth.account_locked'` becomes `AuditAction::AuthAccountLockedSuspended = 'auth.account_locked.suspended'`. The two strings stay coupled because honest semantic divergence between an audit event name and a response code name has no current consumer benefit, and an accidental drift between the two would be harder to debug than one shared string serving two purposes. If a future sprint genuinely needs to split the audit vocabulary from the response vocabulary, that's a deliberate refactor with its own justification.

**Files touched:**

- `apps/api/app/Modules/Identity/Http/Controllers/LoginController.php` — emit-site (the one Cursor identified at lines 102–107).
- `apps/api/app/Modules/Audit/Enums/AuditAction.php` — case rename + value update.
- `apps/api/app/Modules/Identity/Services/AccountLockoutService.php` — emit-site for the audit log row (uses the enum, but the rename of the case touches usage).
- `apps/api/tests/Feature/Modules/Identity/Auth/LoginTest.php` — three or four assertion updates (the `assertJsonPath('errors.0.code', 'auth.account_locked')` lines, the `where('action', AuditAction::AuthAccountLocked->value)` line, the `it(...)` description, the test name).
- `apps/api/tests/Feature/Modules/Audit/AuditActionEnumTest.php` — the literal-value assertion at line 21.
- Any docblocks in `LoginResult.php`, `AuthService.php`, `AccountLockoutService.php` that reference the literal — comment update only, not behaviour change.
- `apps/main/src/core/i18n/locales/{en,pt,it}/auth.json` — drop the `_default` sentinel, replace with `auth.account_locked.suspended`. Also drop `auth._meta._default_convention` (it has no remaining purpose).
- `apps/main/src/core/i18n/index.ts` — drop the `_default` convention reference if the docblock mentions it.
- `apps/main/tests/unit/architecture/i18n-auth-codes.spec.ts` — drop the `_default`-resolution branch from `resolveDottedKey()` (a dotted path lands on a string or it doesn't; the code branch is no longer needed). Also strengthen the sanity check: assert `auth.account_locked.suspended` is in the harvested set AND `auth.account_locked` is NOT (catches a botched rename where an emit-site is updated but a test isn't).

**Verification:**

- Backend: `pest --filter LoginTest` and `pest --filter AuditActionEnumTest` both green.
- Frontend: `pnpm --filter @catalyst/main test --run i18n-auth-codes` green; `pnpm --filter @catalyst/main test:coverage` green.
- Architecture test exercises the new sanity assertions: a temporary edit changing `'auth.account_locked.suspended'` to `'auth.account_locked'` in `LoginController` should flip the test red ("missing translation: auth.account_locked"); revert.

**2. Optimistic-update pattern in `verifyTotp()` and `disableTotp()` — replace silent-swallow on follow-up `me()`.**

Current shape (lines 268–304 of `useAuthStore.ts`): both actions call `me()` after the primary call to refresh `two_factor_enabled` on the stored user, and silently swallow any failure on the follow-up. The reasoning ("the next `bootstrap()` self-heals") doesn't hold: there's no scheduled `bootstrap()` between the 2FA enrollment success screen and the next page load. A user who enrolls, sees recovery codes, and navigates to `/dashboard` will see stale `two_factor_enabled = false` until something else triggers a refresh. Chunk 6.5's router guards will read that stale flag.

Setting `bootstrapStatus = 'error'` on follow-up failure (Cursor's Q2 alternative) is wrong in the other direction — it conflates the primary action's success with a separate cache-refresh failure.

**Optimistic-update pattern:**

```typescript
async function verifyTotp(payload: ConfirmTotpRequest): Promise<RecoveryCodesResponse> {
  isVerifyingTotp.value = true
  try {
    const result = await authApi.verifyTotp(payload)
    // Optimistic: the backend just confirmed enrollment, so we know
    // two_factor_enabled is now true. Update the stored user directly
    // rather than waiting for a follow-up me() to confirm.
    if (user.value !== null) {
      user.value = {
        ...user.value,
        attributes: { ...user.value.attributes, two_factor_enabled: true },
      }
    }
    // Best-effort: fire me() to pick up any other drifted fields.
    // A failure here is invisible — the optimistic update has
    // already left the store in the correct shape for two_factor_enabled.
    try {
      const refreshed = await authApi.me()
      setUser(refreshed)
    } catch {
      // Silent — optimistic update is canonical for this field.
    }
    return result
  } finally {
    isVerifyingTotp.value = false
  }
}

async function disableTotp(payload: DisableTotpRequest): Promise<void> {
  isDisablingTotp.value = true
  try {
    await authApi.disableTotp(payload)
    if (user.value !== null) {
      user.value = {
        ...user.value,
        attributes: { ...user.value.attributes, two_factor_enabled: false },
      }
    }
    try {
      const refreshed = await authApi.me()
      setUser(refreshed)
    } catch {
      // Same rationale as verifyTotp.
    }
  } finally {
    isDisablingTotp.value = false
  }
}
```

**Test additions** to `useAuthStore.spec.ts`:

- `verifyTotp` — when the primary call succeeds AND the follow-up `me()` succeeds, the user reflects the refreshed payload (existing test, unchanged).
- `verifyTotp` — when the primary call succeeds AND the follow-up `me()` fails, the user is still `two_factor_enabled = true` (NEW — pins the optimistic update).
- `verifyTotp` — when the primary call fails, the user is unchanged AND no follow-up `me()` is invoked (existing test, unchanged).
- Mirror three tests for `disableTotp` with `two_factor_enabled = false` on the optimistic branch.

The user-mutation pattern uses spread-replace (`user.value = { ...user.value, attributes: { ... } }`) rather than `user.value.attributes.two_factor_enabled = true` so reactive consumers see the update via the top-level ref reassignment. Either works in Pinia setup-syntax stores; spread-replace is the clearer pattern for "this is a deliberate state transition."

**3. File-size guard on `apps/main/src/modules/auth/api/auth.api.ts` — coverage exclusion gets a drift guard.**

The re-export module is excluded from coverage thresholds because tests deliberately mock it at the module level. The exclusion is correct (the module's contract is verified by typecheck), but bare exclusion has a known failure mode: someone adds a one-line interceptor "while they're in there," coverage doesn't catch it, the architecture diverges silently.

**Add** `apps/main/tests/unit/architecture/auth-api-reexport-shape.spec.ts`:

```typescript
import { promises as fs } from 'node:fs'
import path from 'node:path'
import { describe, expect, it } from 'vitest'

const FILE = path.resolve(__dirname, '../../../src/modules/auth/api/auth.api.ts')

const MAX_NON_COMMENT_LINES = 12 // current is ~6; headroom for a future
// re-export of an additional symbol but
// not for runtime logic.

describe('apps/main/src/modules/auth/api/auth.api.ts is a pure re-export', () => {
  it('contains no runtime logic beyond re-exports', async () => {
    const contents = await fs.readFile(FILE, 'utf8')
    const lines = contents.split('\n')

    // Strip blank lines and comment-only lines (single-line // and
    // block /* ... */ that span their own lines).
    const significantLines = lines.filter((line) => {
      const trimmed = line.trim()
      if (trimmed === '') return false
      if (trimmed.startsWith('//')) return false
      if (trimmed.startsWith('*')) return false // inside a block comment
      if (trimmed.startsWith('/*')) return false
      if (trimmed.startsWith('*/')) return false
      return true
    })

    expect(significantLines.length).toBeLessThanOrEqual(MAX_NON_COMMENT_LINES)

    // Every significant line must be an `import` or `export` statement.
    for (const line of significantLines) {
      const trimmed = line.trim()
      const isImportOrExport = /^(import|export)\b/.test(trimmed)
      expect(isImportOrExport, `disallowed runtime line: "${trimmed}"`).toBe(true)
    }
  })
})
```

This catches both shape failures (a `const x = ...` runtime line) and growth failures (someone adding ten lines of "lightweight glue"). If a future sprint legitimately needs the file to grow, the threshold gets bumped with a code review and a justification — exactly the discipline we want around a coverage exclusion.

**Verification:** add the file, run `pnpm --filter @catalyst/main test --run auth-api-reexport-shape`, expect green. Demonstrate the failure mode by adding a temporary `const _x = 1` runtime line; expect red; remove; expect green.

---

## Q3 settled

The kickoff Q3 (`_default` vs `_self` vs `_root` for the sentinel name) is moot — change-request #1 eliminates the sentinel entirely. No naming convention to add to `01-UI-UX.md`. If a future i18n collision arises that genuinely can't be resolved by renaming the colliding code, we'll revisit then.

## Follow-up items

### For chunk 6.5 (router + guards)

- The `requireAuth` router guard fires `useAuthStore.bootstrap()` and waits on it before rendering any authenticated route. Three terminal cases the guard reads: 200 → render the route; 401 (`bootstrapStatus === 'ready'` AND `user === null`) → redirect to `/sign-in`; 403 `auth.mfa.enrollment_required` (`mfaEnrollmentRequired === true`) → redirect to `/auth/2fa/enable`. The error case (`bootstrapStatus === 'error'`) needs a fallback page; chunk 6.5 should pick the shape (top-level error boundary vs a dedicated route).
- 401 axios interceptor that triggers re-bootstrap on session expiry — chunk 6.5. The interceptor lives in `apps/main/src/core/api/index.ts` (the singleton wiring layer), not in the api-client package — the package is transport-only, the interceptor is application-policy.
- Idle-timeout composable — chunk 6.5.

### For chunk 6.6 (auth pages)

- Components rendering `t(error.code)` consume the i18n bundle at the dotted path directly. With change-request #1 landed, no sentinel-aware composable is needed; `t('auth.account_locked.suspended')` resolves to the leaf string, period. Vue-i18n's default messageResolver handles the dotted path.
- The auth pages should accept the user-facing string as a slot or prop rather than hardcoding `t(...)` calls — keeps the rendering testable without a full i18n mount. Chunk 6.6 picks the exact shape.

### For chunk 6.7 (2FA UI)

- Recovery codes from `verifyTotp()` and `regenerateRecoveryCodes()` flow through the action return value to the component, which holds them in component-local `ref` state for one-time display. The component MUST NOT pass them back into the store. The architecture test in `no-recovery-codes-in-store.spec.ts` enforces the store side; component-side discipline is reviewed by Claude in chunk 6.7.

### For chunk 7 (admin SPA)

- The admin SPA's `useAdminAuthStore` builds on `createAuthApi(http, { variant: 'admin' })`. Same shape as `useAuthStore`, different paths and a stricter MFA-enrollment branch. The 403 `auth.mfa.enrollment_required` path that's currently dead code on the main store becomes the canonical signed-in-but-blocked branch for the admin store.
- The admin SPA's architecture tests are already mounted (`no-direct-http.spec.ts`); chunk 7 should add the admin-side equivalent of the recovery-codes guard before the admin store ships any 2FA actions.

### For chunk 8 (preferences PATCH)

- `PATCH /api/v1/me/preferences` (or whatever the endpoint becomes) uses the optimistic-update + best-effort refresh pattern from change-request #2. The store action mutates the user payload locally on primary success, then fires `me()` as a refresh whose failure is invisible.

## What was deferred (with triggers)

- **Composable for vue-i18n consumption with sentinel-aware resolution.** Eliminated by change-request #1 — there is no sentinel.
- **Server-Sent Events transport** — out of scope for chunk 6; if needed for future chunks, the addition lives in `@catalyst/api-client` (per the `no-direct-http.spec.ts` docblock), not as a per-SPA bypass.
- **i18n schema typing for the existence of every backend code at compile time.** The current source-inspection test catches drift at test time, not at compile time. A typed schema generated from a backend codes registry would shift the catch left; deferred until / unless drift becomes frequent.
- **Concurrent-action loading-flag dedup.** If two simultaneous calls to the same action ever fire (unlikely with `bootstrap()`'s pattern, but possible for `regenerateRecoveryCodes()` from a fast double-click), the second toggles the loading flag off when the first is still in flight. Trigger: any UI bug that traces back to a double-fire. Resolution: extend the `bootstrap()` dedup pattern to other actions, or per-action click-debouncing in components.
- **Telemetry on `bootstrapStatus = 'error'` transitions.** Currently the store rethrows; consumers decide what to do. A telemetry hook is out of scope until we have a telemetry surface.

## Verification results

| Gate                                       | Result                                                                                                                                                                                             |
| ------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `@catalyst/api-client` tests               | 82 passed (errors.spec.ts: 11, http.spec.ts: 35, auth.spec.ts: 36); 100% coverage on auth.ts / errors.ts / http.ts                                                                                 |
| `apps/main` tests                          | 61 passed (App.spec.ts: 1, architecture: 8, useAuthStore.spec.ts: 52); 100% coverage on useAuthStore.ts. Will go to 67–68 after change-requests (3 verifyTotp + 3 disableTotp + 1 file-size guard) |
| `apps/admin` tests                         | 2 passed (App.spec.ts: 1, no-direct-http.spec.ts: 1)                                                                                                                                               |
| Backend tests                              | All green; will be re-run after change-request #1 lands (LoginTest + AuditActionEnumTest assertion updates)                                                                                        |
| Lint / typecheck across all three projects | clean                                                                                                                                                                                              |

## Spot-checks performed

1. **`no-direct-http.spec.ts`** — confirmed walks the entire `apps/main/src/` tree (recursive `walk()`); covers four pattern variants (static `from 'axios'`, `require('axios')`, dynamic `import('axios')`, and `fetch(`); fails loudly with file paths and pattern descriptions on violation; mirrored on `apps/admin/`. Mounted on the admin SPA from day zero so the perimeter is enforced before chunk 7's first commit.

2. **`no-recovery-codes-in-store.spec.ts`** — confirmed the `FORBIDDEN_NAME_PATTERN` is `/recovery_?codes?/i` (catches `recoveryCodes`, `recovery_codes`, `recoveryCode`, etc.); confirmed the `ALLOWED_REF_NAMES` set is exactly `{ 'isRegeneratingRecoveryCodes' }`; confirmed the inline docblock says "extend with an explicit allowlist AND document the rationale" rather than "loosen the regex." Sanity-check finds the auth store before asserting the rule.

3. **`i18n-auth-codes.spec.ts`** — confirmed regex-walks `apps/api/app/Modules/Identity/**/*.php` from the SPA test runner; confirmed `harvestAuthCodes()` regex anchors on `['"]auth\.[a-z_][a-zA-Z0-9_.]*['"]` (literal forms only — no false positives on `$config['auth']['foo']`); confirmed `CONFIG_KEY_ALLOWLIST` excludes the two Laravel framework config keys; confirmed `resolveDottedKey()` honors the `_default` sentinel as a terminal value (will be simplified after change-request #1). After change-request #1 the test gains a positive assertion that `auth.account_locked.suspended` is in the harvested set.

4. **i18n test output + en bundle** — test passes (5 cases); en bundle reviewed inline. Bundle structure clean; the `_default` sentinel is the only architectural wart, and change-request #1 removes it.

5. **`http.ts` CSRF preflight** — reviewed full source. Confirmed: `xsrfCookieName: undefined` and `xsrfHeaderName: undefined` on the axios instance disable the built-in JSDOM-broken auto-attach; `STATE_CHANGING_METHODS` set covers POST/PATCH/DELETE; preflight fires before the actual request via a separate `instance.request({ method: 'GET', url: resolvedCsrfUrl })`; cookie read happens via `readCookie(xsrfCookieName)` after the preflight, with `decodeURIComponent` (Laravel writes the cookie URL-encoded); header attached on the actual request. `withCredentials: true` is hard-pinned via `instance.defaults.withCredentials = true` after instance creation, defending against an injected test instance defaulting to false. `resolveCsrfUrl()` correctly anchors at host root for absolute baseUrls and falls through to relative for Vite-proxied dev. The `c8 ignore` markers on defensive guards are appropriate (SSR check, regex group cast, defaulted method).

6. **`bootstrap()`** — reviewed full source. Three-branch terminal logic exactly per the kickoff: 200 → set user, status=ready, mfaEnrollmentRequired=false; 401 → clear user, status=ready, mfaEnrollmentRequired=false, NO throw and NO error status; 403 `auth.mfa.enrollment_required` → mfaEnrollmentRequired=true, status=ready, user untouched; other → status=error, rethrow. Dedup cache (`inFlightBootstrap`) cleared in `finally` so a rejected first call doesn't cement a stuck-promise state. Concurrent calls early-return the cached promise. Clean.

7. **`verifyTotp` / `disableTotp`** — reviewed full source. Confirms Cursor's Q2 description: both actions silently swallow follow-up `me()` failures. Change-request #2 reshapes both to optimistic-update + best-effort refresh.

8. **`axios` footprint** — confirmed via `package.json` reads (jq unavailable on Pedram's machine; substituted with Node, output verbatim). `apps/main` direct deps: `@catalyst/api-client`, `@catalyst/design-tokens`, `@catalyst/ui`, `pinia`, `vue`, `vue-i18n`, `vue-router`, `vuetify`. `apps/admin` direct deps: identical list. `packages/api-client` direct deps: `axios` (sole runtime dep). Architecture test enforces; package boundaries enforce.

9. **Backend rename investigation** — Cursor surfaced the `AuditAction` enum collision I would have missed. The investigation correctly recharacterizes the parent leaf (`auth.account_locked`) as "indefinite/admin-cleared suspension," not "fallback for unspecified lockout type." Rename target adjusted accordingly: `auth.account_locked.suspended`, with audit enum coupled in lockstep. Captured as change-request #1 above.

## Cross-chunk note

None this round. Confirmed:

- The chunk-6.1 `MeController` two-route shape is mirrored one layer up by the api-client's `variant: 'main' | 'admin'` discriminator. Same architectural pressure (cookie-isolation contract → path-aware routing), same shape of resolution (one shared implementation, two routes/calls).
- The chunk-3 / chunk-4 / chunk-5 backend invariants are untouched — change-request #1 is a code-name rename only, not a behaviour change.
- The chunk-5 `TwoFactorIsolationTest` source-inspection precedent is extended by three new architecture tests (`no-direct-http.spec.ts`, `no-recovery-codes-in-store.spec.ts`, `i18n-auth-codes.spec.ts`), continuing standard 5.1.

## Process record — compressed pattern

The compressed-pattern process modifications applied for this group worked as intended. Three observations for the running record:

- **Q1–Q3 pre-answers eliminated one round-trip.** Cursor built straight through without pausing for plan approval. The Q1 (`_default` sentinel handling) that Cursor surfaced at the end of the build wasn't a Q1 in the original sense (architectural ambiguity blocking the build) — it was a flagged design tension that emerged from doing the work and required a change-request anyway. That's the right kind of question to surface at completion, not the kind that would have benefited from a mid-build pause.
- **Single completion artifact at the end was correct.** One draft review file covering all three sub-chunks, one chat summary. Total review-file size (Cursor's draft + Claude's merge) is comparable to the chunk-6.1 review even though the scope is 3× as large; nothing was lost in the compression.
- **The spot-checks-before-greenlighting workflow caught two design tensions that Cursor's draft also flagged honestly** (the `_default` sentinel and the `me()` swallow). The honest-flagging pattern from chunk-5 spot-check #3 and chunk-6.1 change-request #1 holds. Three data points now; recording as a confirmed team standard rather than an emerging pattern.

The compressed pattern continues for chunks 6.5–6.7 unless 6.2–6.4's resolution round surfaces something that requires the un-compressed cadence.

---

_Provenance: drafted by Cursor on Sprint 1 chunks 6.2–6.4 group completion (compressed-pattern process — single chat completion summary + single structured draft per `PROJECT-WORKFLOW.md` § 3 step 6, modified). Independently reviewed by Claude with nine spot-checks. Cursor's investigation of the proposed rename surfaced an audit enum collision Claude would have missed; rename target adjusted from `auth.account_locked.unspecified` to `auth.account_locked.suspended` based on the (c) semantic analysis. Three change-requests issued. Status flips to "Closed" when change-requests #1–#3 land in the working tree, in the same commit as this review file._
