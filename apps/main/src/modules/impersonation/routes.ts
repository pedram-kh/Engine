/**
 * Main-SPA impersonation route table (Sprint 13, D-9 / D-10).
 *
 * A single public landing: the hand-off claim. It is intentionally
 * guard-free — the admin arrives here with a one-time token in the URL
 * fragment BEFORE any `web` session exists, so `requireAuth` would bounce
 * them. The page itself consumes the token, establishes the impersonated
 * session, and only then navigates into the guarded app.
 */

import type { RouteRecordRaw } from 'vue-router'

export const impersonationRoutes: RouteRecordRaw[] = [
  {
    path: '/impersonation/claim',
    name: 'impersonation.claim',
    component: () => import('./pages/ClaimImpersonationPage.vue'),
    meta: { layout: 'app' },
  },
]
