/**
 * Admin SPA dashboard-module route table (Sprint 13, D-7).
 *
 * `app.dashboard` is the post-auth landing route (the `requireGuest`
 * guard bounces signed-in admins here). It moved out of the auth
 * module's `appRoutes` into its own module in Sprint 13 when the
 * substantive dashboard surface landed. `layout: 'admin'` mounts it in
 * the Sprint-13 shell.
 */

import type { RouteRecordRaw } from 'vue-router'

const adminGuards = ['requireAuth', 'requireMfaEnrolled'] as const

export const dashboardRoutes: RouteRecordRaw[] = [
  {
    path: '/',
    name: 'app.dashboard',
    component: () => import('@/modules/dashboard/pages/DashboardPage.vue'),
    meta: { layout: 'admin', guards: adminGuards },
  },
]
