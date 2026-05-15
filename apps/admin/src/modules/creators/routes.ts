/**
 * Admin SPA creators-module route table.
 *
 * Sprint 3 Chunk 3 sub-step 9 — read-only creator drill-in. The
 * approve/reject workflow + per-field edit modal ship in Chunk 4
 * per pause-condition-6 closure.
 *
 * Routes are gated by `requireAuth` + `requireMfaEnrolled` per the
 * admin SPA's mandatory-MFA model (docs/05-SECURITY-COMPLIANCE.md
 * § 6.3).
 */

import type { RouteRecordRaw } from 'vue-router'

export const creatorsRoutes: RouteRecordRaw[] = [
  {
    path: '/creators/:ulid',
    name: 'app.creators.detail',
    component: () => import('@/modules/creators/pages/CreatorDetailPage.vue'),
    meta: { layout: 'app', guards: ['requireAuth', 'requireMfaEnrolled'] },
  },
]
