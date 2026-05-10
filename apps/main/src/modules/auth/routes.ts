/**
 * Auth-module route table for the main SPA.
 *
 * Pre-answered chunk-6.5 Q1: Vue Router v4 in HTML5 history mode, kebab-case
 * route names, guards composed via `meta`. The router instance itself
 * (`apps/main/src/core/router/index.ts`) wires these records and dispatches
 * the `meta.guards` chain in a single `beforeEach`. Components NEVER import
 * the router instance directly — see `tests/unit/architecture/no-direct-router-imports.spec.ts`.
 *
 * The `meta.guards` array names which guard composables to chain. The
 * router's `beforeEach` iterates them in declaration order; the first one
 * that returns a redirect short-circuits the chain.
 *
 * Layout assignment:
 *   - All `auth.*` routes render inside `AuthLayout.vue` (centred card,
 *     brand mark, locale switcher).
 *   - `app.dashboard` is a placeholder until chunk 7.
 *   - `error.auth-bootstrap` is the dedicated terminal error route — see
 *     chunk-6.5 pre-answered Q1, which prefers a deep-linkable route over
 *     a global app-level error boundary.
 */

import type { RouteRecordRaw } from 'vue-router'

/**
 * Symbolic guard names. The router's `beforeEach` resolves these to the
 * actual guard composables in `apps/main/src/core/router/guards.ts`. We
 * keep the names in `meta` rather than direct function references so the
 * route table stays a plain serialisable record (easier to inspect, easier
 * to test, and avoids importing the guards eagerly into every chunk that
 * touches the router).
 */
export type GuardName = 'requireAuth' | 'requireGuest' | 'requireMfaEnrolled'

declare module 'vue-router' {
  interface RouteMeta {
    guards?: ReadonlyArray<GuardName>
    /**
     * `'auth'` routes use `AuthLayout.vue`; `'app'` routes are post-auth
     * application surface (the dashboard placeholder for now).
     * `'error'` is the terminal error layout.
     */
    layout?: 'auth' | 'app' | 'error'
  }
}

export const authRoutes: RouteRecordRaw[] = [
  // Sign-in / sign-up — guests only. requireGuest redirects authenticated
  // users to the dashboard.
  {
    path: '/sign-in',
    name: 'auth.sign-in',
    component: () => import('./pages/SignInPage.vue'),
    meta: { layout: 'auth', guards: ['requireGuest'] },
  },
  {
    path: '/sign-up',
    name: 'auth.sign-up',
    component: () => import('./pages/SignUpPage.vue'),
    meta: { layout: 'auth', guards: ['requireGuest'] },
  },

  // Email verification — public landing pages. No guards: the user lands
  // here from an out-of-band email link, possibly while not yet signed in.
  {
    path: '/verify-email/pending',
    name: 'auth.verify-email.pending',
    component: () => import('./pages/EmailVerificationPendingPage.vue'),
    meta: { layout: 'auth' },
  },
  {
    path: '/verify-email/confirm',
    name: 'auth.verify-email.confirm',
    component: () => import('./pages/EmailVerificationConfirmPage.vue'),
    meta: { layout: 'auth' },
  },

  // Password recovery — public landing pages. Same rationale as
  // verify-email above.
  {
    path: '/forgot-password',
    name: 'auth.forgot-password',
    component: () => import('./pages/ForgotPasswordPage.vue'),
    meta: { layout: 'auth' },
  },
  {
    path: '/reset-password',
    name: 'auth.reset-password',
    component: () => import('./pages/ResetPasswordPage.vue'),
    meta: { layout: 'auth' },
  },

  // 2FA enrollment — requires authentication, but does NOT require MFA
  // already enrolled (this is where the user enrols).
  {
    path: '/auth/2fa/enable',
    name: 'auth.2fa.enable',
    component: () => import('./pages/EnableTotpPage.vue'),
    meta: { layout: 'auth', guards: ['requireAuth'] },
  },
  // 2FA verification challenge — used during the sign-in flow when the
  // backend signals that 2FA is required. Reachable without a full
  // authenticated session, so no requireAuth.
  {
    path: '/auth/2fa/verify',
    name: 'auth.2fa.verify',
    component: () => import('./pages/VerifyTotpPage.vue'),
    meta: { layout: 'auth' },
  },
  // 2FA disable — requires a full authenticated MFA-enrolled session.
  {
    path: '/auth/2fa/disable',
    name: 'auth.2fa.disable',
    component: () => import('./pages/DisableTotpPage.vue'),
    meta: { layout: 'auth', guards: ['requireAuth', 'requireMfaEnrolled'] },
  },
]

export const appRoutes: RouteRecordRaw[] = [
  // Dashboard placeholder — full implementation in chunk 7. Requires
  // authentication AND MFA enrolment (gate enforced by requireMfaEnrolled,
  // which redirects unenroled users to the enable-2fa page). The
  // placeholder lives under `core/pages/` rather than the auth module so
  // it does not pollute the auth module's 100% coverage scope.
  {
    path: '/',
    name: 'app.dashboard',
    component: () => import('@/core/pages/DashboardPlaceholderPage.vue'),
    meta: { layout: 'app', guards: ['requireAuth', 'requireMfaEnrolled'] },
  },
  // Settings placeholder — landing target for DisableTotp on success.
  {
    path: '/settings',
    name: 'app.settings',
    component: () => import('@/core/pages/DashboardPlaceholderPage.vue'),
    meta: { layout: 'app', guards: ['requireAuth'] },
  },
]

export const errorRoutes: RouteRecordRaw[] = [
  // Terminal error route for the bootstrap-failed branch. Chosen over a
  // global app-level error boundary so the URL itself is deep-linkable
  // from logs / Sentry breadcrumbs (chunk-6.5 pre-answered Q1).
  {
    path: '/error/auth-bootstrap',
    name: 'error.auth-bootstrap',
    component: () => import('./pages/AuthBootstrapErrorPage.vue'),
    meta: { layout: 'error' },
  },
]

/**
 * The full route table the main SPA mounts. Order matters only for
 * route-name collisions, of which there are none.
 */
export const routes: RouteRecordRaw[] = [...authRoutes, ...appRoutes, ...errorRoutes]
