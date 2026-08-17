/**
 * Vue Router v4 instance for the main SPA.
 *
 * Pre-answered chunk-6.5 Q1: HTML5 history mode (no hash routing). Guards
 * declared on `meta.guards` are dispatched in a single `beforeEach` so
 * the guard chain stays declarative — adding a new guarded route is a
 * data change, not a wiring change.
 *
 * The route table itself lives in `apps/main/src/modules/auth/routes.ts`;
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
  type RouterScrollBehavior,
} from 'vue-router'

import { useAuthStore } from '@/modules/auth/stores/useAuthStore'
import { routes } from '@/modules/auth/routes'

import { guards, type GuardContext } from './guards'

/**
 * Resolve `meta.guards` to the actual guard composables and run them in
 * declaration order. The first guard returning a redirect short-circuits
 * the chain.
 *
 * Exported so tests can exercise the dispatcher without mounting the
 * full router.
 */
export async function runGuards(
  to: RouteLocationNormalized,
  from: RouteLocationNormalized,
  store: ReturnType<typeof useAuthStore>,
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
 * How long scroll restoration will wait for an async list to render tall
 * enough to hold the saved offset before giving up and scrolling as far as it
 * can. Longer than a warm list request, short enough that a genuinely slow
 * page does not feel stuck mid-navigation.
 */
const SCROLL_RESTORE_TIMEOUT_MS = 1200

const SCROLL_RESTORE_POLL_MS = 50

/** Is the document already tall enough to scroll to `top`? */
function canScrollTo(top: number): boolean {
  const el = document.documentElement
  return el.scrollHeight - el.clientHeight >= top
}

/**
 * Restoring a scroll offset only works once the content that made the page
 * that tall is back on screen — and our lists paint a short skeleton first,
 * then fetch. Scrolling at `nextTick` would therefore land at the bottom of
 * the skeleton. Poll instead, and cap the wait so a failed request cannot hang
 * the navigation.
 */
async function waitForScrollHeight(top: number): Promise<void> {
  const deadline = Date.now() + SCROLL_RESTORE_TIMEOUT_MS
  while (!canScrollTo(top) && Date.now() < deadline) {
    await new Promise((resolve) => setTimeout(resolve, SCROLL_RESTORE_POLL_MS))
  }
}

/**
 * Back/forward returns the operator where they were; every other navigation
 * starts at the top. Exported for direct unit testing — the router only ever
 * calls it in a real browser.
 */
export const scrollBehavior: RouterScrollBehavior = async (_to, _from, savedPosition) => {
  if (savedPosition === null) {
    return { top: 0 }
  }
  await waitForScrollHeight(savedPosition.top)
  return savedPosition
}

/**
 * Build a fresh `Router` instance. Production wiring uses the module-level
 * singleton `router`; tests that need a clean router (e.g. to avoid leaked
 * navigation history between cases) call this directly.
 *
 * Accepts an optional history factory so tests can swap `createWebHistory`
 * for `createMemoryHistory` without spinning a JSDOM URL.
 */
export function createRouter(
  historyFactory: () => ReturnType<typeof createWebHistory> = () =>
    createWebHistory(import.meta.env.BASE_URL),
): Router {
  const r = createVueRouter({
    history: historyFactory(),
    routes,
    scrollBehavior,
  })

  r.beforeEach(async (to, from) => {
    const store = useAuthStore()
    return (await runGuards(to, from, store)) ?? true
  })

  return r
}

export const router: Router = createRouter()
