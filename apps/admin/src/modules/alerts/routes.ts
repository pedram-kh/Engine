/**
 * Admin SPA operational-alerts route table (Sprint 13, D-12).
 *
 * The non-payment admin notification consumer surface. The payment-event
 * alerts are a coming-soon block WITHIN the page (driven by the backend's
 * `meta.payment_alerts`), so there is one route here, not a separate
 * coming-soon route. `layout: 'admin'` + mandatory-MFA guards.
 */

import type { RouteRecordRaw } from 'vue-router'

const adminGuards = ['requireAuth', 'requireMfaEnrolled'] as const

export const alertsRoutes: RouteRecordRaw[] = [
  {
    path: '/alerts',
    name: 'app.alerts.list',
    component: () => import('@/modules/alerts/pages/OperationalAlertsPage.vue'),
    meta: { layout: 'admin', guards: adminGuards },
  },
]
