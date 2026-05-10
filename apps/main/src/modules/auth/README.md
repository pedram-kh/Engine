# `apps/main/src/modules/auth/`

The auth module owns every user-visible auth surface in the main SPA:
sign-up, sign-in, email verification, password reset, and the full
2FA enrollment + sign-in flow. The module is responsible for the UI,
the page-level state, and the route table; the wire layer (HTTP,
error envelopes) lives in `@catalyst/api-client`, the cross-cutting
401 policy + idle timeout live in `apps/main/src/core/`. This README
is the orientation for a future contributor; it assumes the reader
has already read `docs/04-API-DESIGN.md`, `docs/05-SECURITY-COMPLIANCE.md § 6`,
and `docs/02-CONVENTIONS.md`.

## Where to start reading

- **`stores/useAuthStore.ts`** — the data hub. Every page consumes
  this store; every backend call goes through it. The `bootstrap()`
  action is the cold-load identity probe (chunks 6.4 / 6.5); the
  per-action loading flags drive the UI's disabled / loading states.
- **`routes.ts`** — the declarative route table. Each record carries
  `meta.layout` (consumed by `App.vue`'s layout switcher) and
  `meta.guards` (resolved by `core/router/index.ts`'s dispatcher).
- **`pages/SignInPage.vue`** — a representative consumer of the
  store + the `useErrorMessage` resolver. Reading it shows how a
  page composes loading flags, inline error rendering, and the
  in-page TOTP transition on `auth.mfa_required`.
- **`components/RecoveryCodesDisplay.vue`** — the most invariant-
  heavy component in the module: codes flow in via prop only, never
  enter Pinia state, and the 5-second confirm gate is enforced
  unconditionally with no skip path.

## Architecture tests that protect the module

Source-inspection tests under `apps/main/tests/unit/architecture/`
catch architectural drift the type system can't:

- `no-direct-http.spec.ts` — no `import 'axios'` or `fetch(` outside
  `@catalyst/api-client`. Forces every backend call through the
  shared HTTP layer.
- `no-recovery-codes-in-store.spec.ts` — no Pinia state field whose
  name matches `/recovery_?codes?/i`, AND `RecoveryCodesDisplay.vue`
  must not import `useAuthStore`.
- `i18n-auth-codes.spec.ts` — every `auth.*` literal harvested from
  `apps/api/app/Modules/Identity/**/*.php` resolves in all three
  locale bundles (en, pt, it).
- `auth-api-reexport-shape.spec.ts` — `api/auth.api.ts` is a pure
  re-export (≤ 12 significant lines, only `import` / `export`
  statements). Exclusion+guard for the coverage carve-out.
- `auth-layout-shape.spec.ts` — `AuthLayout.vue` stays a structural
  shell (≤ 80 lines, no multi-statement arrows in `<script setup>`).
  Exclusion+guard for the SFC's coverage carve-out.
- `no-direct-router-imports.spec.ts` — components consume the
  router via `useRouter()` / `useRoute()` only. The wiring layer
  (`core/router`, `core/api`) is allowlisted.

Failing any of these indicates a structural rule was broken; fix the
violation, not the test.

## Recurring patterns

- **`useErrorMessage(err, te)` for ApiError → i18n key.** Pure
  function (not a composable). Resolves the error's `code` field
  to its bundled message and forwards `details[0].meta` as the
  interpolation bag. Network errors fall through to
  `auth.ui.errors.network`, unknown errors to `auth.ui.errors.unknown`.
- **Per-action loading flags.** `isLoggingIn`, `isLoggingOut`, etc.
  Concurrent actions never share a single coarse `isLoading`.
- **Optimistic update + best-effort refresh.** `verifyTotp()` and
  `disableTotp()` set `two_factor_enabled` locally on success, then
  fire `me()` as a silent refresh. Failed refresh is invisible.
- **Exclusion+guard for coverage carve-outs.** A file excluded from
  the coverage threshold gets a sibling architecture test that pins
  its shape (size, content). See `auth.api.ts` and `AuthLayout.vue`.
- **Component-local one-time secrets.** Recovery codes live in the
  page's `ref<readonly string[]>([])`, never the store. The
  enforcing architecture test extends to the display component
  itself.
