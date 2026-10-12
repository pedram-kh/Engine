/**
 * Admin SPA creators-module route table.
 *
 * Sprint 3 Chunk 3 sub-step 9 — read-only creator drill-in. The
 * approve/reject workflow + per-field edit modal ship in Chunk 4
 * per pause-condition-6 closure.
 *
 * Sprint 13 (D-1 / D-4): migrated `layout: 'app'` → `'admin'` (the
 * Sprint-13 shell). Added the KYC review queue (a queue DISTINCT from
 * the application-approval queue, § 6.4) and the "all creators" surface.
 * `app.creators.list` is the application-approval queue (the
 * pre-existing `?status=` queue); `app.creators.kyc` filters on
 * `?kyc_status=pending`.
 *
 * Routes are gated by `requireAuth` + `requireMfaEnrolled` per the
 * admin SPA's mandatory-MFA model (docs/05-SECURITY-COMPLIANCE.md
 * § 6.3).
 */

import type { RouteRecordRaw } from 'vue-router'

const adminGuards = ['requireAuth', 'requireMfaEnrolled'] as const

export const creatorsRoutes: RouteRecordRaw[] = [
  {
    path: '/creators',
    name: 'app.creators.list',
    component: () => import('@/modules/creators/pages/CreatorListPage.vue'),
    meta: { layout: 'admin', guards: adminGuards },
  },
  {
    path: '/creators/kyc',
    name: 'app.creators.kyc',
    component: () => import('@/modules/creators/pages/KycQueuePage.vue'),
    meta: { layout: 'admin', guards: adminGuards },
  },
  {
    path: '/creators/all',
    name: 'app.creators.all',
    component: () => import('@/modules/creators/pages/AllCreatorsPage.vue'),
    meta: { layout: 'admin', guards: adminGuards },
  },
  // AH-102b — creator-app usage. Registered BEFORE `/creators/:ulid`, like
  // `/creators/all` and `/creators/kyc`: a static segment must not be read as
  // a creator ULID. (vue-router ranks static above dynamic, so this is
  // belt-and-braces — a route-order spec keeps it deliberate.)
  {
    path: '/creators/app',
    name: 'app.creators.app',
    component: () => import('@/modules/creators/pages/CreatorAppPage.vue'),
    meta: { layout: 'admin', guards: adminGuards },
  },
  {
    path: '/creators/:ulid',
    name: 'app.creators.detail',
    component: () => import('@/modules/creators/pages/CreatorDetailPage.vue'),
    meta: { layout: 'admin', guards: adminGuards },
  },
]
