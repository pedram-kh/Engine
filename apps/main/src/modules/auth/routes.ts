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
 *   - `app.dashboard` renders the real agency workspace home
 *     (`@/modules/dashboard`) since Sprint 4 Chunk 1.
 *   - `error.auth-bootstrap` is the dedicated terminal error route — see
 *     chunk-6.5 pre-answered Q1, which prefers a deep-linkable route over
 *     a global app-level error boundary.
 */

import type { RouteRecordRaw } from 'vue-router'

import { creatorsRoutes } from '@/modules/creators/routes'
import { impersonationRoutes } from '@/modules/impersonation/routes'
import { onboardingRoutes } from '@/modules/onboarding/routes'

/**
 * Symbolic guard names. The router's `beforeEach` resolves these to the
 * actual guard composables in `apps/main/src/core/router/guards.ts`. We
 * keep the names in `meta` rather than direct function references so the
 * route table stays a plain serialisable record (easier to inspect, easier
 * to test, and avoids importing the guards eagerly into every chunk that
 * touches the router).
 */
export type GuardName =
  | 'requireAuth'
  | 'requireGuest'
  | 'requireMfaEnrolled'
  | 'requireAgencyAdmin'
  | 'requireOnboardingAccess'
  | 'requireAgencyUser'

declare module 'vue-router' {
  interface RouteMeta {
    guards?: ReadonlyArray<GuardName>
    /**
     * `'auth'` routes use `AuthLayout.vue`.
     * `'agency'` routes use `AgencyLayout.vue` (sidebar + topbar + user menu).
     * `'onboarding'` routes use `OnboardingLayout.vue` — the wizard chrome
     *                 (progress indicator + save-and-exit + minimal body).
     *                 Sprint 3 Chunk 3 sub-step 2.
     * `'creator'`  routes use `CreatorDashboardLayout.vue` — the
     *                 post-submit creator shell. Sprint 3 Chunk 3 sub-step 8.
     * `'app'` routes use the bare v-app catch-all.
     * `'error'` is the terminal error layout.
     */
    layout?: 'auth' | 'agency' | 'onboarding' | 'creator' | 'app' | 'error'
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
  // Path is `/auth/verify-email` to match the link the backend mints in
  // `SignUpService::buildVerificationUrl()` (and the `_test` token-mint
  // helper). The route NAME stays `auth.verify-email.confirm` for
  // name-based navigation. A prior mismatch (SPA on `/verify-email/confirm`,
  // emails pointing at `/auth/verify-email`) landed real users on a blank
  // unmatched route — the e2e suite had papered over it by constructing
  // its own SPA URL.
  {
    path: '/auth/verify-email',
    name: 'auth.verify-email.confirm',
    component: () => import('./pages/EmailVerificationConfirmPage.vue'),
    meta: { layout: 'auth' },
  },

  // Creator-side magic-link invitation landing — public, no guards.
  // Sprint 3 Chunk 4 sub-step 4. The page previews the invitation via
  // the unauthenticated `/creators/invitations/preview` endpoint and
  // renders one of 5 states; the valid-pending state chains forward
  // to /sign-up?token=<token>.
  {
    path: '/auth/accept-invite',
    name: 'auth.accept-creator-invite',
    component: () => import('./pages/AcceptCreatorInvitePage.vue'),
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
  // Every agency-SHELL route (layout: 'agency') chains
  // `requireAuth → requireAgencyUser` (Sprint 6 Chunk 1, D-7): auth resolves a
  // user, then requireAgencyUser bounces a `creator`-type user (who belongs in
  // the onboarding/creator shell, not here) to `onboarding.welcome-back`. The
  // ONE appRoutes exception is `accept-invitation` below — a public pre-auth
  // landing (layout: 'auth', no requireAuth) where the guard cannot run. The
  // arch-test `agency-routes-agency-user-guard.spec.ts` pins this invariant.

  // Dashboard — the real agency workspace home (Sprint 4 Chunk 1; replaced
  // the chunk-6.5 DashboardPlaceholderPage). Requires auth; NOT MFA-gated
  // (see the selective-gating test in agency-routes-mfa-guard.spec.ts).
  {
    path: '/',
    name: 'app.dashboard',
    component: () => import('@/modules/dashboard/pages/DashboardPage.vue'),
    meta: { layout: 'agency', guards: ['requireAuth', 'requireAgencyUser'] },
  },

  // ── Creator roster ("my creators") ─────────────────────────────────────────
  // Sprint 4 Chunk 5. Any agency member may view; not MFA-gated (matches
  // the dashboard + brands list). Read-only this chunk — no row navigation
  // (no agency-side creator detail exists yet, D-c5-4).
  {
    path: '/roster',
    name: 'roster.list',
    component: () => import('@/modules/roster/pages/CreatorRosterPage.vue'),
    meta: { layout: 'agency', guards: ['requireAuth', 'requireAgencyUser'] },
  },

  // Per-creator detail view (Sprint 6 Chunk 2a, D-2a-6 — the D-c5-4 reversal:
  // roster rows now navigate). `:ulid` is the CREATOR ULID (the slim roster
  // row carries it as `creator_id`). Same guard chain as the roster list;
  // pinned into the requireAgencyUser arch-test's expected set.
  {
    path: '/roster/:ulid',
    name: 'roster.detail',
    component: () => import('@/modules/roster/pages/CreatorDetailPage.vue'),
    meta: { layout: 'agency', guards: ['requireAuth', 'requireAgencyUser'] },
  },

  // ── Creator discovery (the global pool) ────────────────────────────────────
  // Sprint 6.6a (D-8). A SEPARATE surface from the roster (NOT a tab): the
  // roster is "creators I have a relationship with"; Discover is "the global
  // pool" (the public resource). Same agency-shell guard chain; not MFA-gated
  // (read-only browse). Both routes are pinned into the requireAgencyUser
  // arch-test's expected set. Read-only this chunk — no send-request (D-9).
  {
    path: '/discover',
    name: 'discover.list',
    component: () => import('@/modules/discover/pages/DiscoverPage.vue'),
    meta: { layout: 'agency', guards: ['requireAuth', 'requireAgencyUser'] },
  },
  // `:ulid` is the CREATOR ULID (a discovery card carries it as its `id`).
  {
    path: '/discover/:ulid',
    name: 'discover.detail',
    component: () => import('@/modules/discover/pages/DiscoverProfilePage.vue'),
    meta: { layout: 'agency', guards: ['requireAuth', 'requireAgencyUser'] },
  },

  // ── Brands ───────────────────────────────────────────────────────────────
  {
    path: '/brands',
    name: 'brands.list',
    component: () => import('@/modules/brands/pages/BrandListPage.vue'),
    meta: { layout: 'agency', guards: ['requireAuth', 'requireAgencyUser'] },
  },
  {
    path: '/brands/new',
    name: 'brands.create',
    component: () => import('@/modules/brands/pages/BrandCreatePage.vue'),
    meta: { layout: 'agency', guards: ['requireAuth', 'requireAgencyUser'] },
  },
  {
    path: '/brands/:ulid',
    name: 'brands.detail',
    component: () => import('@/modules/brands/pages/BrandDetailPage.vue'),
    meta: { layout: 'agency', guards: ['requireAuth', 'requireAgencyUser'] },
  },
  {
    path: '/brands/:ulid/edit',
    name: 'brands.edit',
    component: () => import('@/modules/brands/pages/BrandEditPage.vue'),
    meta: { layout: 'agency', guards: ['requireAuth', 'requireAgencyUser'] },
  },

  // ── Campaigns ──────────────────────────────────────────────────────────────
  // Sprint 8 Chunk 1. Any agency member may view (list/detail); create + the
  // detail Settings tab are admin/manager-gated (backend + UI). Same agency-
  // shell guard chain; not MFA-gated. All three names are pinned into the
  // requireAgencyUser arch-test's expected set.
  {
    path: '/campaigns',
    name: 'campaigns.list',
    component: () => import('@/modules/campaigns/pages/CampaignListPage.vue'),
    meta: { layout: 'agency', guards: ['requireAuth', 'requireAgencyUser'] },
  },
  {
    path: '/campaigns/new',
    name: 'campaigns.create',
    component: () => import('@/modules/campaigns/pages/CampaignCreatePage.vue'),
    meta: { layout: 'agency', guards: ['requireAuth', 'requireAgencyUser'] },
  },
  {
    path: '/campaigns/:ulid',
    name: 'campaigns.detail',
    component: () => import('@/modules/campaigns/pages/CampaignDetailPage.vue'),
    meta: { layout: 'agency', guards: ['requireAuth', 'requireAgencyUser'] },
  },

  // ── Talent pools ───────────────────────────────────────────────────────────
  // Sprint 6 Chunk 2b. Mirrors the brands block: any agency member may view;
  // create/edit gated by role in the UI + backend. NOT MFA-gated (pools are
  // non-admin — pinned out of the MFA arch-test's gated set).
  {
    path: '/talent-pools',
    name: 'pools.list',
    component: () => import('@/modules/pools/pages/PoolListPage.vue'),
    meta: { layout: 'agency', guards: ['requireAuth', 'requireAgencyUser'] },
  },
  {
    path: '/talent-pools/new',
    name: 'pools.create',
    component: () => import('@/modules/pools/pages/PoolCreatePage.vue'),
    meta: { layout: 'agency', guards: ['requireAuth', 'requireAgencyUser'] },
  },
  {
    path: '/talent-pools/:ulid',
    name: 'pools.detail',
    component: () => import('@/modules/pools/pages/PoolDetailPage.vue'),
    meta: { layout: 'agency', guards: ['requireAuth', 'requireAgencyUser'] },
  },
  {
    path: '/talent-pools/:ulid/edit',
    name: 'pools.edit',
    component: () => import('@/modules/pools/pages/PoolEditPage.vue'),
    meta: { layout: 'agency', guards: ['requireAuth', 'requireAgencyUser'] },
  },

  // ── Agency users / invitations ────────────────────────────────────────────
  // Visible only to agency_admin (route guard + UI gating).
  //
  // Sprint 3 Chunk 4 sub-step 5 — `requireMfaEnrolled` lands on
  // admin-gated agency routes per Sprint 2 § e carry-forward. Order
  // mirrors the chunk-7.1 admin SPA chain: requireAuth → requireMfaEnrolled
  // → requireAgencyAdmin. An admin user who hasn't enrolled 2FA is
  // bounced to /auth/2fa/enable before the route renders. Non-admin
  // routes (app.dashboard, brands.*, settings) are NOT MFA-gated in
  // Phase 1 — Sprint 4+ may broaden this if any of them grows a
  // state-flipping admin surface.
  {
    path: '/agency-users',
    name: 'agency-users.list',
    component: () => import('@/modules/agency-users/pages/AgencyUsersPage.vue'),
    meta: {
      layout: 'agency',
      guards: ['requireAuth', 'requireAgencyUser', 'requireMfaEnrolled', 'requireAgencyAdmin'],
    },
  },

  // ── Bulk creator invitations ─────────────────────────────────────────────
  // Sprint 3 Chunk 4 sub-step 11 — CSV-driven prospect creator bulk-invite.
  // Admin-only (matches the backend's BulkInviteController::authorizeAdmin
  // role check). The guard chain mirrors agency-users.list — requireAuth →
  // requireMfaEnrolled → requireAgencyAdmin — so 2FA is enforced before
  // an admin can ship invitations.
  {
    path: '/creator-invitations/bulk',
    name: 'creator-invitations.bulk',
    component: () => import('@/modules/creator-invitations/pages/BulkInvitePage.vue'),
    meta: {
      layout: 'agency',
      guards: ['requireAuth', 'requireAgencyUser', 'requireMfaEnrolled', 'requireAgencyAdmin'],
    },
  },

  // ── Settings ──────────────────────────────────────────────────────────────
  // Visible to all roles; editable by admin only (backend + UI enforced).
  {
    path: '/settings',
    name: 'settings',
    component: () => import('@/modules/settings/pages/SettingsPage.vue'),
    meta: { layout: 'agency', guards: ['requireAuth', 'requireAgencyUser'] },
  },

  // ── Notifications archive ───────────────────────────────────────────────────
  // S11.0 Ch3a (D-2) — the agency-shell full paginated notification feed. The
  // app-bar bell's dropdown is the recent slice; this is the archive. Any agency
  // member may view (the API is owner-scoped to the auth user); not MFA-gated.
  // The creator shell has a parallel `/creator/notifications` route rendering
  // the SAME shell-agnostic NotificationsPage. Pinned into the requireAgencyUser
  // arch-test's expected set.
  {
    path: '/notifications',
    name: 'notifications',
    component: () => import('@/modules/notifications/pages/NotificationsPage.vue'),
    meta: { layout: 'agency', guards: ['requireAuth', 'requireAgencyUser'] },
  },

  // ── Notification preferences (S11.0 Ch3b) ───────────────────────────────────
  // The first user self-write surface. Owner-scoped to the auth user (no agency
  // id), reached from the user-menu "Notification settings" item. The creator
  // shell has a parallel `/creator/notifications/preferences` route rendering the
  // SAME shell-agnostic page. Added to the requireAgencyUser arch-test set.
  {
    path: '/notifications/preferences',
    name: 'notifications.preferences',
    component: () => import('@/modules/notifications/pages/NotificationPreferencesPage.vue'),
    meta: { layout: 'agency', guards: ['requireAuth', 'requireAgencyUser'] },
  },

  // ── Accept invitation ─────────────────────────────────────────────────────
  // Public landing page. Auth state is detected inside the page.
  // No layout guard — this renders inside AgencyLayout only if authenticated,
  // and falls back to a minimal layout for unauthenticated visitors.
  // Using AuthLayout keeps it simple for unauthenticated state.
  //
  // D-7 exception: this is the ONE appRoutes entry that does NOT carry
  // `requireAgencyUser`. It's a public pre-auth landing (layout: 'auth', no
  // requireAuth), so the agency-shell guard cannot run here — the page resolves
  // auth itself. The arch-test excludes it explicitly.
  {
    path: '/accept-invitation',
    name: 'accept-invitation',
    component: () => import('@/modules/agency-users/pages/AcceptInvitationPage.vue'),
    meta: { layout: 'auth' },
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
 *
 * Sprint 3 Chunk 3 sub-step 2: onboarding + creators routes joined.
 */
export const routes: RouteRecordRaw[] = [
  ...authRoutes,
  ...appRoutes,
  ...onboardingRoutes,
  ...creatorsRoutes,
  ...impersonationRoutes,
  ...errorRoutes,
]
