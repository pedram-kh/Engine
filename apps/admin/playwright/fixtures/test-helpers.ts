import type { APIRequestContext } from '@playwright/test'

/**
 * Typed wrappers around the chunk 6.1 `App\TestHelpers` HTTP surface,
 * scoped to admin-suite consumption (chunk 7.6).
 *
 * Mirror of `apps/main/playwright/fixtures/test-helpers.ts` (chunks
 * 6.8 + 7.1 hotfix saga) with two structurally-correct admin
 * adaptations:
 *
 *   - `API_BASE_URL` defaults to `http://127.0.0.1:8001` (admin's
 *     offset Laravel port — see `apps/admin/playwright.config.ts`)
 *     rather than `:8000`. Overridable via `CATALYST_ADMIN_API_BASE_URL`
 *     for ad-hoc runs against an alternate stack.
 *
 *   - The production sign-up endpoint cannot create admin users
 *     (admin onboarding is out-of-band per `docs/20-PHASE-1-SPEC.md`
 *     § 5), so the admin equivalent of `signUpUser` is
 *     `signUpAdminUser` — it calls the chunk-7.6 test-helper
 *     `POST /_test/users/admin` endpoint rather than the production
 *     sign-up surface. The two helpers share the same defaultHeaders
 *     convention (Accept: application/json + X-Requested-With:
 *     XMLHttpRequest) from the chunk-7.1 hotfix saga.
 *
 * Specs use Playwright's `request` fixture (NOT the SPA's HTTP path)
 * to call these helpers. `request` shares cookies with the page when
 * accessed via `page.context().request`, so a helper-driven sign-in
 * lands the user in the same session the page sees.
 *
 * The `X-Test-Helper-Token` header is forwarded automatically by the
 * `extraHTTPHeaders` block in `playwright.config.ts` — these wrappers
 * never spell it.
 *
 * Returned values are typed; specs read named fields rather than raw
 * `Response` objects. A non-2xx response from any helper throws a
 * descriptive error so a misconfigured spec fails loudly instead of
 * silently asserting against undefined.
 */

/**
 * Headers every fixture forwards on its outbound request.
 *
 * Why both headers
 * ----------------
 * - `Accept: application/json` flips Laravel's exception-handler
 *   branch from "redirect to named login route" (HTML response) to
 *   "return 401 JSON envelope". Without it, an unauthenticated
 *   request to a protected endpoint hits Laravel's default
 *   `redirectTo()` path which calls `route('login')` — and this
 *   API-only Laravel app has no named `login` route, so the
 *   exception cascades to a `RouteNotFoundException` 500 with an
 *   HTML error page. Spec #19's `signOutViaApi` step surfaced this
 *   in CI for main (chunk-7.1 post-merge hotfix); admin manifests
 *   the finding from the first commit (no replay of the saga).
 * - `X-Requested-With: XMLHttpRequest` is the conventional XHR
 *   sentinel honored by Laravel's `Request::expectsJson()` and by
 *   most middleware-driven content-negotiation paths. Belt-and-
 *   suspenders alongside the `Accept` header.
 *
 * Mirrors the chunk-5 SPA `apiClient` convention: every state-
 * changing request that originates from the SPA sets the same pair.
 * Fixtures follow suit so a future fixture author who copy-pastes
 * an existing wrapper inherits the contract for free.
 *
 * Forwarded by every wrapper below. New fixtures should spread this
 * into their `request.*` options' `headers` field rather than re-
 * declaring the literal — reduces drift if the convention extends.
 */
const defaultHeaders = {
  Accept: 'application/json',
  'X-Requested-With': 'XMLHttpRequest',
} as const

/**
 * Admin-suite API base URL. Defaults to the chunk-7.6 admin-stack
 * port (8001); overridable via `CATALYST_ADMIN_API_BASE_URL` if a
 * spec authoritatively needs to drive a different stack.
 */
const API_BASE_URL = process.env.CATALYST_ADMIN_API_BASE_URL ?? 'http://127.0.0.1:8001'

function url(path: string): string {
  return `${API_BASE_URL}${path}`
}

export interface SignUpAdminUserOptions {
  enrolled?: boolean
  name?: string
}

export interface SignUpAdminUserResult {
  email: string
  password: string
  /**
   * Present only when `enrolled === true`. The base32 secret stamped
   * on `users.two_factor_secret` for the new admin row; passed to
   * `mintTotpFromSecret` to derive the current 6-digit code at sign-in.
   */
  twoFactorSecret: string | null
}

/**
 * Provision a fresh `platform_admin` user via the chunk-7.6
 * `POST /_test/users/admin` helper. Production sign-up rejects
 * `platform_admin` (admin onboarding is out-of-band) so admin specs
 * cannot drive their own subject through the production surface;
 * this is the equivalent gap the chunk-7.4 router-store + the
 * chunk-7.6 test-helper plus this fixture together close.
 *
 * Pass `enrolled: true` to seed the user with a known
 * `two_factor_secret` + `two_factor_confirmed_at = now()`. The
 * happy-path sign-in spec uses this branch; the mandatory-MFA
 * enrollment journey uses `enrolled: false` (default) so the
 * /me 403 → /auth/2fa/enable redirect fires.
 */
export async function signUpAdminUser(
  request: APIRequestContext,
  email: string,
  password: string,
  options: SignUpAdminUserOptions = {},
): Promise<SignUpAdminUserResult> {
  const response = await request.post(url('/api/v1/_test/users/admin'), {
    headers: defaultHeaders,
    data: {
      email,
      password,
      name: options.name ?? 'Admin User',
      enrolled: options.enrolled === true,
    },
  })

  if (response.status() !== 201) {
    throw new Error(
      `signUpAdminUser failed with status ${response.status()}: ${await response.text()}`,
    )
  }

  const body = (await response.json()) as {
    data: { email: string; two_factor_secret: string | null }
  }
  return {
    email: body.data.email,
    password,
    twoFactorSecret: body.data.two_factor_secret,
  }
}

export interface MintTotpResult {
  code: string
}

/**
 * Mint the current 6-digit TOTP code for the supplied base32 secret.
 *
 * Used by the chunk-7.6 happy-path spec for an admin that was
 * pre-enrolled via `signUpAdminUser({ enrolled: true })` — the secret
 * lives on the row and the helper produces the matching code on
 * demand. Also used by the mandatory-MFA enrollment journey when
 * the SPA renders the secret inside `enable-totp-manual-key` mid-
 * enrollment (the cache-resident path, mirroring main's spec #19).
 */
export async function mintTotpFromSecret(
  request: APIRequestContext,
  secret: string,
): Promise<MintTotpResult> {
  const response = await request.post(url('/api/v1/_test/totp/secret'), {
    headers: defaultHeaders,
    data: { secret },
  })

  if (response.status() !== 200) {
    throw new Error(
      `mintTotpFromSecret failed with status ${response.status()}: ${await response.text()}`,
    )
  }

  const body = (await response.json()) as { data: { code: string } }
  return { code: body.data.code }
}

/**
 * Pin the application clock to the given ISO 8601 instant via the
 * chunk-6.1 test-clock surface. Subsequent backend requests will see
 * `Carbon::now()` return the pinned instant until the next call to
 * `setClock()` or `resetClock()`.
 *
 * Specs SHOULD always pair `setClock()` calls with an `afterEach`
 * `resetClock()` so a stray test does not bleed pinned time into the
 * next.
 *
 * T0 baseline: chunk-7.1 hotfix established that specs using
 * `setClock` MUST baseline at `Date.now() + 30 days` (or further
 * future) to avoid colliding with the session-cookie `Max-Age`
 * window — Carbon's `Carbon::now()` honors `setTestNow`, but the
 * cookie's expiry instant is computed from PHP's wall-clock `time()`
 * at issue time, so a clock pinned to `Date.now() - <hours>` makes
 * Sanctum return the cookie with `Max-Age = -<seconds>` and the
 * browser drops it immediately. Admin specs manifest the convention
 * from the first commit.
 */
export async function setClock(request: APIRequestContext, isoInstant: string): Promise<void> {
  const response = await request.post(url('/api/v1/_test/clock'), {
    headers: defaultHeaders,
    data: { at: isoInstant },
  })

  if (response.status() !== 200) {
    throw new Error(
      `setClock(${isoInstant}) failed with status ${response.status()}: ${await response.text()}`,
    )
  }
}

/**
 * Release the pinned application clock so backend requests see real
 * wall-clock time again.
 */
export async function resetClock(request: APIRequestContext): Promise<void> {
  const response = await request.post(url('/api/v1/_test/clock/reset'), {
    headers: defaultHeaders,
  })

  if (response.status() !== 200) {
    throw new Error(`resetClock failed with status ${response.status()}: ${await response.text()}`)
  }
}

/**
 * Names of the four production rate limiters a spec is allowed to
 * neutralise. Mirrors the backend's
 * `App\TestHelpers\Services\RateLimiterNeutralizer::ALLOWED_NAMES`.
 * Adding a name here without a matching backend allowlist entry will
 * 422 at the helper call.
 */
export type ThrottleName =
  | 'auth-ip'
  | 'auth-login-email'
  | 'auth-password'
  | 'auth-resend-verification'

/**
 * Override the named Laravel rate limiter to `Limit::none()` so the
 * application-level lockout layer can be exercised in isolation
 * (chunk-7.1 spec #20 design choice — option (i), mirroring the
 * chunk-5 `LoginTest::beforeEach` Pest pattern).
 *
 * REQUIRES afterEach restoreThrottle
 * ----------------------------------
 * The neutralised state lives in shared cache and survives across
 * tests — that's the whole point: `php artisan serve` spawns a fresh
 * PHP process per request and the override has to persist. Every
 * spec that calls this MUST pair with `restoreThrottle` in
 * `afterEach`.
 */
export async function neutralizeThrottle(
  request: APIRequestContext,
  name: ThrottleName,
): Promise<void> {
  const response = await request.post(
    url(`/api/v1/_test/rate-limiter/${encodeURIComponent(name)}`),
    { headers: defaultHeaders },
  )

  if (response.status() !== 200) {
    throw new Error(
      `neutralizeThrottle(${name}) failed with status ${response.status()}: ${await response.text()}`,
    )
  }
}

/**
 * Restore the named Laravel rate limiter to its production callback.
 *
 * Idempotent — calling restore on a name that was never neutralised
 * is a no-op. Specs SHOULD call this in `afterEach` even when they
 * think they cleaned up inline; cache-state-bleed is exactly the
 * failure mode this pair guards against.
 */
export async function restoreThrottle(
  request: APIRequestContext,
  name: ThrottleName,
): Promise<void> {
  const response = await request.delete(
    url(`/api/v1/_test/rate-limiter/${encodeURIComponent(name)}`),
    { headers: defaultHeaders },
  )

  if (response.status() !== 200) {
    throw new Error(
      `restoreThrottle(${name}) failed with status ${response.status()}: ${await response.text()}`,
    )
  }
}

/**
 * Sign out via the admin production logout endpoint
 * (`/api/v1/admin/auth/logout`). The chunk 7.5 admin SPA does not
 * yet expose a sign-out UI element (settings landing is a placeholder
 * until a later sprint), so specs use this fixture to flip the
 * authenticated state from a known one.
 *
 * Cookie is shared with the page via `page.context().request`, so a
 * subsequent `page.goto()` lands on a cold session.
 */
export async function signOutViaApi(request: APIRequestContext): Promise<void> {
  const response = await request.post(url('/api/v1/admin/auth/logout'), {
    headers: defaultHeaders,
  })

  // 204 (signed-in path) or 401 (already signed out — race with
  // expiry) are both acceptable; anything else is a regression.
  if (response.status() !== 204 && response.status() !== 401) {
    throw new Error(
      `signOutViaApi failed with status ${response.status()}: ${await response.text()}`,
    )
  }
}
