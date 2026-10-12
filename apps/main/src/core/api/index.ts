import { createAuthApi, createHttpClient } from '@catalyst/api-client'

import { nativeTokenStore } from '@/core/auth/tokenStore'
import { isNativePlatform } from '@/core/platform/isNativePlatform'

/**
 * Singleton HTTP client wired to the Laravel backend. Every API call
 * across the main SPA goes through this instance so Sanctum SPA cookie
 * auth, CSRF preflight, and `ApiError` normalization land in exactly
 * one place (`docs/02-CONVENTIONS.md § 3.6`).
 *
 * AH-100: inside the Capacitor creator app the same client runs in
 * bearer mode (token from {@link nativeTokenStore}, no cookies, no CSRF)
 * with the native 401 policy below. The web build passes no `auth`
 * option at all, so its transport is the cookie mode it always was.
 *
 * `VITE_API_BASE_URL` defaults to `/api/v1` in dev; the Vite proxy in
 * `vite.config.ts` forwards both `/api` and `/sanctum` to
 * `http://127.0.0.1:8000`.
 *
 * The `onUnauthorized` policy hook is the chunk-6.5 401 interceptor:
 * any 401 from a request other than the cold-load identity probes
 * (`/me`, `/admin/me`) and the wrong-password channel (`/auth/login`)
 * clears the in-memory user and redirects to the sign-in page with a
 * `?reason=session_expired` query param. The cold-load 401 is normal
 * (`bootstrap()` resolves it to "anonymous session, ready"), and the
 * sign-in 401 is the legitimate wrong-password path the page handles
 * inline — neither should clobber the page's own UI.
 *
 * The interceptor is plugged in via the api-client's `onUnauthorized`
 * callback rather than an axios response interceptor here. Direct axios
 * use outside `@catalyst/api-client` is forbidden by the architecture
 * test in `tests/unit/architecture/no-direct-http.spec.ts`; the callback
 * is the architecture-compliant equivalent. See the chunk-6.5 review
 * file for the full deviation note.
 *
 * The callback uses dynamic imports for `useAuthStore` and `router` to
 * defer evaluation past module-load. This both breaks the
 * `core/api ↔ useAuthStore` import cycle (the store imports `authApi`
 * from this module) and matches the runtime contract — the callback
 * only fires after Vue has mounted, by which time both Pinia and the
 * router are fully wired into the application.
 */
export const SESSION_EXPIRED_QUERY_REASON = 'session_expired'

/** The slice of a router's current route {@link sessionExpiredLocation} reads. */
export interface CurrentRouteSlice {
  fullPath: string
  name?: string | symbol | null
}

/**
 * AH-103 — where the creator app sends a user whose session lapsed: the sign-in
 * screen with the expiry banner, carrying `redirect=<where they were>` so a
 * successful sign-in (`SignInPage.postLoginTarget` already honours `redirect`)
 * returns them to it — e.g. the screen a notification tap had just opened.
 *
 * `redirect` is OMITTED when there is nothing worth returning to: the root, or an
 * auth / error screen (returning to sign-in from sign-in is a loop, and the
 * bootstrap-error screen is not a destination). `SignInPage` also ignores `/`.
 */
export function sessionExpiredLocation(current?: CurrentRouteSlice | null): {
  name: 'auth.sign-in'
  query: Record<string, string>
} {
  const query: Record<string, string> = { reason: SESSION_EXPIRED_QUERY_REASON }

  if (current !== undefined && current !== null && isReturnableRoute(current)) {
    query.redirect = current.fullPath
  }

  return { name: 'auth.sign-in', query }
}

function isReturnableRoute(route: CurrentRouteSlice): boolean {
  if (route.fullPath === '' || route.fullPath === '/') {
    return false
  }

  return !(
    typeof route.name === 'string' &&
    (route.name.startsWith('auth.') || route.name.startsWith('error.'))
  )
}

/**
 * Paths that legitimately return 401 without it meaning "session
 * expired":
 *   - `/me`, `/admin/me` — cold-load identity probes; the store
 *     resolves them to the anonymous-ready state.
 *   - `/auth/impersonation/status` — the cold-load impersonation-banner
 *     probe (App.vue, every route incl. public ones). For an anonymous
 *     visitor it 401s by design; the store swallows it to "not
 *     impersonating". Treating it as session-expired would redirect
 *     anonymous visitors off public pages (sign-up, invitation accept).
 *   - `/auth/login`, `/admin/auth/login` — wrong-password is signalled
 *     here; the sign-in page renders the i18n error inline.
 *
 * Any 401 from any OTHER endpoint means the session expired mid-flight,
 * which is the case the interceptor exists to handle.
 */
const UNAUTHORIZED_EXEMPT_PATHS: ReadonlyArray<string> = [
  '/me',
  '/admin/me',
  '/auth/impersonation/status',
  '/auth/login',
  '/admin/auth/login',
]

/**
 * Pure decision function — exported so the unit test can exercise the
 * matrix without spinning the full HTTP stack.
 */
export function shouldHandleUnauthorized(path: string): boolean {
  return !UNAUTHORIZED_EXEMPT_PATHS.includes(path)
}

/**
 * Build the 401 policy callback the api-client invokes on any 401. The
 * callback is exported (not just inlined into `createHttpClient`) so the
 * unit test can verify the decision matrix + the side-effects in
 * isolation.
 *
 * The two collaborators are passed through as overridable parameters so
 * the unit test can substitute fakes without monkey-patching the live
 * Pinia store / router. Production wiring uses the dynamic-import lazy
 * loaders below.
 */
export interface UnauthorizedPolicyDeps {
  getStore: () => Promise<{ clearUser: () => void }>
  getRouter: () => Promise<{
    push: (location: { name: string; query: Record<string, string> }) => unknown
    /** Optional: only the native policy reads it (AH-103, `redirect=`). */
    currentRoute?: { value: CurrentRouteSlice }
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

/**
 * AH-100 — the creator app's 401 exemptions. `/auth/token` is the
 * wrong-password channel; `/me` is the cold-load probe (the store clears
 * a rejected token itself); `/auth/impersonation/status` is the
 * every-route banner probe that 401s for anonymous visitors by design,
 * exactly as on the web.
 *
 * AH-100b D3 — `/auth/logout` joins them: a 401 there means the token is
 * already gone, i.e. "already signed out", never "your session expired".
 * (A double-tapped Sign out sends a second revoke that the server refuses.)
 */
const NATIVE_UNAUTHORIZED_EXEMPT_PATHS: ReadonlyArray<string> = [
  '/me',
  '/auth/token',
  '/auth/impersonation/status',
  '/auth/logout',
]

export function shouldHandleNativeUnauthorized(path: string): boolean {
  return !NATIVE_UNAUTHORIZED_EXEMPT_PATHS.includes(path)
}

/** What the native policy needs to know about the device's session at the moment a 401 lands. */
export interface NativeSessionState {
  /** A deliberate sign-out is in flight. */
  isLoggingOut: boolean
  /** The auth store still holds a signed-in user. */
  hasUser: boolean
  /** The token store still holds a token. */
  hasToken: boolean
}

/**
 * AH-100b D3 — "your session expired" needs a session to expire. A 401
 * that lands while the user chose to sign out, or after the device has
 * already dropped both the user and the token (a late reply to a request
 * that was in flight at logout, a poll that fires on the way out), is not
 * an expiry and must not paint the banner. A genuine mid-session 401
 * arrives while the user and/or token are still held, so it still acts.
 */
export function hasLiveNativeSession(state: NativeSessionState): boolean {
  return !state.isLoggingOut && (state.hasUser || state.hasToken)
}

/**
 * Builds the {@link NativeSessionState} reader from its two sources: the
 * auth store (user + deliberate-logout flag) and the token store. An
 * unreadable token store reads as "no token", the same rule the token
 * store itself applies.
 */
export function createNativeSessionStateReader(deps: {
  loadStore: () => Promise<{ isLoggingOut: boolean; user: unknown }>
  readToken: () => Promise<string | null>
}): () => Promise<NativeSessionState> {
  return async () => {
    const store = await deps.loadStore()
    const token = await deps.readToken().catch(() => null)
    return {
      isLoggingOut: store.isLoggingOut,
      hasUser: store.user !== null,
      hasToken: token !== null,
    }
  }
}

export interface NativeUnauthorizedPolicyDeps extends UnauthorizedPolicyDeps {
  clearToken: () => Promise<void>
  /**
   * Reads {@link NativeSessionState}. Optional only so the AH-100 policy
   * specs keep running unchanged; production always supplies it.
   */
  getSessionState?: () => Promise<NativeSessionState>
}

/**
 * AH-100 D1 — a 401 in the creator app means the stored token is dead
 * (revoked, expired, or the user was suspended). Drop the token, drop
 * the user, and route to the app's own sign-in screen. The token is
 * cleared even if storage throws, so the user is never left signed in
 * with a token the server has already refused.
 */
export function createNativeUnauthorizedPolicy(deps: NativeUnauthorizedPolicyDeps) {
  return async (path: string): Promise<void> => {
    if (!shouldHandleNativeUnauthorized(path)) {
      return
    }
    if (deps.getSessionState !== undefined && !hasLiveNativeSession(await deps.getSessionState())) {
      return
    }
    const [storeMod, routerMod] = await Promise.all([
      deps.getStore(),
      deps.getRouter(),
      deps.clearToken().catch(() => undefined),
    ])
    storeMod.clearUser()
    void routerMod.push(sessionExpiredLocation(routerMod.currentRoute?.value))
  }
}

/* c8 ignore start -- @preserve: production wiring; tests exercise both policy factories with fakes. */
const loadAuthStore = async () => {
  const mod = await import('@/modules/auth/stores/useAuthStore')
  return mod.useAuthStore()
}

const policyDeps: UnauthorizedPolicyDeps = {
  getStore: loadAuthStore,
  getRouter: async () => {
    const mod = await import('@/core/router')
    return mod.router
  },
}

const isNative = isNativePlatform()

const productionPolicy = isNative
  ? createNativeUnauthorizedPolicy({
      ...policyDeps,
      clearToken: () => nativeTokenStore().clear(),
      getSessionState: createNativeSessionStateReader({
        loadStore: loadAuthStore,
        readToken: () => nativeTokenStore().read(),
      }),
    })
  : createUnauthorizedPolicy(policyDeps)
/* c8 ignore stop */

export const http = createHttpClient({
  baseUrl: import.meta.env.VITE_API_BASE_URL ?? '/api/v1',
  /* c8 ignore next 3 -- @preserve: native-only branch; the web build passes no `auth` (cookie mode). */
  ...(isNative
    ? { auth: { mode: 'bearer' as const, getToken: () => nativeTokenStore().read() } }
    : {}),
  /* c8 ignore next 3 -- @preserve: forwards to productionPolicy; the policy factories are what the unit test exercises. */
  onUnauthorized: (path) => {
    void productionPolicy(path)
  },
})

/**
 * Typed authentication API bound to the main SPA's surface
 * (`/me`, `/auth/*`). The Pinia auth store consumes this directly.
 */
export const authApi = createAuthApi(http, { variant: 'main' })
