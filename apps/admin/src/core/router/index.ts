/**
 * Vue Router v4 instance for the admin SPA.
 *
 * Mirror of `apps/main/src/core/router/index.ts` (chunks 6.5–6.7) with
 * the admin route table (`apps/admin/src/modules/auth/routes.ts`) and
 * the admin Pinia store (`useAdminAuthStore`) substituted into the
 * `beforeEach` hook.
 *
 * Pre-answered chunk-6.5 Q1 (carried forward from main): HTML5 history
 * mode (no hash routing). Guards declared on `meta.guards` are
 * dispatched in a single `beforeEach` so the guard chain stays
 * declarative — adding a new guarded route is a data change, not a
 * wiring change.
 *
 * The route table itself lives in `apps/admin/src/modules/auth/routes.ts`;
 * this file owns the runtime instance + the guard dispatcher + the helper
 * for tests that need a fresh router with the same wiring.
 *
 * Coverage scope: the dispatcher logic (`runGuards`) is fully unit-tested
 * via `tests/unit/core/router/index.spec.ts`. The `createRouter` factory
 * itself is a thin wrapper over Vue Router's primitives and is exercised
 * by both the dispatcher tests and the App.vue mount test.
 */

import {
  createRouter as createVueRouter,
  createWebHistory,
  type Router,
  type RouteLocationNormalized,
  type RouteLocationRaw,
} from 'vue-router'

import { useAdminAuthStore } from '@/modules/auth/stores/useAdminAuthStore'
import { routes } from '@/modules/auth/routes'

import { guards, type GuardContext } from './guards'

/**
 * Resolve `meta.guards` to the actual guard composables and run them
 * in declaration order. The first guard returning a redirect
 * short-circuits the chain.
 *
 * Exported so tests can exercise the dispatcher without mounting the
 * full router.
 */
export async function runGuards(
  to: RouteLocationNormalized,
  from: RouteLocationNormalized,
  store: ReturnType<typeof useAdminAuthStore>,
): Promise<RouteLocationRaw | null> {
  const names = to.meta.guards ?? []
  if (names.length === 0) {
    return null
  }
  const ctx: GuardContext = { to, from, store }
  for (const name of names) {
    const guard = guards[name]
    const result = await guard(ctx)
    if (result !== null) {
      return result
    }
  }
  return null
}

/**
 * Build a fresh `Router` instance. Production wiring uses the
 * module-level singleton `router`; tests that need a clean router
 * (e.g. to avoid leaked navigation history between cases) call this
 * directly.
 *
 * Accepts an optional history factory so tests can swap
 * `createWebHistory` for `createMemoryHistory` without spinning a
 * JSDOM URL.
 */
export function createRouter(
  historyFactory: () => ReturnType<typeof createWebHistory> = () =>
    createWebHistory(import.meta.env.BASE_URL),
): Router {
  const r = createVueRouter({
    history: historyFactory(),
    routes,
  })

  r.beforeEach(async (to, from) => {
    const store = useAdminAuthStore()
    const result = await runGuards(to, from, store)
    return result ?? true
  })

  return r
}

export const router: Router = createRouter()
