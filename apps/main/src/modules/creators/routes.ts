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
    // AH-009 — standalone post-onboarding profile-edit surface. Reuses the
    // wizard step-2 body + the step-3 sub-sections. `requireAuth` only (NOT
    // `requireOnboardingAccess`, which would redirect non-incomplete creators
    // away); the page itself soft-redirects incomplete creators to the wizard.
    path: '/creator/profile',
    name: 'creator.profile',
    component: () => import('./pages/CreatorProfilePage.vue'),
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
    // Jobs Board chunk 3 (AH-056, D9) — the creator's job board: the listed
    // campaigns of every agency that has them on its roster.
    //
    // `requireAuth` only, deliberately. The approved-creator gate is SERVER
    // side (the predicate returns an empty board to an unapproved creator, not
    // an error), and the nav item is hidden for them; the client branch is UX,
    // never the boundary — the house pattern for every other creator route.
    path: '/creator/jobs',
    name: 'creator.jobs',
    component: () => import('./pages/CreatorJobsPage.vue'),
    meta: {
      layout: 'creator',
      guards: ['requireAuth'],
    },
  },
  {
    // AH-056 — one job, by campaign ULID. A job the caller may not see 404s
    // server-side (fail-closed, non-probeable), so this route is safe to reach
    // by a pasted URL.
    path: '/creator/jobs/:ulid',
    name: 'creator.job.detail',
    component: () => import('./pages/CreatorJobDetailPage.vue'),
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
  {
    // AH-010b — the creator-shell relationship-messaging inbox (top-level
    // "Messages" nav, D9). Lists the creator's 1:1 threads, one per connected
    // agency. Symmetric to the agency inbox (Q5). AH-013: the thread is now a
    // CHILD route so the inbox shell stays mounted and renders the thread in a
    // right pane on desktop (WhatsApp Web two-pane); single pane on mobile.
    path: '/creator/messages',
    name: 'creator.messages',
    component: () => import('@/modules/messaging/pages/CreatorMessagesPage.vue'),
    meta: {
      layout: 'creator',
      guards: ['requireAuth'],
      // AH-013 — the two-pane chat fills the viewport (WhatsApp Web), so this
      // page opts out of the layout's 960px reading column. Merged onto the
      // nested thread route too, so it holds while a conversation is open.
      wide: true,
    },
    children: [
      {
        // AH-010b — the relationship thread (keyed by the agency ULID).
        path: ':agencyUlid',
        name: 'creator.messages.thread',
        component: () => import('@/modules/messaging/pages/CreatorRelationshipThreadPage.vue'),
        meta: {
          layout: 'creator',
          guards: ['requireAuth'],
        },
      },
    ],
  },
]
