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
]
