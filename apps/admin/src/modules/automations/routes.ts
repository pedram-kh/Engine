/**
 * Admin SPA automations-module route table (AH-090, D6).
 *
 * The platform automation-rules surface. `layout: 'admin'` + the same
 * auth/mandatory-MFA guard pair every admin route carries.
 */

import type { RouteRecordRaw } from 'vue-router'

const adminGuards = ['requireAuth', 'requireMfaEnrolled'] as const

export const automationsRoutes: RouteRecordRaw[] = [
  {
    path: '/automations',
    name: 'app.automations.list',
    component: () => import('@/modules/automations/pages/AutomationsPage.vue'),
    meta: { layout: 'admin', guards: adminGuards },
  },
]
