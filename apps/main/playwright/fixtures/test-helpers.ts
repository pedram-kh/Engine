import type { APIRequestContext, Page } from '@playwright/test'

/**
 * Typed wrappers around the chunk 6.1 `App\TestHelpers` HTTP surface.
 *
 * Specs use Playwright's `request` fixture (NOT the SPA's HTTP path)
 * to call these helpers. `request` shares cookies with the page when
 * accessed via `page.context().request`, so a helper-driven sign-up
 * lands the user in the same session the page sees.
 *
 * The `X-Test-Helper-Token` header is forwarded automatically by the
 * `extraHTTPHeaders` block in `playwright.config.ts` — these wrappers
 * never spell it.
 *
 * `defaultHeaders` (below) is forwarded by every wrapper so the call
 * self-identifies as a JSON API request — see the constant's docblock
 * for the chunk-7.1 post-merge hotfix discovery context.
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
 *   in CI (chunk-7.1 post-merge hotfix); see the tech-debt entry
 *   "Laravel exception handler returns HTML/redirect for
 *   unauthenticated /api/v1/* requests without Accept: application/json"
 *   for the long-form context and the deferred backend resolution.
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

export interface SignUpUserResult {
  email: string
  password: string
}

/**
 * Sign up a new user via the production sign-up endpoint (NOT a
 * test-helper). The user is created with `email_verified_at = NULL`,
 * which is fine for chunk 6.8 because the LoginController does not
 * gate on email verification. Specs that need a verified email call
 * `mintVerificationToken` and navigate the SPA to the
 * `/verify-email/confirm?token=…` route.
 */
export async function signUpUser(
  request: APIRequestContext,
  email: string,
  password: string,
  name: string = 'Test User',
): Promise<SignUpUserResult> {
  const response = await request.post('http://127.0.0.1:8000/api/v1/auth/sign-up', {
    headers: defaultHeaders,
    data: {
      name,
      email,
      password,
      password_confirmation: password,
    },
  })

  if (response.status() !== 201 && response.status() !== 200) {
    throw new Error(`signUpUser failed with status ${response.status()}: ${await response.text()}`)
  }

  return { email, password }
}

export interface MintTotpResult {
  code: string
}

/**
 * Mint the current 6-digit TOTP code for the user with the given
 * email. Calls the chunk-6.8 email branch of `IssueTotpController` —
 * specs use email because the SPA never exposes the user's numeric
 * primary key.
 *
 * REQUIRES the user to have already completed `/2fa/confirm` so the
 * secret is persisted on `users.two_factor_secret`. For the in-flight
 * case (after `/2fa/enable` but before `/2fa/confirm`), use
 * {@link mintTotpFromSecret} instead — that path takes the secret
 * directly because the in-flight secret lives in cache, not on the row.
 */
export async function mintTotpCodeForEmail(
  request: APIRequestContext,
  email: string,
): Promise<MintTotpResult> {
  const response = await request.post('http://127.0.0.1:8000/api/v1/_test/totp', {
    headers: defaultHeaders,
    data: { email },
  })

  if (response.status() !== 200) {
    throw new Error(
      `mintTotpCodeForEmail failed with status ${response.status()}: ${await response.text()}`,
    )
  }

  const body = (await response.json()) as { data: { code: string } }
  return { code: body.data.code }
}

/**
 * Mint the current 6-digit TOTP code for the supplied base32 secret.
 *
 * Used by the chunk-7.1 spec #19 redesign: during in-flight 2FA
 * enrollment, the secret lives in cache (key prefix
 * `identity:2fa:enroll:`) until `/2fa/confirm` lands. The persisted
 * `users.two_factor_secret` column is NULL until then, so
 * {@link mintTotpCodeForEmail} 422s. The SPA renders the secret as
 * plain text inside the `enable-totp-manual-key` `data-test`
 * element (so the user can type it into an authenticator app); the
 * spec reads the same DOM text and forwards it here.
 *
 * Cookie context: this fixture does not depend on a session — the
 * helper is gated by the chunk-6.1 `X-Test-Helper-Token` header
 * (forwarded automatically by `extraHTTPHeaders` in
 * `playwright.config.ts`), and the secret it receives is the
 * authoritative input. The user-by-email branch is intentionally
 * absent: see the `IssueTotpFromSecretController` class docblock for
 * the cache-vs-row reasoning.
 */
export async function mintTotpFromSecret(
  request: APIRequestContext,
  secret: string,
): Promise<MintTotpResult> {
  const response = await request.post('http://127.0.0.1:8000/api/v1/_test/totp/secret', {
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

export interface MintVerificationTokenResult {
  token: string
  verificationUrl: string
}

/**
 * Mint a fresh email-verification token for the user with the given
 * email. The returned `token` is the value the SPA's
 * `/verify-email/confirm?token=…` route consumes.
 *
 * The helper's `verification_url` field is included for completeness
 * but specs MUST NOT rely on its path matching the SPA route — the
 * helper's URL targets `/auth/verify-email`, while the SPA route is
 * `/verify-email/confirm` (chunk 6.6). Specs construct the SPA URL
 * themselves using the returned `token`.
 */
export async function mintVerificationToken(
  request: APIRequestContext,
  email: string,
): Promise<MintVerificationTokenResult> {
  const response = await request.get(
    `http://127.0.0.1:8000/api/v1/_test/verification-token?email=${encodeURIComponent(email)}`,
    { headers: defaultHeaders },
  )

  if (response.status() !== 200) {
    throw new Error(
      `mintVerificationToken failed with status ${response.status()}: ${await response.text()}`,
    )
  }

  const body = (await response.json()) as {
    data: { token: string; verification_url: string }
  }
  return { token: body.data.token, verificationUrl: body.data.verification_url }
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
 */
export async function setClock(request: APIRequestContext, isoInstant: string): Promise<void> {
  const response = await request.post('http://127.0.0.1:8000/api/v1/_test/clock', {
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
  const response = await request.post('http://127.0.0.1:8000/api/v1/_test/clock/reset', {
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
 * spec that calls this MUST pair with {@link restoreThrottle} in
 * `afterEach`. An un-restored neutraliser bleeds into every
 * subsequent spec on the same suite run; the production-shape
 * assertions of unrelated specs become meaningless.
 *
 * The convention is identical to {@link setClock} / {@link resetClock}
 * for the chunk-6.1 test clock — pair the mutation with its inverse,
 * and pin the inverse on `afterEach`.
 */
export async function neutralizeThrottle(
  request: APIRequestContext,
  name: ThrottleName,
): Promise<void> {
  const response = await request.post(
    `http://127.0.0.1:8000/api/v1/_test/rate-limiter/${encodeURIComponent(name)}`,
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
 * is a no-op (200 with an empty `neutralized` list). Specs SHOULD
 * call this in `afterEach` even when they think they cleaned up
 * inline; the cache-state-bleed is exactly the failure mode this
 * pair guards against.
 */
export async function restoreThrottle(
  request: APIRequestContext,
  name: ThrottleName,
): Promise<void> {
  const response = await request.delete(
    `http://127.0.0.1:8000/api/v1/_test/rate-limiter/${encodeURIComponent(name)}`,
    { headers: defaultHeaders },
  )

  if (response.status() !== 200) {
    throw new Error(
      `restoreThrottle(${name}) failed with status ${response.status()}: ${await response.text()}`,
    )
  }
}

/**
 * Sign out via the production logout endpoint. The chunk 7 nav
 * surface will provide a UI sign-out button; until then specs use
 * this fixture so the chunk-6.8 specs do not need a UI placeholder
 * just for the sign-out interaction.
 *
 * Cookie is shared with the page via `page.context().request`, so a
 * subsequent `page.goto()` lands on a cold session.
 */
export async function signOutViaApi(request: APIRequestContext): Promise<void> {
  const response = await request.post('http://127.0.0.1:8000/api/v1/auth/logout', {
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

/**
 * Pre-populate the browser's session + XSRF-TOKEN cookies so the SPA's
 * first page-driven POST does not 419 on the CSRF preflight.
 *
 * Why this helper exists
 * ----------------------
 * The chunk-3 SPA `apiClient` does a `GET /sanctum/csrf-cookie`
 * preflight before every state-changing request and forwards the
 * resulting `XSRF-TOKEN` cookie back as the `X-XSRF-TOKEN` header
 * (`packages/api-client/src/http.ts`). On paper this is the canonical
 * Sanctum SPA flow and SHOULD work cold.
 *
 * In practice, when a Playwright spec hits the SPA's first state-
 * changing call from a totally cold browser context (no prior page
 * activity, no prior fixture call that shared cookies via
 * `page.context().request`), the preflight does not reliably leave a
 * usable XSRF-TOKEN cookie behind for the immediately-following POST.
 * The POST lands without a matching `X-XSRF-TOKEN` and Laravel's
 * `VerifyCsrfToken` returns HTTP 419 ("CSRF token mismatch."). The
 * resulting page renders the SPA's generic `auth.errors.unknown`
 * fallback because `auth.csrf_mismatch` is not in the i18n bundle and
 * the response shape (Laravel debug-mode HtmlException, not the
 * standard `errors[]` envelope) carries no resolvable code anyway.
 *
 * Spec #19 never trips this because every page-driven login is
 * preceded by browser activity that already established session +
 * XSRF cookies (page-driven sign-up at step 1, OR steps 2-5's full
 * auth + 2FA flow before step 7's re-sign-in). Spec #20 is the only
 * suite member whose first page-driven login lands on a fully cold
 * browser context — and the only one that 419s.
 *
 * What this helper does
 * ---------------------
 * Issues a browser-side `fetch('/sanctum/csrf-cookie')` so the cookies
 * are set in the page context BEFORE the SPA's apiClient preflight
 * runs. The SPA's own preflight on the immediately-following form
 * submit then refreshes (rather than initialises) the cookies, and the
 * POST sees a matching token.
 *
 * Caller contract
 * ---------------
 * The page must already be on the SPA origin (`http://127.0.0.1:5173`)
 * — typically via a prior `await page.goto('/sign-in')`. The helper
 * does NOT navigate; it only runs the in-page fetch. This keeps the
 * caller in control of which navigation the warm-up follows.
 *
 * Tech-debt linkage
 * -----------------
 * This is a spec-level workaround. The root-cause investigation
 * (browser cookie state on first preflight, possible Vite-proxy
 * Set-Cookie quirk, possible apiClient race) is captured in
 * `docs/tech-debt.md` under "SPA apiClient CSRF preflight 419s on
 * cold browser context (Playwright workaround in `warmCsrfCookie`)".
 * Future specs that drive page-level form submissions from cold
 * cookie state should reuse this helper rather than re-implementing
 * the workaround inline.
 */
export async function warmCsrfCookie(page: Page): Promise<void> {
  await page.evaluate(async () => {
    const response = await fetch('/sanctum/csrf-cookie', {
      credentials: 'include',
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
    })
    if (!response.ok) {
      throw new Error(`warmCsrfCookie: GET /sanctum/csrf-cookie returned ${response.status}`)
    }
  })
}
