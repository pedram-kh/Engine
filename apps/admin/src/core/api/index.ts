import { createAuthApi, createHttpClient } from '@catalyst/api-client'

/**
 * Singleton HTTP client wired to the Laravel backend. Every API call
 * across the admin SPA goes through this instance so Sanctum SPA cookie
 * auth, CSRF preflight, and `ApiError` normalization land in exactly
 * one place (`docs/02-CONVENTIONS.md § 3.6`).
 *
 * `VITE_API_BASE_URL` defaults to `/api/v1` in dev; the Vite proxy in
 * `vite.config.ts` forwards both `/api` and `/sanctum` to
 * `http://127.0.0.1:8000`.
 *
 * The `onUnauthorized` policy hook mirrors `apps/main/src/core/api/index.ts`
 * (chunk 6.5): any 401 from a request other than the cold-load identity
 * probe (`/admin/me`) and the wrong-password channel
 * (`/admin/auth/login`) clears the in-memory admin user and redirects
 * to the sign-in page with `?reason=session_expired`. The cold-load
 * 401 is normal (`bootstrap()` resolves it to "anonymous session,
 * ready"); the sign-in 401 is the legitimate wrong-password path the
 * page handles inline — neither should clobber the page's own UI.
 *
 * The exempt-paths set is narrower than main's: main allowlists both
 * `/me` and `/admin/me` because chunks 6.5–6.7 pre-staged the admin
 * paths in main's policy for the future-cross-SPA case. The admin SPA's
 * api-client variant only ever hits admin paths
 * (`createAuthApi(http, { variant: 'admin' })`), so we only need to
 * exempt the admin endpoints here. The narrowing is the
 * structurally-correct shape given the variant-pattern isolation
 * established in chunk 7.2-7.3 Group 1 (Deviation #1).
 *
 * The interceptor is plugged in via the api-client's `onUnauthorized`
 * callback rather than an axios response interceptor here. Direct axios
 * use outside `@catalyst/api-client` is forbidden by the architecture
 * test in `tests/unit/architecture/no-direct-http.spec.ts`; the callback
 * is the architecture-compliant equivalent. See chunk 6.5 OQ-1 for the
 * deviation rationale main went through; admin inherits the
 * structurally-correct shape from the start.
 *
 * The callback uses dynamic imports for `useAdminAuthStore` and
 * `router` to defer evaluation past module-load. This both breaks the
 * `core/api ↔ useAdminAuthStore` import cycle (the store imports
 * `authApi` from this module via the `admin-auth.api.ts` re-export)
 * and matches the runtime contract — the callback only fires after
 * Vue has mounted, by which time both Pinia and the router are fully
 * wired into the application.
 */
export const SESSION_EXPIRED_QUERY_REASON = 'session_expired'

/**
 * Paths that legitimately return 401 without it meaning "session
 * expired":
 *   - `/admin/me` — cold-load identity probe; the store resolves it
 *     to the anonymous-ready state.
 *   - `/admin/auth/login` — wrong-password is signalled here; the
 *     sign-in page (sub-chunk 7.5) renders the i18n error inline.
 *
 * Any 401 from any OTHER endpoint means the session expired
 * mid-flight, which is the case the interceptor exists to handle.
 */
const UNAUTHORIZED_EXEMPT_PATHS: ReadonlyArray<string> = ['/admin/me', '/admin/auth/login']

/**
 * Pure decision function — exported so the unit test can exercise the
 * matrix without spinning the full HTTP stack.
 */
export function shouldHandleUnauthorized(path: string): boolean {
  return !UNAUTHORIZED_EXEMPT_PATHS.includes(path)
}

/**
 * Build the 401 policy callback the api-client invokes on any 401. The
 * callback is exported (not just inlined into `createHttpClient`) so
 * the unit test can verify the decision matrix + the side-effects in
 * isolation.
 *
 * The two collaborators are passed through as overridable parameters
 * so the unit test can substitute fakes without monkey-patching the
 * live Pinia store / router. Production wiring uses the dynamic-import
 * lazy loaders below.
 */
export interface UnauthorizedPolicyDeps {
  getStore: () => Promise<{ clearUser: () => void }>
  getRouter: () => Promise<{
    push: (location: { name: string; query: Record<string, string> }) => unknown
  }>
}

export function createUnauthorizedPolicy(deps: UnauthorizedPolicyDeps) {
  return async (path: string): Promise<void> => {
    if (!shouldHandleUnauthorized(path)) {
      return
    }
    const [storeMod, routerMod] = await Promise.all([deps.getStore(), deps.getRouter()])
    storeMod.clearUser()
    void routerMod.push({
      name: 'auth.sign-in',
      query: { reason: SESSION_EXPIRED_QUERY_REASON },
    })
  }
}

/* c8 ignore start -- @preserve: production wiring; tests exercise createUnauthorizedPolicy with fakes. */
const productionPolicy = createUnauthorizedPolicy({
  getStore: async () => {
    const mod = await import('@/modules/auth/stores/useAdminAuthStore')
    return mod.useAdminAuthStore()
  },
  getRouter: async () => {
    const mod = await import('@/core/router')
    return mod.router
  },
})
/* c8 ignore stop */

export const http = createHttpClient({
  baseUrl: import.meta.env.VITE_API_BASE_URL ?? '/api/v1',
  /* c8 ignore next 3 -- @preserve: forwards to productionPolicy; createUnauthorizedPolicy is what the unit test exercises. */
  onUnauthorized: (path) => {
    void productionPolicy(path)
  },
})

/**
 * Typed authentication API bound to the admin SPA's surface
 * (`/admin/me`, `/admin/auth/*`). The admin Pinia store consumes this
 * via the module-local re-export at
 * `apps/admin/src/modules/auth/api/admin-auth.api.ts`.
 */
export const authApi = createAuthApi(http, { variant: 'admin' })
