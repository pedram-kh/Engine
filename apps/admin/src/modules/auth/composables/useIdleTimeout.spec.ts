/**
 * Admin idle + absolute session-timeout composable tests (Sprint 13, D-11).
 *
 * The admin console runs TWO concurrent caps: a 30-min idle timer (reset
 * on activity) and an 8-h absolute cap (armed once, NEVER reset). These
 * cases pin both, plus the "fires only once" invariant when both/either
 * elapse and the listener lifecycle.
 *
 * The composable accepts lifecycle / store / router / target overrides so
 * it can be driven without mounting a component (mirror of main's spec).
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import {
  useIdleTimeout,
  DEFAULT_ADMIN_IDLE_TIMEOUT_MINUTES,
  DEFAULT_ADMIN_ABSOLUTE_TIMEOUT_MINUTES,
  ACTIVITY_EVENTS,
} from './useIdleTimeout'

interface Fixture {
  logout: ReturnType<typeof vi.fn>
  push: ReturnType<typeof vi.fn>
  registered: Map<string, EventListener>
  addEventListener: ReturnType<typeof vi.fn>
  removeEventListener: ReturnType<typeof vi.fn>
  mountCb: (() => void) | null
  unmountCb: (() => void) | null
}

function makeFixture(): Fixture {
  const registered = new Map<string, EventListener>()
  const f: Fixture = {
    logout: vi.fn(async () => undefined),
    push: vi.fn(() => Promise.resolve()),
    registered,
    addEventListener: vi.fn((event: string, listener: EventListener) => {
      registered.set(event, listener)
    }),
    removeEventListener: vi.fn((event: string) => {
      registered.delete(event)
    }),
    mountCb: null,
    unmountCb: null,
  }
  return f
}

function install(f: Fixture, idleMinutes?: number, absoluteMinutes?: number): void {
  useIdleTimeout(idleMinutes, absoluteMinutes, {
    store: { logout: f.logout } as unknown as ReturnType<
      typeof import('@/modules/auth/stores/useAdminAuthStore').useAdminAuthStore
    >,
    router: { push: f.push } as unknown as ReturnType<typeof import('vue-router').useRouter>,
    onMounted: ((cb: () => void) => {
      f.mountCb = cb
    }) as unknown as typeof import('vue').onMounted,
    onBeforeUnmount: ((cb: () => void) => {
      f.unmountCb = cb
    }) as unknown as typeof import('vue').onBeforeUnmount,
    target: {
      addEventListener: f.addEventListener,
      removeEventListener: f.removeEventListener,
    } as unknown as Window,
  })
}

async function flushMicrotasks(): Promise<void> {
  for (let i = 0; i < 5; i++) {
    await Promise.resolve()
  }
}

beforeEach(() => {
  vi.useFakeTimers()
})

afterEach(() => {
  vi.useRealTimers()
})

describe('admin useIdleTimeout (Sprint 13, D-11)', () => {
  it('exports the tightened admin defaults: 30-min idle, 8-h absolute', () => {
    expect(DEFAULT_ADMIN_IDLE_TIMEOUT_MINUTES).toBe(30)
    expect(DEFAULT_ADMIN_ABSOLUTE_TIMEOUT_MINUTES).toBe(480)
  })

  it('attaches one listener per activity event on mount', () => {
    const f = makeFixture()
    install(f)
    f.mountCb?.()

    expect(f.addEventListener).toHaveBeenCalledTimes(ACTIVITY_EVENTS.length)
  })

  it('signs out after the idle timeout elapses with no activity', async () => {
    const f = makeFixture()
    install(f, 5, 480)
    f.mountCb?.()

    await vi.advanceTimersByTimeAsync(5 * 60_000)
    await flushMicrotasks()

    expect(f.logout).toHaveBeenCalledTimes(1)
    expect(f.push).toHaveBeenCalledWith({
      name: 'auth.sign-in',
      query: { reason: 'session_expired' },
    })
  })

  it('resets the IDLE timer on activity', async () => {
    const f = makeFixture()
    install(f, 5, 480)
    f.mountCb?.()

    await vi.advanceTimersByTimeAsync(4 * 60_000)
    f.registered.get('mousemove')?.(new Event('mousemove'))
    await vi.advanceTimersByTimeAsync(4 * 60_000)
    expect(f.logout).not.toHaveBeenCalled()

    await vi.advanceTimersByTimeAsync(60_000)
    await flushMicrotasks()
    expect(f.logout).toHaveBeenCalledTimes(1)
  })

  it('the ABSOLUTE cap is NOT reset by activity — it fires regardless', async () => {
    const f = makeFixture()
    // Idle 10 min, absolute 30 min. Keep bumping activity every 9 min so
    // the idle timer never elapses; the absolute cap must still fire.
    install(f, 10, 30)
    f.mountCb?.()

    for (let elapsed = 0; elapsed < 30 * 60_000; elapsed += 9 * 60_000) {
      await vi.advanceTimersByTimeAsync(9 * 60_000)
      f.registered.get('keydown')?.(new Event('keydown'))
    }
    await flushMicrotasks()

    // ~31.5 min of constant activity — idle never elapsed, but the 30-min
    // absolute cap signed the session out anyway.
    expect(f.logout).toHaveBeenCalledTimes(1)
  })

  it('fires only once even when both caps would elapse', async () => {
    const f = makeFixture()
    install(f, 5, 5)
    f.mountCb?.()

    await vi.advanceTimersByTimeAsync(10 * 60_000)
    await flushMicrotasks()

    expect(f.logout).toHaveBeenCalledTimes(1)
    expect(f.push).toHaveBeenCalledTimes(1)
  })

  it('still redirects when logout() rejects (defensive)', async () => {
    const f = makeFixture()
    f.logout.mockRejectedValue(new Error('boom'))
    install(f, 1, 480)
    f.mountCb?.()

    await vi.advanceTimersByTimeAsync(60_000)
    await flushMicrotasks()

    expect(f.push).toHaveBeenCalledWith({
      name: 'auth.sign-in',
      query: { reason: 'session_expired' },
    })
  })

  it('detaches every listener and clears timers on unmount', async () => {
    const f = makeFixture()
    install(f, 5, 480)
    f.mountCb?.()
    f.unmountCb?.()

    expect(f.removeEventListener).toHaveBeenCalledTimes(ACTIVITY_EVENTS.length)

    await vi.advanceTimersByTimeAsync(20 * 60_000)
    await flushMicrotasks()
    expect(f.logout).not.toHaveBeenCalled()
  })
})
