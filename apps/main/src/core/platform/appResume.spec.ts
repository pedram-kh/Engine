/**
 * AH-100d (item 8) — the app-resume listener, both polarities:
 *   resume (native)  → the refresh runs, throttled, errors swallowed;
 *   everything else  → it does not (web build: nothing is even subscribed).
 * Plus `refreshSessionState()`, the composition it runs: auth `/me` first,
 * the onboarding payload second, nothing when signed out, sign-in redirect on
 * a lapsed session.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

import { RESUME_MIN_INTERVAL_MS, refreshSessionState, startAppResumeRefresh } from './appResume'
import type { ResumeSubscribe } from './appResume'

/** A controllable lifecycle source. */
function fakeSource() {
  let onActive: (() => void) | null = null
  const unsubscribe = vi.fn()
  const subscribe = vi.fn<ResumeSubscribe>(async (cb) => {
    onActive = cb
    return unsubscribe
  })
  return {
    subscribe,
    unsubscribe,
    /** The app returns to the foreground. */
    resume: () => onActive?.(),
  }
}

async function flush() {
  await Promise.resolve()
  await Promise.resolve()
}

describe('startAppResumeRefresh()', () => {
  it('runs the refresh when the app becomes active (native)', async () => {
    const source = fakeSource()
    const refresh = vi.fn(async () => undefined)
    startAppResumeRefresh({
      refresh,
      subscribe: source.subscribe,
      isNative: () => true,
      now: () => 0,
    })
    await flush()

    source.resume()

    expect(refresh).toHaveBeenCalledTimes(1)
  })

  it('on the web it subscribes to nothing and returns a harmless stop', async () => {
    const source = fakeSource()
    const refresh = vi.fn(async () => undefined)

    const stop = startAppResumeRefresh({
      refresh,
      subscribe: source.subscribe,
      isNative: () => false,
    })
    await flush()
    source.resume()

    expect(source.subscribe).not.toHaveBeenCalled()
    expect(refresh).not.toHaveBeenCalled()
    expect(stop).not.toThrow()
  })

  it('does not refresh just because it started (a cold start is the router guard’s job)', async () => {
    const source = fakeSource()
    const refresh = vi.fn(async () => undefined)

    startAppResumeRefresh({ refresh, subscribe: source.subscribe, isNative: () => true })
    await flush()

    expect(source.subscribe).toHaveBeenCalledTimes(1)
    expect(refresh).not.toHaveBeenCalled()
  })

  it('treats activations closer than the throttle as one resume, and the next real one as a new resume', async () => {
    const source = fakeSource()
    const refresh = vi.fn(async () => undefined)
    let clock = 1_000
    startAppResumeRefresh({
      refresh,
      subscribe: source.subscribe,
      isNative: () => true,
      now: () => clock,
    })
    await flush()

    source.resume()
    clock += 1_000
    source.resume()
    expect(refresh).toHaveBeenCalledTimes(1)

    clock += RESUME_MIN_INTERVAL_MS
    source.resume()
    expect(refresh).toHaveBeenCalledTimes(2)
  })

  it('swallows a failing refresh (a background refresh is never an error state)', async () => {
    const source = fakeSource()
    const refresh = vi.fn(async () => {
      throw new Error('offline')
    })
    startAppResumeRefresh({
      refresh,
      subscribe: source.subscribe,
      isNative: () => true,
      now: () => 0,
    })
    await flush()

    expect(() => source.resume()).not.toThrow()
    await flush()
    expect(refresh).toHaveBeenCalledTimes(1)
  })

  it('stop() unsubscribes and silences later resumes', async () => {
    const source = fakeSource()
    const refresh = vi.fn(async () => undefined)
    const stop = startAppResumeRefresh({
      refresh,
      subscribe: source.subscribe,
      isNative: () => true,
      now: () => 0,
    })
    await flush()

    stop()
    source.resume()

    expect(source.unsubscribe).toHaveBeenCalledTimes(1)
    expect(refresh).not.toHaveBeenCalled()
  })

  it('stop() before the subscription resolved still unsubscribes once it does', async () => {
    const source = fakeSource()
    const stop = startAppResumeRefresh({
      refresh: async () => undefined,
      subscribe: source.subscribe,
      isNative: () => true,
    })

    stop()
    await flush()

    expect(source.unsubscribe).toHaveBeenCalledTimes(1)
  })

  it('a subscription that fails to register is swallowed', async () => {
    const subscribe: ResumeSubscribe = async () => {
      throw new Error('plugin not implemented')
    }

    expect(() =>
      startAppResumeRefresh({ refresh: async () => undefined, subscribe, isNative: () => true }),
    ).not.toThrow()
    await flush()
  })
})

// ---------------------------------------------------------------------------
// refreshSessionState() — the composition
// ---------------------------------------------------------------------------

const auth = vi.hoisted(() => ({
  user: null as null | { attributes: { user_type: string } },
  sessionLapsed: false,
  refreshSession: vi.fn<() => Promise<void>>(),
}))
const onboarding = vi.hoisted(() => ({ refresh: vi.fn<() => Promise<void>>() }))

vi.mock('@/modules/auth/stores/useAuthStore', () => ({ useAuthStore: () => auth }))
vi.mock('@/modules/onboarding/stores/useOnboardingStore', () => ({
  useOnboardingStore: () => onboarding,
}))

const onLapsed = vi.fn()

describe('refreshSessionState()', () => {
  beforeEach(() => {
    auth.user = { attributes: { user_type: 'creator' } }
    auth.sessionLapsed = false
    auth.refreshSession.mockReset().mockResolvedValue(undefined)
    onboarding.refresh.mockReset().mockResolvedValue(undefined)
    onLapsed.mockReset()
  })

  it('re-reads /me, then the onboarding payload, for a signed-in creator', async () => {
    const order: string[] = []
    auth.refreshSession.mockImplementation(async () => {
      order.push('me')
    })
    onboarding.refresh.mockImplementation(async () => {
      order.push('onboarding')
    })

    await refreshSessionState(onLapsed)

    expect(order).toEqual(['me', 'onboarding'])
    expect(onLapsed).not.toHaveBeenCalled()
  })

  it('does nothing at all when signed out', async () => {
    auth.user = null

    await refreshSessionState(onLapsed)

    expect(auth.refreshSession).not.toHaveBeenCalled()
    expect(onboarding.refresh).not.toHaveBeenCalled()
    expect(onLapsed).not.toHaveBeenCalled()
  })

  it('does not fetch the onboarding payload for a non-creator', async () => {
    auth.user = { attributes: { user_type: 'agency_user' } }

    await refreshSessionState(onLapsed)

    expect(auth.refreshSession).toHaveBeenCalledTimes(1)
    expect(onboarding.refresh).not.toHaveBeenCalled()
  })

  it('a lapsed session (the 401 cleared the user) calls onSessionLapsed (the sign-in redirect) and skips onboarding', async () => {
    auth.refreshSession.mockImplementation(async () => {
      auth.user = null
      auth.sessionLapsed = true
    })

    await refreshSessionState(onLapsed)

    expect(onboarding.refresh).not.toHaveBeenCalled()
    expect(onLapsed).toHaveBeenCalledTimes(1)
  })

  it('a deliberate sign-out that lands mid-refresh is not "session expired"', async () => {
    auth.refreshSession.mockImplementation(async () => {
      auth.user = null
      auth.sessionLapsed = false
    })

    await refreshSessionState(onLapsed)

    expect(onLapsed).not.toHaveBeenCalled()
    expect(onboarding.refresh).not.toHaveBeenCalled()
  })
})
