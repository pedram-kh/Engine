/**
 * Unit tests for the idle-timeout composable.
 *
 * Coverage requirement: 100% lines / branches / functions / statements
 * (auth-flow gate per docs/02-CONVENTIONS.md § 4.3).
 *
 * The composable accepts overrides for the lifecycle hooks, the store,
 * the router, and the global target so we can drive it without
 * mounting a component. Each test wires its own fakes — there is no
 * cross-test state.
 */

import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest'

import { useIdleTimeout, DEFAULT_IDLE_TIMEOUT_MINUTES, ACTIVITY_EVENTS } from './useIdleTimeout'

interface Fixture {
  logout: ReturnType<typeof vi.fn>
  push: ReturnType<typeof vi.fn>
  addEventListener: ReturnType<typeof vi.fn>
  removeEventListener: ReturnType<typeof vi.fn>
  registered: Map<string, EventListener>
  /**
   * Captured by the fake `onMounted` / `onBeforeUnmount`. We invoke
   * them manually to drive the lifecycle.
   */
  mountCb: (() => void) | null
  unmountCb: (() => void) | null
}

function makeFixture(): Fixture {
  const logout = vi.fn(async () => undefined)
  const push = vi.fn(() => Promise.resolve())
  const registered = new Map<string, EventListener>()
  const addEventListener = vi.fn((event: string, listener: EventListener) => {
    registered.set(event, listener)
  })
  const removeEventListener = vi.fn((event: string) => {
    registered.delete(event)
  })

  const fixture: Fixture = {
    logout,
    push,
    addEventListener,
    removeEventListener,
    registered,
    mountCb: null,
    unmountCb: null,
  }
  return fixture
}

function install(fixture: Fixture, durationMinutes?: number): void {
  useIdleTimeout(durationMinutes, {
    store: { logout: fixture.logout } as unknown as ReturnType<
      typeof import('@/modules/auth/stores/useAuthStore').useAuthStore
    >,
    router: { push: fixture.push } as unknown as ReturnType<typeof import('vue-router').useRouter>,
    onMounted: ((cb: () => void) => {
      fixture.mountCb = cb
    }) as unknown as typeof import('vue').onMounted,
    onBeforeUnmount: ((cb: () => void) => {
      fixture.unmountCb = cb
    }) as unknown as typeof import('vue').onBeforeUnmount,
    target: {
      addEventListener: fixture.addEventListener,
      removeEventListener: fixture.removeEventListener,
    } as unknown as Window,
  })
}

beforeEach(() => {
  vi.useFakeTimers()
})

afterEach(() => {
  vi.useRealTimers()
})

describe('useIdleTimeout', () => {
  it('exports the documented default duration (30 minutes per § 6 spec)', () => {
    expect(DEFAULT_IDLE_TIMEOUT_MINUTES).toBe(30)
  })

  it('exports the activity event list with all five events from the kickoff', () => {
    expect(new Set(ACTIVITY_EVENTS)).toEqual(
      new Set(['mousemove', 'keydown', 'click', 'scroll', 'touchstart']),
    )
  })

  it('attaches one listener per activity event on mount', () => {
    const f = makeFixture()
    install(f)

    f.mountCb?.()

    expect(f.addEventListener).toHaveBeenCalledTimes(ACTIVITY_EVENTS.length)
    for (const event of ACTIVITY_EVENTS) {
      expect(f.registered.has(event)).toBe(true)
    }
  })

  it('detaches every listener on unmount (memory-leak guard, review priority #3)', () => {
    const f = makeFixture()
    install(f)

    f.mountCb?.()
    f.unmountCb?.()

    expect(f.removeEventListener).toHaveBeenCalledTimes(ACTIVITY_EVENTS.length)
    for (const event of ACTIVITY_EVENTS) {
      expect(f.registered.has(event)).toBe(false)
    }
  })

  // Helper to flush all microtasks queued by awaited fakes
  // (logout + router.push). `advanceTimersByTimeAsync` flushes the
  // immediate microtask, but the chained `await store.logout()` then
  // `router.push(...)` needs an extra round-trip.
  async function flushMicrotasks(): Promise<void> {
    for (let i = 0; i < 5; i++) {
      await Promise.resolve()
    }
  }

  it('fires logout() and pushes to auth.sign-in after the duration elapses', async () => {
    const f = makeFixture()
    install(f, 5) // 5 minutes
    f.mountCb?.()

    expect(f.logout).not.toHaveBeenCalled()

    await vi.advanceTimersByTimeAsync(5 * 60_000)
    await flushMicrotasks()

    expect(f.logout).toHaveBeenCalledTimes(1)
    expect(f.push).toHaveBeenCalledWith({
      name: 'auth.sign-in',
      query: { reason: 'session_expired' },
    })
  })

  it('uses the default duration when called with no argument', async () => {
    const f = makeFixture()
    install(f) // default = 30
    f.mountCb?.()

    await vi.advanceTimersByTimeAsync(DEFAULT_IDLE_TIMEOUT_MINUTES * 60_000 - 1)
    expect(f.logout).not.toHaveBeenCalled()

    await vi.advanceTimersByTimeAsync(1)
    await flushMicrotasks()

    expect(f.logout).toHaveBeenCalledTimes(1)
  })

  it('resets the timer on every activity event', async () => {
    const f = makeFixture()
    install(f, 5)
    f.mountCb?.()

    await vi.advanceTimersByTimeAsync(4 * 60_000)
    expect(f.logout).not.toHaveBeenCalled()

    // Activity 1 minute before fire — resets the timer to a fresh 5
    // minutes.
    f.registered.get('mousemove')?.(new Event('mousemove'))
    await vi.advanceTimersByTimeAsync(4 * 60_000)
    expect(f.logout).not.toHaveBeenCalled()

    await vi.advanceTimersByTimeAsync(60_000)
    await flushMicrotasks()
    expect(f.logout).toHaveBeenCalledTimes(1)
  })

  it('still redirects when logout() rejects (defensive)', async () => {
    const f = makeFixture()
    f.logout.mockRejectedValue(new Error('boom'))
    install(f, 1)
    f.mountCb?.()

    await vi.advanceTimersByTimeAsync(60_000)
    await flushMicrotasks()

    expect(f.logout).toHaveBeenCalledTimes(1)
    expect(f.push).toHaveBeenCalledWith({
      name: 'auth.sign-in',
      query: { reason: 'session_expired' },
    })
  })

  it('clears the pending timer when unmounted before it fires', async () => {
    const f = makeFixture()
    install(f, 5)
    f.mountCb?.()
    f.unmountCb?.()

    await vi.advanceTimersByTimeAsync(10 * 60_000)
    await flushMicrotasks()

    expect(f.logout).not.toHaveBeenCalled()
    expect(f.push).not.toHaveBeenCalled()
  })

  it('fires only once even if multiple activity events arrive in the same tick', async () => {
    const f = makeFixture()
    install(f, 5)
    f.mountCb?.()

    f.registered.get('mousemove')?.(new Event('mousemove'))
    f.registered.get('keydown')?.(new Event('keydown'))
    f.registered.get('click')?.(new Event('click'))
    f.registered.get('scroll')?.(new Event('scroll'))
    f.registered.get('touchstart')?.(new Event('touchstart'))

    await vi.advanceTimersByTimeAsync(5 * 60_000)
    await flushMicrotasks()

    expect(f.logout).toHaveBeenCalledTimes(1)
    expect(f.push).toHaveBeenCalledTimes(1)
  })
})
