/**
 * Admin SPA payments-module route table (Sprint 13, D-13 — COMING SOON).
 *
 * Every payment-touching surface ships this sprint as a discrete,
 * swappable coming-soon block: Sprint 10 lights them up by replacing the
 * `ComingSoonPage` component reference with the real page (and dropping
 * the `comingSoon` route prop), NOT by unpicking payment assumptions
 * woven elsewhere. The routes resolve today so the nav links land
 * somewhere honest. `layout: 'admin'` + mandatory-MFA guards.
 */

import type { RouteRecordRaw } from 'vue-router'

const adminGuards = ['requireAuth', 'requireMfaEnrolled'] as const

export const paymentsRoutes: RouteRecordRaw[] = [
  {
    path: '/payments/disputes',
    name: 'app.payments.disputes',
    component: () => import('@/core/pages/ComingSoonPage.vue'),
    props: { titleKey: 'app.comingSoon.disputes' },
    meta: { layout: 'admin', guards: adminGuards },
  },
  {
    path: '/payments/recent',
    name: 'app.payments.recent',
    component: () => import('@/core/pages/ComingSoonPage.vue'),
    props: { titleKey: 'app.comingSoon.recentPayments' },
    meta: { layout: 'admin', guards: adminGuards },
  },
]
