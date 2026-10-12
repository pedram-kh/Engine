import { createMemoryHistory } from 'vue-router'
import { describe, expect, it, vi } from 'vitest'

import {
  createPushTapHandler,
  PUSH_TAP_ROUTE_NAME,
  type PushTapDeps,
  type TapResolution,
  type TapRoute,
} from './pushTapRouting'

import { createRouter } from '@/core/router'
import { createTapResolver } from '@/modules/notifications/pushTapResolver'

const NOTIF = '01J9ZK3Q8M5T7WXYNTFAAAAAAA'
const ASSIGNMENT = '01J9ZK3Q8M5T7WXYABCDEFGHJK'

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

function fakeRouter(
  overrides: {
    isReady?: () => Promise<void>
    push?: (to: TapRoute) => unknown
    landedName?: string | null
  } = {},
) {
  const current = {
    value: { name: overrides.landedName ?? ('creator.dashboard' as string | null) },
  }
  const push = vi.fn(overrides.push ?? (() => Promise.resolve()))

  return {
    router: {
      isReady: overrides.isReady ?? (() => Promise.resolve()),
      push,
      currentRoute: current,
    },
    push,
    current,
  }
}

function fakeDeps(over: Partial<PushTapDeps> = {}): PushTapDeps & {
  markRead: ReturnType<typeof vi.fn>
  onMarkedRead: ReturnType<typeof vi.fn>
  resolveTap: ReturnType<typeof vi.fn>
} {
  return {
    resolveTap: vi.fn(async (): Promise<TapResolution> => ({ to: null })),
    hasSession: () => true,
    markRead: vi.fn(async () => undefined),
    onMarkedRead: vi.fn(),
    ...over,
  } as never
}

describe('createPushTapHandler — navigation', () => {
  it('pushes the resolved destination once the router is ready', async () => {
    const { router, push } = fakeRouter()
    const to = { name: 'creator.assignment.detail', params: { ulid: ASSIGNMENT } }
    const deps = fakeDeps({ resolveTap: vi.fn(async () => ({ to })) })

    createPushTapHandler(router, deps)({ type: 'x' })
    await settle()

    expect(push).toHaveBeenCalledTimes(1)
    expect(push).toHaveBeenCalledWith(to)
  })

  it('hands the tap data to resolveTap untouched', async () => {
    const { router } = fakeRouter()
    const deps = fakeDeps()
    const data = { type: 'assignment.invited', 'google.ttl': 86400 }

    createPushTapHandler(router, deps)(data)
    await settle()

    expect(deps.resolveTap.mock.calls[0]![0]).toBe(data)
  })

  it('opens the notification list when nothing resolves (AH-102 behaviour)', async () => {
    const { router, push } = fakeRouter()

    createPushTapHandler(router, fakeDeps())(undefined)
    await settle()

    expect(push).toHaveBeenCalledWith({ name: 'creator.notifications' })
    expect(PUSH_TAP_ROUTE_NAME).toBe('creator.notifications')
  })

  it('does NOT push while the first navigation is still in flight (the cold-start tap)', async () => {
    const ready = deferred()
    const { router, push } = fakeRouter({ isReady: () => ready.promise })
    const deps = fakeDeps()

    createPushTapHandler(router, deps)({ type: 'x' })
    await settle()

    expect(push).not.toHaveBeenCalled()
    expect(deps.resolveTap).not.toHaveBeenCalled() // not even resolved before the router is ready

    ready.resolve()
    await settle()

    expect(push).toHaveBeenCalledTimes(1)
  })

  it('never throws when the router fails to become ready', async () => {
    const { router, push } = fakeRouter({ isReady: () => Promise.reject(new Error('nav failed')) })
    const handler = createPushTapHandler(router, fakeDeps())

    expect(() => handler({})).not.toThrow()
    await settle()
    expect(push).not.toHaveBeenCalled()
  })

  it('a resolver that THROWS still lands on the list', async () => {
    const { router, push } = fakeRouter()
    const deps = fakeDeps({ resolveTap: vi.fn().mockRejectedValue(new Error('boom')) })

    createPushTapHandler(router, deps)({})
    await settle()

    expect(push).toHaveBeenCalledWith({ name: 'creator.notifications' })
  })

  it('a destination whose push FAILS falls back to the list (never a blank screen)', async () => {
    const calls: TapRoute[] = []
    const { router } = fakeRouter({
      push: (to) => {
        calls.push(to)
        return to.name === 'creator.assignment.detail'
          ? Promise.reject(new Error('chunk load failed'))
          : Promise.resolve()
      },
    })
    const deps = fakeDeps({
      resolveTap: vi.fn(async () => ({
        to: { name: 'creator.assignment.detail', params: { ulid: ASSIGNMENT } },
      })),
    })

    createPushTapHandler(router, deps)({})
    await settle()

    expect(calls.map((c) => c.name)).toEqual(['creator.assignment.detail', 'creator.notifications'])
  })

  it('even if the list push also fails, nothing throws', async () => {
    const { router } = fakeRouter({ push: () => Promise.reject(new Error('no')) })

    expect(() => createPushTapHandler(router, fakeDeps())({})).not.toThrow()
    await settle()
  })
})

describe('createPushTapHandler — supersede', () => {
  it('a second tap while the first is still resolving drops the first (the newer one owns the screen)', async () => {
    const slow = deferred<TapResolution>()
    const first = { name: 'creator.assignment.detail', params: { ulid: 'FIRST' } }
    const second = { name: 'creator.job.detail', params: { ulid: 'SECOND' } }
    const resolveTap = vi
      .fn()
      .mockImplementationOnce(() => slow.promise)
      .mockImplementationOnce(async () => ({ to: second }))
    const { router, push } = fakeRouter()
    const deps = fakeDeps({ resolveTap })
    const handler = createPushTapHandler(router, deps)

    handler({ n: 1 })
    await settle()
    handler({ n: 2 })
    await settle()

    expect(push.mock.calls.map((c) => c[0])).toEqual([second])

    slow.resolve({ to: first, notificationUlid: NOTIF })
    await settle()

    // The stale tap neither navigated nor marked anything read.
    expect(push.mock.calls.map((c) => c[0])).toEqual([second])
    expect(deps.markRead).not.toHaveBeenCalled()
  })
})

describe('createPushTapHandler — mark as read', () => {
  const resolved = (
    to: TapRoute | null = { name: 'creator.assignment.detail', params: { ulid: ASSIGNMENT } },
  ) => vi.fn(async () => ({ to, notificationUlid: NOTIF }))

  it('marks the row read AFTER the navigation settles, then refreshes the bell', async () => {
    const nav = deferred()
    const { router } = fakeRouter({ push: () => nav.promise })
    const deps = fakeDeps({ resolveTap: resolved() })

    createPushTapHandler(router, deps)({})
    await settle()

    expect(deps.markRead).not.toHaveBeenCalled() // the push has not settled yet

    nav.resolve()
    await settle()

    expect(deps.markRead).toHaveBeenCalledWith(NOTIF)
    expect(deps.onMarkedRead).toHaveBeenCalledTimes(1)
  })

  it('also marks read when the tap resolved to the list (the user landed, the row is still handled)', async () => {
    const { router } = fakeRouter()
    const deps = fakeDeps({ resolveTap: resolved(null) })

    createPushTapHandler(router, deps)({})
    await settle()

    expect(deps.markRead).toHaveBeenCalledWith(NOTIF)
  })

  it('no notification_ulid (muted in-app) → nothing to mark', async () => {
    const { router } = fakeRouter()
    const deps = fakeDeps({ resolveTap: vi.fn(async () => ({ to: null })) })

    createPushTapHandler(router, deps)({})
    await settle()

    expect(deps.markRead).not.toHaveBeenCalled()
    expect(deps.onMarkedRead).not.toHaveBeenCalled()
  })

  it.each(['auth.sign-in', 'error.auth-bootstrap'])(
    'a user who landed on %s marks NOTHING (an unauthenticated PATCH would trip the native 401 policy)',
    async (landedName) => {
      const { router } = fakeRouter({ landedName })
      const deps = fakeDeps({ resolveTap: resolved() })

      createPushTapHandler(router, deps)({})
      await settle()

      expect(deps.markRead).not.toHaveBeenCalled()
      expect(deps.onMarkedRead).not.toHaveBeenCalled()
    },
  )

  it('no session → nothing is marked (signed-out tap)', async () => {
    const { router } = fakeRouter()
    const deps = fakeDeps({ resolveTap: resolved(), hasSession: () => false })

    createPushTapHandler(router, deps)({})
    await settle()

    expect(deps.markRead).not.toHaveBeenCalled()
  })

  it('a failing mark-read (404 / offline) is swallowed and does not refresh', async () => {
    const { router } = fakeRouter()
    const deps = fakeDeps({
      resolveTap: resolved(),
      markRead: vi.fn().mockRejectedValue(new Error('404')),
    })

    expect(() => createPushTapHandler(router, deps)({})).not.toThrow()
    await settle()

    expect(deps.markRead).toHaveBeenCalledTimes(1)
    expect(deps.onMarkedRead).not.toHaveBeenCalled()
  })

  it('a failed navigation does not block the mark (the user is wherever they already were)', async () => {
    const { router } = fakeRouter({ push: () => Promise.reject(new Error('x')) })
    const deps = fakeDeps({ resolveTap: resolved() })

    createPushTapHandler(router, deps)({})
    await settle()

    expect(deps.markRead).toHaveBeenCalledWith(NOTIF)
  })
})

describe('the real chain: observed device payload → parse → resolve → push, against the REAL route table', () => {
  const real = createRouter(() => createMemoryHistory())

  function chain(session = true) {
    const { router, push } = fakeRouter()
    const deps = fakeDeps({
      resolveTap: createTapResolver({
        hasRoute: (name) => real.hasRoute(name),
        hasSession: () => session,
        lookupThread: vi.fn(async () => null),
      }),
      hasSession: () => session,
    })

    return { handler: createPushTapHandler(router, deps), push, deps }
  }

  /** `notification.data` as the Android device delivered it (inventory §9.3), real-shaped ULIDs. */
  const android = (extra: Record<string, unknown>) => ({
    v: '1',
    notification_ulid: NOTIF,
    from: '1234567890',
    collapse_key: 'com.catalystgrowth.platform',
    'gcm.n.analytics_data': 'Bundle[mParcelledData.dataSize=100]',
    'google.sent_time': 1760000000000,
    'google.ttl': 86400,
    ...extra,
  })

  it('a creator assignment push lands on that assignment, and the pushed location is a real URL', async () => {
    const { handler, push, deps } = chain()

    handler(android({ type: 'assignment.draft_approved', assignment_ulid: ASSIGNMENT }))
    await settle()

    const to = push.mock.calls[0]![0] as TapRoute
    expect(real.resolve(to).fullPath).toBe(`/creator/assignments/${ASSIGNMENT}`)
    expect(deps.markRead).toHaveBeenCalledWith(NOTIF)
  })

  it('a job-posted push lands on that job', async () => {
    const { handler, push } = chain()
    const campaign = '01J9ZK3Q8M5T7WXYCAMPAGNAAA'

    handler(android({ type: 'campaign.job_posted', campaign_ulid: campaign }))
    await settle()

    expect(real.resolve(push.mock.calls[0]![0] as TapRoute).fullPath).toBe(
      `/creator/jobs/${campaign}`,
    )
  })

  it('an account verdict lands on the dashboard', async () => {
    const { handler, push } = chain()

    handler(android({ type: 'creator.approved' }))
    await settle()

    expect(real.resolve(push.mock.calls[0]![0] as TapRoute).fullPath).toBe('/creator/dashboard')
  })

  it('a thread push with no session degrades to the list (P-Q2) and marks nothing', async () => {
    const { handler, push, deps } = chain(false)

    handler(
      android({
        type: 'message.relationship_received_by_creator',
        thread_ulid: '01J9ZK3Q8M5T7WXYTHREADAAAA',
      }),
    )
    await settle()

    expect(push).toHaveBeenCalledWith({ name: 'creator.notifications' })
    expect(deps.markRead).not.toHaveBeenCalled()
  })

  it('garbage, an unknown type, and a payload with a malformed id all open the list', async () => {
    for (const data of [
      undefined,
      'nope',
      { type: 'assignment.cancelled', assignment_ulid: ASSIGNMENT },
      { type: 'assignment.invited', assignment_ulid: '01SPIKEASSIGN' },
    ]) {
      const { handler, push } = chain()
      handler(data)
      await settle()

      expect(push).toHaveBeenCalledWith({ name: 'creator.notifications' })
    }
  })
})
