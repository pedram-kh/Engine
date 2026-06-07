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
     * `'admin'` routes are the post-auth admin-console surface, mounted
     * in `AdminLayout` (Sprint 13, D-1 — the shell);
     * `'app'` is the legacy bare `<v-app><v-main>` shell, retained only
     * as the transient default before the first navigation resolves;
     * `'error'` is the terminal error layout.
     *
     * Sprint 13 migrated every authenticated route from `'app'` to
     * `'admin'` (surfaced as a divergence: main's standard 5.14 mounts
     * authenticated shells under a named layout, not the bare `'app'`).
     */
    layout?: 'auth' | 'admin' | 'app' | 'error'
  }
}

export const authRoutes: RouteRecordRaw[] = [
  // Sign-in — guests only. requireGuest redirects authenticated admins
  // to the dashboard so the back button never strands them on a sign-in
  // form they no longer need.
  {
    path: '/sign-in',
    name: 'auth.sign-in',
    component: () => import('@/modules/auth/pages/SignInPage.vue'),
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
    component: () => import('@/modules/auth/pages/EnableTotpPage.vue'),
    meta: { layout: 'auth', guards: ['requireAuth'] },
  },
  // 2FA verification challenge — used during the sign-in flow when the
  // backend signals that 2FA is required. Reachable without a full
  // authenticated session, so no requireAuth.
  {
    path: '/auth/2fa/verify',
    name: 'auth.2fa.verify',
    component: () => import('@/modules/auth/pages/VerifyTotpPage.vue'),
    meta: { layout: 'auth' },
  },
  // 2FA disable — requires a full authenticated MFA-enrolled session.
  // The backend gates `/admin/auth/2fa/disable` with EnsureMfaForAdmins;
  // the router guard pre-empts that with the same check so the SPA
  // doesn't even render the page for an admin without MFA.
  {
    path: '/auth/2fa/disable',
    name: 'auth.2fa.disable',
    component: () => import('@/modules/auth/pages/DisableTotpPage.vue'),
    meta: { layout: 'auth', guards: ['requireAuth', 'requireMfaEnrolled'] },
  },
]

export const appRoutes: RouteRecordRaw[] = [
  // Settings placeholder — landing target for DisableTotp on success.
  // Stricter than main's analog: main's `app.settings` only requires
  // auth (so a user landing back from DisableTotp can see the
  // placeholder); admin requires MFA-enrolled because admins under the
  // mandatory-MFA spec NEVER have a legitimate window where they are
  // signed in but not enrolled (every authenticated admin route assumes
  // enrolment).
  //
  // Sprint 13: `app.dashboard` moved to its own dashboard module
  // (`dashboardRoutes`) when the substantive surface landed; settings
  // migrated to `layout: 'admin'` (the shell).
  {
    path: '/settings',
    name: 'app.settings',
    component: () => import('@/core/pages/PlaceholderPage.vue'),
    meta: { layout: 'admin', guards: ['requireAuth', 'requireMfaEnrolled'] },
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

import { agenciesRoutes } from '@/modules/agencies/routes'
import { alertsRoutes } from '@/modules/alerts/routes'
import { auditRoutes } from '@/modules/audit/routes'
import { complianceRoutes } from '@/modules/compliance/routes'
import { creatorsRoutes } from '@/modules/creators/routes'
import { dashboardRoutes } from '@/modules/dashboard/routes'
import { featureFlagsRoutes } from '@/modules/feature-flags/routes'
import { operationsRoutes } from '@/modules/operations/routes'
import { paymentsRoutes } from '@/modules/payments/routes'
import { supportRoutes } from '@/modules/support/routes'

/**
 * The full route table the admin SPA mounts. Order matters only for
 * route-name collisions (none) and path-prefix shadowing — the
 * creators module declares `/creators/kyc` + `/creators/all` BEFORE
 * `/creators/:ulid` internally so the literals are not captured by the
 * param route.
 *
 * Sprint 13 (D-1): the full S13 module route table — dashboard,
 * agencies, creators (+KYC +all), payments (coming-soon), audit,
 * support/impersonation, operations, compliance, feature-flags — all
 * mounted in the `AdminLayout` shell. Everything in the sprint reaches
 * the user through one of these routes.
 */
export const routes: RouteRecordRaw[] = [
  ...authRoutes,
  ...appRoutes,
  ...dashboardRoutes,
  ...agenciesRoutes,
  ...creatorsRoutes,
  ...paymentsRoutes,
  ...auditRoutes,
  ...alertsRoutes,
  ...supportRoutes,
  ...operationsRoutes,
  ...complianceRoutes,
  ...featureFlagsRoutes,
  ...errorRoutes,
]
