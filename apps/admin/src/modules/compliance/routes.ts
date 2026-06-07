/**
 * Admin SPA compliance-module route table (Sprint 13, D-11).
 *
 * GDPR export + erasure queues ship this sprint as EMPTY SHELLS wired to
 * a `[]`-returning stub API — the `data_export_requests` /
 * `data_erasure_requests` tables + the export/erasure machinery are
 * Sprint 14. The surfaces exist now so S14 fills data into them rather
 * than building new pages. `layout: 'admin'` + mandatory-MFA guards.
 */

import type { RouteRecordRaw } from 'vue-router'

const adminGuards = ['requireAuth', 'requireMfaEnrolled'] as const

export const complianceRoutes: RouteRecordRaw[] = [
  {
    path: '/compliance/export-requests',
    name: 'app.compliance.exports',
    component: () => import('@/modules/compliance/pages/ExportRequestsPage.vue'),
    meta: { layout: 'admin', guards: adminGuards },
  },
  {
    path: '/compliance/erasure-queue',
    name: 'app.compliance.erasures',
    component: () => import('@/modules/compliance/pages/ErasureQueuePage.vue'),
    meta: { layout: 'admin', guards: adminGuards },
  },
]
