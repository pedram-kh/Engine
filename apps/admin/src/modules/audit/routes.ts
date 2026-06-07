/**
 * Admin SPA audit-module route table (Sprint 13, D-5).
 *
 * The audit-log viewer reads the existing `audit_logs` table via the
 * net-new `GET /admin/audit-logs` endpoint (filter + cursor pagination).
 * `layout: 'admin'` + the mandatory-MFA guard chain.
 */

import type { RouteRecordRaw } from 'vue-router'

const adminGuards = ['requireAuth', 'requireMfaEnrolled'] as const

export const auditRoutes: RouteRecordRaw[] = [
  {
    path: '/audit-logs',
    name: 'app.audit.list',
    component: () => import('@/modules/audit/pages/AuditLogPage.vue'),
    meta: { layout: 'admin', guards: adminGuards },
  },
]
