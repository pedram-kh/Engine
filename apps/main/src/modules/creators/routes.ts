/**
 * Creators-module route table (Sprint 3 Chunk 3 sub-step 2 scaffold,
 * fleshed out in sub-step 8).
 *
 * The creator-side post-submit surface — application-status page +
 * Sprint 4+ approved-creator dashboard surfaces. The wizard surface
 * itself lives in the `onboarding/` module sibling.
 *
 * Route path lock (Refinement 5): creators land at `/creator/dashboard`
 * to avoid the namespace collision with the agency-side `/dashboard`
 * (agency users hit `/` for their dashboard; creators hit
 * `/creator/dashboard`). The distinct path keeps the layout-switcher
 * logic in App.vue clean — no user.type-based dispatch needed.
 */

import type { RouteRecordRaw } from 'vue-router'

export const creatorsRoutes: RouteRecordRaw[] = [
  {
    path: '/creator/dashboard',
    name: 'creator.dashboard',
    component: () => import('./pages/CreatorDashboardPage.vue'),
    meta: {
      layout: 'creator',
      guards: ['requireAuth'],
    },
  },
  {
    // Sprint 5 Chunk B — creator availability calendar (month view).
    path: '/creator/availability',
    name: 'creator.availability',
    component: () => import('./availability/pages/AvailabilityPage.vue'),
    meta: {
      layout: 'creator',
      guards: ['requireAuth'],
    },
  },
  {
    // Sprint 8 Chunk 2 (D-10) — the creator's campaign-invitation surface
    // (accept / decline / counter). Consumes GET creators/me/assignments.
    path: '/creator/assignments',
    name: 'creator.assignments',
    component: () => import('./pages/CreatorAssignmentsPage.vue'),
    meta: {
      layout: 'creator',
      guards: ['requireAuth'],
    },
  },
  {
    // Sprint 9 Chunk 1 (D-9) — the per-assignment detail + submission surface
    // (draft submit/resubmit + posted content). The flat list links here; this
    // is the home for draft history + state-dependent (fail-closed) actions.
    path: '/creator/assignments/:ulid',
    name: 'creator.assignment.detail',
    component: () => import('./pages/CreatorAssignmentDetailPage.vue'),
    meta: {
      layout: 'creator',
      guards: ['requireAuth'],
    },
  },
  {
    // S11.0 Ch3a (D-2) — the creator-shell full paginated notification feed.
    // Parallel to the agency `/notifications` route; renders the SAME
    // shell-agnostic NotificationsPage. Distinct path (the `/creator/*` prefix)
    // keeps it on the creator layout — App.vue dispatches purely off
    // meta.layout, so a single shared path would 302 creators via
    // requireAgencyUser (the `/dashboard` vs `/creator/dashboard` precedent).
    path: '/creator/notifications',
    name: 'creator.notifications',
    component: () => import('@/modules/notifications/pages/NotificationsPage.vue'),
    meta: {
      layout: 'creator',
      guards: ['requireAuth'],
    },
  },
  {
    // S11.0 Ch3b — the creator-shell notification-preferences page. Parallel to
    // the agency `/notifications/preferences` route; renders the SAME
    // shell-agnostic NotificationPreferencesPage (the API is user-scoped).
    // Reached from the user-menu "Notification settings" item.
    path: '/creator/notifications/preferences',
    name: 'creator.notifications.preferences',
    component: () => import('@/modules/notifications/pages/NotificationPreferencesPage.vue'),
    meta: {
      layout: 'creator',
      guards: ['requireAuth'],
    },
  },
]
