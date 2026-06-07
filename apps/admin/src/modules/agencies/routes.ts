/**
 * Admin SPA agencies-module route table (Sprint 13, D-3).
 *
 * Live list + detail surfaces for platform-admin agency management
 * (suspend / reactivate). Gated by `requireAuth` + `requireMfaEnrolled`
 * per the admin SPA's mandatory-MFA model; `layout: 'admin'` mounts them
 * in the Sprint-13 shell (D-1).
 */

import type { RouteRecordRaw } from 'vue-router'

const adminGuards = ['requireAuth', 'requireMfaEnrolled'] as const

export const agenciesRoutes: RouteRecordRaw[] = [
  {
    path: '/agencies',
    name: 'app.agencies.list',
    component: () => import('@/modules/agencies/pages/AgencyListPage.vue'),
    meta: { layout: 'admin', guards: adminGuards },
  },
  {
    path: '/agencies/:ulid',
    name: 'app.agencies.detail',
    component: () => import('@/modules/agencies/pages/AgencyDetailPage.vue'),
    meta: { layout: 'admin', guards: adminGuards },
  },
]
