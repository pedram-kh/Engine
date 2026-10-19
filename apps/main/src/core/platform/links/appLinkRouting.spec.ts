import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory } from 'vue-router'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { APP_LINK_HOST } from './appLink'
import {
  APP_LINK_DUPLICATE_WINDOW_MS,
  createAppLinkHandler,
  type AppLinkRouter,
} from './appLinkRouting'

import { createRouter } from '@/core/router'
import { useAuthStore } from '@/modules/auth/stores/useAuthStore'

const ULID = '01J9ZK3Q8M5T7WXYABCDEFGHJK'
const OTHER = '01J9ZK3Q8M5T7WXYZZZZZZZZZZ'
const HOST = `https://${APP_LINK_HOST}`
const JOB = `${HOST}/creator/jobs/${ULID}`

function deferred<T = void>(): { promise: Promise<T>; resolve: (v: T) => void } {
  let resolve!: (v: T) => void
  const promise = new Promise<T>((r) => {
    resolve = r
  })
  return { promise, resolve }
}

/** Let every queued microtask (and a few chained ones) run. */
async function settle(): Promise<void> {
  for (let i = 0; i < 12; i++) {
    await Promise.resolve()
  }
}

/** A router whose `resolve` knows the creator job route and nothing else. */
function fakeRouter(
  over: {
    isReady?: () => Promise<void>
    push?: (to: string | { name: string }) => unknown
  } = {},
) {
  const push = vi.fn(over.push ?? (() => Promise.resolve()))

  const router: AppLinkRouter = {
    isReady: over.isReady ?? (() => Promise.resolve()),
    push,
    resolve: ({ path, query }) => {
      const known = /^\/creator\/(jobs|assignments)\/[A-Za-z0-9]+$/.test(path)
      const qs = new URLSearchParams(query).toString()

      return {
        name: known ? 'creator.jobs.detail' : null,
        matched: known ? [{}] : [],
        fullPath: qs === '' ? path : `${path}?${qs}`,
      }
    },
  }

  return { router, push }
}

describe('createAppLinkHandler — navigation', () => {
  it('pushes the link’s own screen once the router is ready', async () => {
    const { router, push } = fakeRouter()

    createAppLinkHandler(router)(JOB)
    await settle()

    expect(push).toHaveBeenCalledTimes(1)
    expect(push).toHaveBeenCalledWith(`/creator/jobs/${ULID}`)
  })

  it('carries the allow-listed query and nothing else', async () => {
    const { router, push } = fakeRouter()

    createAppLinkHandler(router)(`${HOST}/creator/assignments/${ULID}?focus=messages&utm=x`)
    await settle()

    expect(push).toHaveBeenCalledWith(`/creator/assignments/${ULID}?focus=messages`)
  })

  it('an in-scope link that does not resolve opens the DASHBOARD (D2, as cleared)', async () => {
    const { router, push } = fakeRouter()

    createAppLinkHandler(router)(`${HOST}/creator/nonsense`)
    await settle()

    expect(push).toHaveBeenCalledTimes(1)
    expect(push).toHaveBeenCalledWith({ name: 'creator.dashboard' })
  })

  it('an in-scope link with a hostile path opens the dashboard and never hands the path to the router', async () => {
    const { router, push } = fakeRouter()
    const resolve = vi.spyOn(router, 'resolve')

    createAppLinkHandler(router)(`${HOST}/creator/jobs/a%2fb`)
    await settle()

    expect(push).toHaveBeenCalledWith({ name: 'creator.dashboard' })
    expect(resolve).not.toHaveBeenCalled()
  })

  it.each([
    ['another host', `https://example.com/creator/jobs/${ULID}`],
    ['an auth token link', `${HOST}/auth/verify-email?token=x`],
    ['an agency path', `${HOST}/campaigns/${ULID}`],
    ['garbage', 'not a url'],
  ])('an OUT-of-scope link (%s) does nothing', async (_label, url) => {
    const { router, push } = fakeRouter()

    createAppLinkHandler(router)(url)
    await settle()

    expect(push).not.toHaveBeenCalled()
  })

  it('does NOT push while the first navigation is still in flight (the cold-start link)', async () => {
    const ready = deferred()
    const { router, push } = fakeRouter({ isReady: () => ready.promise })
    const resolve = vi.spyOn(router, 'resolve')

    createAppLinkHandler(router)(JOB)
    await settle()

    expect(push).not.toHaveBeenCalled()
    expect(resolve).not.toHaveBeenCalled() // not even resolved before the router is ready

    ready.resolve()
    await settle()

    expect(push).toHaveBeenCalledTimes(1)
    expect(push).toHaveBeenCalledWith(`/creator/jobs/${ULID}`)
  })

  it('never throws when the router fails to become ready', async () => {
    const { router, push } = fakeRouter({ isReady: () => Promise.reject(new Error('nav failed')) })
    const handler = createAppLinkHandler(router)

    expect(() => handler(JOB)).not.toThrow()
    await settle()
    expect(push).not.toHaveBeenCalled()
  })

  it('a screen whose push FAILS falls back to the dashboard (never a blank screen)', async () => {
    const { router, push } = fakeRouter({
      push: (to) => (typeof to === 'string' ? Promise.reject(new Error('x')) : Promise.resolve()),
    })

    createAppLinkHandler(router)(JOB)
    await settle()

    expect(push).toHaveBeenCalledTimes(2)
    expect(push).toHaveBeenNthCalledWith(1, `/creator/jobs/${ULID}`)
    expect(push).toHaveBeenNthCalledWith(2, { name: 'creator.dashboard' })
  })

  it('a dashboard push that fails too is swallowed', async () => {
    const { router, push } = fakeRouter({ push: () => Promise.reject(new Error('x')) })
    const handler = createAppLinkHandler(router)

    expect(() => handler(JOB)).not.toThrow()
    await settle()

    expect(push).toHaveBeenCalledTimes(2)
  })

  it('a failing dashboard push (the in-scope fallback) is swallowed and not retried', async () => {
    const { router, push } = fakeRouter({ push: () => Promise.reject(new Error('x')) })

    expect(() => createAppLinkHandler(router)(`${HOST}/creator/nonsense`)).not.toThrow()
    await settle()

    expect(push).toHaveBeenCalledTimes(1)
  })

  it('a router whose resolve THROWS lands on the dashboard', async () => {
    const { router, push } = fakeRouter()
    router.resolve = () => {
      throw new Error('boom')
    }

    createAppLinkHandler(router)(JOB)
    await settle()

    expect(push).toHaveBeenCalledWith({ name: 'creator.dashboard' })
  })
})

describe('createAppLinkHandler — a newer link supersedes an older one still waiting', () => {
  it('drops the stale link before it can navigate over the newer one', async () => {
    const ready = deferred()
    const { router, push } = fakeRouter({ isReady: () => ready.promise })
    const handler = createAppLinkHandler(router)

    handler(JOB)
    handler(`${HOST}/creator/jobs/${OTHER}`)
    await settle()

    ready.resolve()
    await settle()

    expect(push).toHaveBeenCalledTimes(1)
    expect(push).toHaveBeenCalledWith(`/creator/jobs/${OTHER}`)
  })

  it('two links in sequence, each after the router is ready, both navigate', async () => {
    const { router, push } = fakeRouter()
    const handler = createAppLinkHandler(router)

    handler(JOB)
    await settle()
    handler(`${HOST}/creator/jobs/${OTHER}`)
    await settle()

    expect(push).toHaveBeenCalledTimes(2)
  })
})

describe('createAppLinkHandler — the 3 s duplicate guard (D3)', () => {
  it('pins the window at 3 seconds', () => {
    expect(APP_LINK_DUPLICATE_WINDOW_MS).toBe(3_000)
  })

  it('drops the same URL delivered twice inside the window (appUrlOpen + getLaunchUrl)', async () => {
    const { router, push } = fakeRouter()
    let t = 1_000
    const handler = createAppLinkHandler(router, { now: () => t })

    handler(JOB)
    t += APP_LINK_DUPLICATE_WINDOW_MS - 1
    handler(JOB)
    await settle()

    expect(push).toHaveBeenCalledTimes(1)
  })

  it('handles the same URL again once the window has passed', async () => {
    const { router, push } = fakeRouter()
    let t = 1_000
    const handler = createAppLinkHandler(router, { now: () => t })

    handler(JOB)
    await settle()
    t += APP_LINK_DUPLICATE_WINDOW_MS
    handler(JOB)
    await settle()

    expect(push).toHaveBeenCalledTimes(2)
  })

  it('a DIFFERENT URL inside the window is not a duplicate', async () => {
    const { router, push } = fakeRouter()
    let t = 1_000
    const handler = createAppLinkHandler(router, { now: () => t })

    handler(JOB)
    await settle()
    t += 10
    handler(`${HOST}/creator/jobs/${OTHER}`)
    await settle()

    expect(push).toHaveBeenCalledTimes(2)
  })

  it('the window measures from the last HANDLED delivery, so a steady re-tap stream is not starved forever', async () => {
    const { router, push } = fakeRouter()
    let t = 1_000
    const handler = createAppLinkHandler(router, { now: () => t })

    handler(JOB)
    await settle()
    t += 2_000
    handler(JOB) // dropped, inside the window
    t += 2_000 // 4 s after the first delivery, 2 s after the dropped one
    handler(JOB)
    await settle()

    expect(push).toHaveBeenCalledTimes(1 + 1)
  })
})

describe('signed out: the destination returns after sign-in (R5), through the REAL guard', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    // jsdom has no scrolling; the router's scroll behaviour calls it on every navigation.
    vi.stubGlobal('scrollTo', vi.fn())
  })

  it('a creator link while signed out lands on sign-in carrying redirect=<the link’s screen>', async () => {
    const auth = useAuthStore()
    vi.spyOn(auth, 'bootstrap').mockResolvedValue(undefined as never)

    const router = createRouter(() => createMemoryHistory())
    await router.replace('/sign-in')
    await router.isReady()
    expect(auth.user).toBeNull()

    createAppLinkHandler(router)(`${HOST}/creator/assignments/${ULID}?focus=messages`)
    // Already ON sign-in before the link: wait for the redirect the guard adds.
    await vi.waitFor(() => expect(router.currentRoute.value.query.redirect).toBeDefined(), {
      timeout: 5_000,
    })

    expect(router.currentRoute.value.name).toBe('auth.sign-in')
    expect(router.currentRoute.value.query.redirect).toBe(
      `/creator/assignments/${ULID}?focus=messages`,
    )
  })

  it('an in-scope unusable link while signed out returns to the DASHBOARD after sign-in', async () => {
    const auth = useAuthStore()
    vi.spyOn(auth, 'bootstrap').mockResolvedValue(undefined as never)

    const router = createRouter(() => createMemoryHistory())
    await router.replace('/sign-in')
    await router.isReady()

    createAppLinkHandler(router)(`${HOST}/creator/nonsense`)
    await vi.waitFor(() => expect(router.currentRoute.value.query.redirect).toBeDefined(), {
      timeout: 5_000,
    })

    expect(router.currentRoute.value.name).toBe('auth.sign-in')
    expect(router.currentRoute.value.query.redirect).toBe('/creator/dashboard')
  })
})
