/**
 * AH-121 C2: client wiring. Empty DSN sends nothing; hooks capture; the user is
 * set on sign-in and cleared on sign-out. Mirrored in both SPAs.
 */

import * as Sentry from '@sentry/vue'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createApp, nextTick } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'

vi.mock('@sentry/vue', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@sentry/vue')>()
  return { ...actual, init: vi.fn(actual.init) }
})

import { startSentry } from '@/core/observability/sentryClient'

import { FAKE_DSN, startRecordingSentry, ULID, type Harness } from '../../helpers/sentryRecorder'

let harness: Harness | null = null
afterEach(async () => {
  await harness?.close()
  harness = null
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
})

describe('empty DSN sends nothing', () => {
  for (const dsn of [undefined, '', '   ']) {
    it(`dsn ${JSON.stringify(dsn)}: no init, no client, no fetch, no XHR, no sendBeacon`, async () => {
      const init = vi.mocked(Sentry.init)
      init.mockClear()
      const fetchSpy = vi.fn()
      const beacon = vi.fn()
      const xhrOpen = vi.spyOn(XMLHttpRequest.prototype, 'open')
      vi.stubGlobal('fetch', fetchSpy)
      Object.defineProperty(navigator, 'sendBeacon', { value: beacon, configurable: true })

      const app = createApp({ render: () => null })
      const router = createRouter({
        history: createMemoryHistory(),
        routes: [{ path: '/', component: {} }],
      })
      const started = startSentry({
        app,
        router,
        dsn,
        release: 'r',
        environment: 'test',
        user: () => ({ id: ULID, type: 'creator' }),
      })

      // The browser would call the global handler; none is installed here (no client), so this is a no-op.
      ;(globalThis as unknown as { onerror?: (...a: unknown[]) => void }).onerror?.(
        'boom',
        'x.js',
        1,
        1,
        new Error('boom'),
      )
      await nextTick()

      expect(started).toBe(false)
      expect(init).not.toHaveBeenCalled()
      expect(Sentry.getClient()).toBeUndefined()
      expect(fetchSpy).not.toHaveBeenCalled()
      expect(xhrOpen).not.toHaveBeenCalled()
      expect(beacon).not.toHaveBeenCalled()
    })
  }
})

describe('hooks', () => {
  it('captures a Vue errorHandler error', async () => {
    harness = startRecordingSentry()
    const app = harness.app
    const host = document.createElement('div')
    app.mount(host)
    // Vue calls this handler for a component error; Sentry's wrapper captures and then rethrows
    // (no previous handler), so the call is wrapped.
    try {
      app.config.errorHandler?.(new Error('vue render boom'), null, 'render')
    } catch {
      // expected rethrow
    }
    await harness.settled()
    expect(JSON.stringify(harness.recorded.events)).toContain('vue render boom')
    app.unmount()
  })

  it('router.onError captures, and tags a stale-chunk failure with chunk_load (rate limited)', async () => {
    harness = startRecordingSentry()
    const router = harness.router
    let n = 0
    router.addRoute({
      path: '/lazy',
      // A different message each time: the SDK's dedupe integration drops identical repeats.
      component: () =>
        Promise.reject(
          new TypeError(`Failed to fetch dynamically imported module: /assets/x${(n += 1)}.js`),
        ),
    })
    router.addRoute({
      path: '/other',
      component: () => Promise.reject(new Error('some other route failure')),
    })
    await router.push('/lazy').catch(() => undefined)
    await router.push('/other').catch(() => undefined)
    await harness.settled(2)
    const [chunk, other] = harness.recorded.events
    expect(chunk?.tags?.chunk_load).toBe('true')
    expect(other?.tags?.chunk_load).toBeUndefined()

    for (let i = 0; i < 6; i += 1) {
      await router.push('/lazy').catch(() => undefined)
      await router.push('/').catch(() => undefined)
    }
    await harness.settled(4)
    const chunkEvents = harness.recorded.events.filter((e) => e.tags?.chunk_load === 'true')
    expect(chunkEvents.length).toBe(3)
  })

  it('captures the window error event and an unhandled promise rejection', async () => {
    harness = startRecordingSentry()
    // jsdom's `window` is not Node's `globalThis`, which is where the SDK installs its handlers,
    // so the browser's "fire the event" step is done by calling the installed handlers directly.
    const g = globalThis as unknown as {
      onerror: (...args: unknown[]) => void
      onunhandledrejection: (event: unknown) => void
    }
    expect(typeof g.onerror).toBe('function')
    expect(typeof g.onunhandledrejection).toBe('function')
    g.onerror('window boom', 'https://app.example.test/a.js', 1, 1, new Error('window boom'))
    g.onunhandledrejection({ reason: new Error('rejected boom') })
    await harness.settled(2)
    const text = JSON.stringify(harness.recorded.events)
    expect(text).toContain('window boom')
    expect(text).toContain('rejected boom')
  })
})

describe('user', () => {
  it('sets the ulid and the user_type tag on sign-in and clears both on sign-out', async () => {
    harness = startRecordingSentry()
    harness.user.value = { id: ULID, type: 'agency_user' }
    await nextTick()
    Sentry.captureException(new Error('while signed in'))
    harness.user.value = { id: null, type: null }
    await nextTick()
    Sentry.captureException(new Error('after sign out'))
    await harness.settled(2)
    const [signedIn, signedOut] = harness.recorded.events
    expect(signedIn?.user).toEqual({ id: ULID })
    expect(signedIn?.tags?.user_type).toBe('agency')
    expect(signedOut?.user).toBeUndefined()
    expect(signedOut?.tags?.user_type).toBeUndefined()
  })

  it('maps platform_admin to admin, agency_user to agency, creator to creator, and sets NO tag for brand_user', async () => {
    harness = startRecordingSentry()
    for (const type of ['platform_admin', 'agency_user', 'creator', 'brand_user'] as const) {
      harness.user.value = { id: ULID, type }
      await nextTick()
      Sentry.captureException(new Error(type))
    }
    await harness.settled(4)
    expect(harness.recorded.events.map((e) => e.tags?.user_type)).toEqual([
      'admin',
      'agency',
      'creator',
      undefined,
    ])
    expect(harness.recorded.events[3]?.user).toEqual({ id: ULID })
  })
})

describe('options', () => {
  it('has no replay, tracing, feedback or default PII at runtime', async () => {
    harness = startRecordingSentry()
    const options = Sentry.getClient()?.getOptions()
    expect(options?.dsn).toBe(FAKE_DSN)
    expect(options?.sendDefaultPii).toBe(false)
    expect(options?.tracesSampleRate).toBeUndefined()
    expect(
      (options as Record<string, unknown> | undefined)?.replaysSessionSampleRate,
    ).toBeUndefined()
    const names = (Sentry.getClient()?.getOptions().integrations ?? []).map((i) => i.name)
    expect(names).not.toContain('Replay')
    expect(names).not.toContain('BrowserTracing')
    expect(names).not.toContain('Feedback')
    expect(names).toContain('Vue')
    expect(names).toContain('GlobalHandlers')
  })
})
