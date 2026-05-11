/**
 * Admin SPA auth-module route table.
 *
 * Mirrors `apps/main/src/modules/auth/routes.ts` (chunks 6.5–6.7) with
 * admin-specific adaptations for the mandatory-MFA model
 * (`docs/05-SECURITY-COMPLIANCE.md` § 6.3 + § 6.4):
 *
 *   - No `/sign-up` / `/verify-email/*` / `/forgot-password` / `/reset-password`
 *     routes — admin onboarding goes through an out-of-band invite flow
 *     (`docs/20-PHASE-1-SPEC.md` § 5).
 *   - `app.dashboard` AND `app.settings` BOTH require `requireMfaEnrolled`
 *     on top of `requireAuth`. Main only mounts `requireMfaEnrolled` on
 *     `app.dashboard`; `app.settings` on main is reachable without 2FA
 *     so a user landing back from DisableTotp can see the placeholder.
 *     Admin's mandatory-MFA model means EVERY post-auth route requires
 *     MFA enrolment — admins cannot disable their own 2FA (the route
 *     exists for the admin-of-admins case in a future sprint; for now
 *     it's still gated as defence-in-depth).
 *
 * Route names mirror main's kebab-case `module.route-name` convention
 * verbatim (no `admin.` prefix). Admin and main are separate SPAs with
 * no namespace collision; uniform names reduce cross-SPA context-switching
 * for future Cursor sessions reading both route tables (chunk 7.2-7.3
 * standard: cross-SPA action-name consistency reduces cognitive load).
 *
 * Pre-answered chunk-6.5 Q1 (carried forward from main): HTML5 history
 * mode, `meta.guards` array names guard composables, the router's
 * `beforeEach` resolves them via `runGuards`. Adding a new guarded route
 * is a data change, not a wiring change.
 *
 * Page components: every route lazy-loads
 * `@/core/pages/PlaceholderPage.vue` for now. Sub-chunk 7.5 substitutes
 * the substantive auth pages (`SignInPage`, `EnableTotpPage`,
 * `VerifyTotpPage`, `DisableTotpPage`, `AuthBootstrapErrorPage`) into
 * the relevant slots; `app.dashboard` / `app.settings` stay placeholders
 * until the admin dashboard surface lands in a later chunk.
 *
 * Architecture-level protection on imports is mirrored from main at
 * `apps/admin/tests/unit/architecture/no-direct-router-imports.spec.ts`.
 */

import type { RouteRecordRaw } from 'vue-router'

/**
 * Symbolic guard names. The router's `beforeEach` resolves these to the
 * actual guard composables in `apps/admin/src/core/router/guards.ts`.
 * Kept as strings in `meta` (rather than direct function references) so
 * the route table stays a plain serialisable record — easier to inspect,
 * easier to test, and avoids importing the guards eagerly into every
 * file that touches the router.
 */
export type GuardName = 'requireAuth' | 'requireGuest' | 'requireMfaEnrolled'

declare module 'vue-router' {
  interface RouteMeta {
    guards?: ReadonlyArray<GuardName>
    /**
     * `'auth'` routes use an admin auth layout (sub-chunk 7.5);
     * `'app'` routes are post-auth admin-console surface;
     * `'error'` is the terminal error layout. Sub-chunk 7.4 ships a
     * single bare `<v-app><v-main><router-view /></v-main></v-app>`
     * shell regardless of layout — 7.5 expands `App.vue` into a
     * layout switcher matching main's chunk-6.8 pattern.
     */
    layout?: 'auth' | 'app' | 'error'
  }
}

export const authRoutes: RouteRecordRaw[] = [
  // Sign-in — guests only. requireGuest redirects authenticated admins
  // to the dashboard so the back button never strands them on a sign-in
  // form they no longer need.
  {
    path: '/sign-in',
    name: 'auth.sign-in',
    component: () => import('@/core/pages/PlaceholderPage.vue'),
    meta: { layout: 'auth', guards: ['requireGuest'] },
  },

  // 2FA enrollment — requires authentication, but does NOT require MFA
  // already enrolled (this IS where the admin enrols). Reached either
  // via direct navigation by a signed-in admin OR via the requireAuth
  // guard's `mfaEnrollmentRequired` branch when /me returns 403
  // `auth.mfa.enrollment_required` (the chunk-7.4 mandatory-MFA gate).
  {
    path: '/auth/2fa/enable',
    name: 'auth.2fa.enable',
    component: () => import('@/core/pages/PlaceholderPage.vue'),
    meta: { layout: 'auth', guards: ['requireAuth'] },
  },
  // 2FA verification challenge — used during the sign-in flow when the
  // backend signals that 2FA is required. Reachable without a full
  // authenticated session, so no requireAuth.
  {
    path: '/auth/2fa/verify',
    name: 'auth.2fa.verify',
    component: () => import('@/core/pages/PlaceholderPage.vue'),
    meta: { layout: 'auth' },
  },
  // 2FA disable — requires a full authenticated MFA-enrolled session.
  // The backend gates `/admin/auth/2fa/disable` with EnsureMfaForAdmins;
  // the router guard pre-empts that with the same check so the SPA
  // doesn't even render the page for an admin without MFA.
  {
    path: '/auth/2fa/disable',
    name: 'auth.2fa.disable',
    component: () => import('@/core/pages/PlaceholderPage.vue'),
    meta: { layout: 'auth', guards: ['requireAuth', 'requireMfaEnrolled'] },
  },
]

export const appRoutes: RouteRecordRaw[] = [
  // Dashboard placeholder — substantive admin console UI ships in a
  // later sprint. Requires authentication AND MFA enrolment per the
  // mandatory-MFA spec (`docs/05-SECURITY-COMPLIANCE.md` § 6.3).
  {
    path: '/',
    name: 'app.dashboard',
    component: () => import('@/core/pages/PlaceholderPage.vue'),
    meta: { layout: 'app', guards: ['requireAuth', 'requireMfaEnrolled'] },
  },
  // Settings placeholder — landing target for DisableTotp on success.
  // Stricter than main's analog: main's `app.settings` only requires
  // auth (so a user landing back from DisableTotp can see the
  // placeholder); admin requires MFA-enrolled because admins under the
  // mandatory-MFA spec NEVER have a legitimate window where they are
  // signed in but not enrolled (every authenticated admin route assumes
  // enrolment).
  {
    path: '/settings',
    name: 'app.settings',
    component: () => import('@/core/pages/PlaceholderPage.vue'),
    meta: { layout: 'app', guards: ['requireAuth', 'requireMfaEnrolled'] },
  },
]

export const errorRoutes: RouteRecordRaw[] = [
  // Terminal error route for the bootstrap-failed branch. Chosen over a
  // global app-level error boundary so the URL itself is deep-linkable
  // from logs / Sentry breadcrumbs — mirrors main's chunk-6.5 decision.
  {
    path: '/error/auth-bootstrap',
    name: 'error.auth-bootstrap',
    component: () => import('@/core/pages/PlaceholderPage.vue'),
    meta: { layout: 'error' },
  },
]

/**
 * The full route table the admin SPA mounts. Order matters only for
 * route-name collisions, of which there are none.
 */
export const routes: RouteRecordRaw[] = [...authRoutes, ...appRoutes, ...errorRoutes]
