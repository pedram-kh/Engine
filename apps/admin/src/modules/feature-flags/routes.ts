/**
 * Admin SPA feature-flags-module route table (Sprint 13, D-6).
 *
 * The DB-backed Pennant flag toggle UI (the runtime mutation path via
 * `Feature::activate()` / `deactivate()`). `layout: 'admin'` +
 * mandatory-MFA guards.
 */

import type { RouteRecordRaw } from 'vue-router'

const adminGuards = ['requireAuth', 'requireMfaEnrolled'] as const

export const featureFlagsRoutes: RouteRecordRaw[] = [
  {
    path: '/feature-flags',
    name: 'app.feature-flags.list',
    component: () => import('@/modules/feature-flags/pages/FeatureFlagsPage.vue'),
    meta: { layout: 'admin', guards: adminGuards },
  },
]
