/**
 * Admin SPA support-module route table (Sprint 13, D-9).
 *
 * User search + impersonation start (the security-critical surface) and
 * the persistent impersonation log (`docs/09-ADMIN-PANEL.md` § 6.8). The
 * impersonation START action lives on the user-search / detail surface;
 * the impersonated session itself runs in the MAIN SPA tab (the
 * dual-session model, D-9). `layout: 'admin'` + mandatory-MFA guards.
 */

import type { RouteRecordRaw } from 'vue-router'

const adminGuards = ['requireAuth', 'requireMfaEnrolled'] as const

export const supportRoutes: RouteRecordRaw[] = [
  {
    path: '/support/users',
    name: 'app.support.search',
    component: () => import('@/modules/support/pages/UserSearchPage.vue'),
    meta: { layout: 'admin', guards: adminGuards },
  },
  {
    path: '/support/impersonation-log',
    name: 'app.support.impersonation-log',
    component: () => import('@/modules/support/pages/ImpersonationLogPage.vue'),
    meta: { layout: 'admin', guards: adminGuards },
  },
]
