# `apps/admin/src/modules/auth/`

The auth module owns every user-visible auth surface in the admin SPA:
sign-in, the full 2FA enrollment / verification / disable flow, and
recovery-code display. The module is responsible for the UI, the
page-level state, and the route table; the wire layer (HTTP, error
envelopes) lives in `@catalyst/api-client`, the cross-cutting 401 policy

- idle timeout live in `apps/admin/src/core/`. This README is the
  orientation for a future contributor; it assumes the reader has already
  read `docs/04-API-DESIGN.md`, `docs/05-SECURITY-COMPLIANCE.md § 6`, and
  `docs/02-CONVENTIONS.md`.

Mirror of `apps/main/src/modules/auth/README.md` (chunks 6.5–6.7) with
two structurally-correct admin adaptations:

- **No sign-up / forgot-password surface.** Admin onboarding is
  out-of-band per `docs/20-PHASE-1-SPEC.md` § 5; the only routes the
  module declares are `/sign-in` and the three `/auth/2fa/*` routes.
- **Mandatory MFA.** Every authenticated admin route is gated by
  `requireMfaEnrolled` on top of `requireAuth`. The chunk-7.4 guard
  preserves the intended destination across the enforcement redirect
  via `?redirect=<intended>` (D7 design decision) — see the
  "Chunk-7 design decisions" section below.

## Where to start reading

- **`stores/useAdminAuthStore.ts`** — the data hub. Every page consumes
  this store; every backend call goes through it. The `bootstrap()`
  action is the cold-load identity probe (chunks 7.2 + 7.4) and
  surfaces the `mfaEnrollmentRequired` flag the router consumes. The
  per-action loading flags drive the UI's disabled / loading states.
- **`routes.ts`** — the declarative route table. Each record carries
  `meta.layout` (consumed by `App.vue`'s layout switcher) and
  `meta.guards` (resolved by `core/router/index.ts`'s dispatcher).
- **`pages/SignInPage.vue`** — a representative consumer of the
  store + the `useErrorMessage` resolver. Reading it shows how a
  page composes loading flags, inline error rendering, and the
  in-page TOTP transition on `auth.mfa_required`. The page omits
  the sign-up / forgot-password links that main's identical-shape
  page renders (chunk-7.5 structurally-correct admin adaptation).
- **`pages/EnableTotpPage.vue`** — the load-bearing page for the
  mandatory-MFA flow. Mirrors main's chunk-6.6 enrollment page with
  one admin-only adaptation: the post-confirm navigation honors
  `?redirect=<intended>` if present (D7 deep-link preservation).
- **`components/RecoveryCodesDisplay.vue`** — the most invariant-
  heavy component in the module: codes flow in via prop only, never
  enter Pinia state, and the 5-second confirm gate is enforced
  unconditionally with no skip path.

## Architecture tests that protect the module

Source-inspection tests under `apps/admin/tests/unit/architecture/`
catch architectural drift the type system can't:

- `no-direct-http.spec.ts` — no `import 'axios'` or `fetch(` outside
  `@catalyst/api-client`.
- `no-recovery-codes-in-store.spec.ts` — no Pinia state field whose
  name matches `/recovery_?codes?/i`, AND (chunk-7.5 extension)
  `RecoveryCodesDisplay.vue` must not import `useAdminAuthStore`.
- `i18n-auth-codes.spec.ts` — every `auth.*` / `rate_limit.*` literal
  harvested from `apps/api/app/Modules/Identity/**/*.php` resolves
  in all three admin locale bundles (en, pt, it).
- `auth-api-reexport-shape.spec.ts` — `api/admin-auth.api.ts` is a
  pure re-export (≤ 12 significant lines, only `import` / `export`
  statements). Exclusion+guard for the coverage carve-out.
- `auth-layout-shape.spec.ts` (chunk-7.5) — `AuthLayout.vue` stays a
  structural shell (≤ 80 lines, no multi-statement arrows in
  `<script setup>`). Exclusion+guard for the SFC's coverage carve-out.
- `no-direct-router-imports.spec.ts` — components consume the router
  via `useRouter()` / `useRoute()` only. The wiring layer
  (`core/router`, `core/api`) is allowlisted.

Failing any of these indicates a structural rule was broken; fix the
violation, not the test.

## Recurring patterns

- **`useErrorMessage(err, te)` for ApiError → i18n key.** Pure
  function (not a composable). Resolves the error's `code` field to
  its bundled message and forwards `details[0].meta` as the
  interpolation bag. Network errors fall through to
  `auth.ui.errors.network`, unknown errors to `auth.ui.errors.unknown`.
- **Per-action loading flags.** `isLoggingIn`, `isEnrollingTotp`,
  `isVerifyingTotp`, `isDisablingTotp`, `isRegeneratingRecoveryCodes`.
  Concurrent actions never share a single coarse `isLoading`.
- **Optimistic update + best-effort refresh.** `verifyTotp()` and
  `disableTotp()` set `two_factor_enabled` locally on success, then
  fire `me()` as a silent refresh. Failed refresh is invisible.
- **Exclusion+guard for coverage carve-outs.** A file excluded from
  the coverage threshold gets a sibling architecture test that pins
  its shape (size, content). See `admin-auth.api.ts`, `AuthLayout.vue`,
  and `routes.ts`.
- **Component-local one-time secrets.** Recovery codes live in the
  page's `ref<readonly string[]>([])`, never the store. The enforcing
  architecture test extends to the display component itself.
- **No parent `data-test` on `<RecoveryCodesDisplay>`.** Vue 3
  single-root attribute fall-through replaces a child's root
  `data-test` when the parent provides one. The chunk-7.1 main hotfix
  surfaced this; the admin EnableTotpPage carries the same reminder
  comment so a future refactor cannot regress the contract.

## Chunk-7 design decisions

- **Mandatory MFA model** (`docs/05-SECURITY-COMPLIANCE.md` § 6.3).
  Every admin must enrol TOTP before they reach any non-auth route.
  Enforcement is layered:
  1. **Backend.** The `EnsureMfaForAdmins` middleware returns
     403 `auth.mfa.enrollment_required` on `/admin/me` when an admin's
     `two_factor_enabled = false`. Every admin-scoped route except the
     enrollment endpoints carries the same middleware.
  2. **Store.** `useAdminAuthStore.bootstrap()` catches the 403,
     leaves `user` populated, and flips `mfaEnrollmentRequired = true`.
  3. **Router.** `requireAuth` reads the flag and redirects to
     `/auth/2fa/enable`. The 2FA enrollment route deliberately omits
     `requireMfaEnrolled` — gating it would be a chicken-and-egg
     lockout.

- **D7 intended-destination preservation across the MFA redirect.**
  When `requireAuth` rebounds an unenrolled admin's deep-link to
  `/auth/2fa/enable`, the original destination is preserved as
  `?redirect=<fullPath>` on the redirect target. `EnableTotpPage.vue`
  honors `?redirect` after a successful enrollment, navigating the
  admin to the original target rather than to the dashboard. Main's
  identical-shape page hard-codes the dashboard because main's 2FA
  is opt-in (no enforcement redirect, nothing to preserve). The
  chunk-7.6 `admin-mandatory-mfa-enrollment.spec.ts` Playwright
  spec exercises this end-to-end (`/settings` deep-link → sign-in →
  enrollment → land on `/settings`).

- **Session-cookie boundary `catalyst_admin_session`.** Admin and
  main run as separate SPAs with separate session cookies. The
  path-aware `UseAdminSessionCookie` backend middleware swaps the
  cookie name before `StartSession` runs on every
  `/api/v1/admin/*` request. A user signed into the main SPA's
  catalyst_main_session does NOT automatically become an admin
  in the admin SPA — they have to sign in again on the admin
  surface, and only `platform_admin` users succeed. The chunk-5
  middleware enforces the type check.

- **`e2e-admin` CI stack** (chunk 7.6). Mirrors `e2e-main` with port
  offsets (admin API: `:8001`, admin Vite: `:5174`) so both jobs can
  run concurrently. The shared `TEST_HELPERS_TOKEN` rotation pattern
  is preserved verbatim. Admin's bespoke
  `POST /_test/users/admin` helper closes the gap that production
  sign-up cannot create admin users (Group 3 deviation #D1).

## How-to recipes

### Add a new admin route

1. Append a `RouteRecordRaw` to `routes.ts`. Use the existing records
   as templates — set `meta.layout` (`'auth'` / `'app'` / `'error'`)
   and `meta.guards` (string names; the dispatcher resolves them).
2. If the route requires a new guard, add it as a composable in
   `apps/admin/src/core/router/guards.ts` and extend the `GuardName`
   union in `routes.ts`. Add the dispatcher branch in
   `core/router/index.ts` AND the matching unit + branch tests.
3. Add the route's `data-test` selectors to
   `apps/admin/playwright/helpers/selectors.ts` if a Playwright spec
   will navigate to it.

### Add a new admin auth page

1. Add the page component under `pages/`. Mirror the shape of an
   existing page (script docblock, `<script setup>` imports, single
   form, error region, submit button with loading flag).
2. Add the matching `.spec.ts` next to the component. Use
   `tests/unit/helpers/mountAuthPage.ts` for the harness. Coverage
   must hit 100% lines + branches + functions; new branches earn new
   tests (see `EnableTotpPage.spec.ts` for the `?redirect` adaptation
   branches as an example).
3. Wire the page into `routes.ts` (replace any
   `() => import('@/core/pages/PlaceholderPage.vue')` slot, or add a
   new record).
4. If the page consumes a new error code, harvest it via the
   `i18n-auth-codes` architecture test and add the bundle entry for
   all three locales.

### Add a new admin E2E spec

1. Create the spec under `apps/admin/playwright/specs/`. Name it
   `admin-<flow>.spec.ts`.
2. Mirror the structure of `admin-sign-in.spec.ts` /
   `admin-mandatory-mfa-enrollment.spec.ts`: `test.describe` block,
   `beforeEach` neutralises `auth-ip`, `afterEach` restores and
   resets the clock.
3. Use the shared fixtures under `playwright/fixtures/test-helpers.ts`.
   Never spell the `X-Test-Helper-Token` header — it's forwarded
   automatically by `extraHTTPHeaders` in `playwright.config.ts`.
   Use `signUpAdminUser({ enrolled: true })` for a pre-enrolled
   subject, `enrolled: false` for the enrollment journey.
4. If the spec pins the test clock, baseline at
   `Date.now() + 30 days` (or further future) to avoid colliding
   with the session-cookie `Max-Age` window — the chunk-7.1 saga's
   T0 baseline. The shared `setClock(request, isoInstant)` fixture
   carries the docblock reminder.
5. Assertions anchor on `data-test` attributes (no English-string
   matches) so a future locale change does not flake the spec.
