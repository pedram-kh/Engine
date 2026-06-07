/**
 * Admin SPA operations-module route table (Sprint 13, D-8).
 *
 * Queues + failed jobs are served by the gated Horizon EMBED (a nav link
 * out to `/horizon`, not a SPA route — see `navItems.ts`). The only
 * in-SPA operations route is the system-health probe surface.
 * `layout: 'admin'` + mandatory-MFA guards.
 */

import type { RouteRecordRaw } from 'vue-router'

const adminGuards = ['requireAuth', 'requireMfaEnrolled'] as const

export const operationsRoutes: RouteRecordRaw[] = [
  {
    path: '/operations/health',
    name: 'app.operations.health',
    component: () => import('@/modules/operations/pages/SystemHealthPage.vue'),
    meta: { layout: 'admin', guards: adminGuards },
  },
]
