/**
 * AH-121 C3: the native Sentry client, with the Capacitor and Vue SDKs mocked. Pins what
 * was proved on the simulator (sentry-plan.md D8): option set, integration allow-list,
 * release from the bundle, user applied only once the client exists, signed-out marker,
 * and early errors replayed after the user is applied.
 */
/* eslint-disable @typescript-eslint/no-explicit-any -- mock call args are untyped */

import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, ref } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'

const calls = vi.hoisted(() => ({ log: [] as string[] }))
const state = vi.hoisted(() => ({ clientAfterPolls: 0, polls: 0 }))

const capacitor = vi.hoisted(() => ({
  init: vi.fn((..._args: any[]) => {
    calls.log.push('init')
  }),
  getClient: vi.fn(() => {
    state.polls += 1
    return state.polls > state.clientAfterPolls ? ({} as object) : undefined
  }),
  setUser: vi.fn((user: unknown) => calls.log.push(`setUser:${JSON.stringify(user)}`)),
  setTag: vi.fn((key: string, value: unknown) => calls.log.push(`setTag:${key}=${String(value)}`)),
  captureException: vi.fn((error: unknown) =>
    calls.log.push(`capture:${(error as Error).message}`),
  ),
  nativeCrash: vi.fn(),
  flush: vi.fn(async () => true),
}))
vi.mock('@sentry/capacitor', () => capacitor)

vi.mock('@sentry/vue', () => ({
  init: vi.fn(),
  setUser: (user: unknown) => calls.log.push(`setUser:${JSON.stringify(user)}`),
  setTag: (key: string, value: unknown) => calls.log.push(`setTag:${key}=${String(value)}`),
  breadcrumbsIntegration: (options: unknown) => ({ name: 'Breadcrumbs', options }),
  globalHandlersIntegration: (options: unknown) => ({ name: 'GlobalHandlers', options }),
  vueIntegration: (options: unknown) => ({ name: 'Vue', options }),
}))

const getInfo = vi.hoisted(() => vi.fn())
vi.mock('@capacitor/app', () => ({ App: { getInfo } }))

import { resetEarlyErrors, startEarlyErrorBuffer } from '@/core/observability/earlyErrors'
import {
  NATIVE_ALLOWED_INTEGRATIONS,
  SIGNED_OUT_USER_ID,
  applyNativeUser,
  nativeReleaseFrom,
  startNativeSentry,
} from '@/core/observability/sentryNative'
import type { SentryUserState } from '@/core/observability/sentryClient'

const DSN = 'https://publickey@o0.ingest.example.invalid/1'
const ULID = '01HZX3K9Q5M7R2T8V4W6Y0A1B2'

function harness(userState: SentryUserState = { id: null, type: null }) {
  const app = createApp({ render: () => null })
  const router = createRouter({ history: createMemoryHistory(), routes: [] })
  const user = ref<SentryUserState>(userState)
  return {
    app,
    router,
    user,
    options: { app, router, dsn: DSN, environment: 'development', user: () => user.value },
  }
}

const initOptions = (): Record<string, any> => capacitor.init.mock.calls[0]?.[0] as never

beforeEach(() => {
  setActivePinia(createPinia())
  calls.log.length = 0
  state.polls = 0
  state.clientAfterPolls = 0
  capacitor.init.mockClear()
  capacitor.getClient.mockClear()
  capacitor.captureException.mockClear()
  getInfo.mockReset()
  getInfo.mockResolvedValue({ id: 'com.catalystgrowth.platform', version: '1.0.0', build: '6' })
  resetEarlyErrors()
})
afterEach(() => {
  resetEarlyErrors()
  vi.unstubAllEnvs()
})

describe('startNativeSentry', () => {
  it('an empty or missing DSN never calls init', async () => {
    const h = harness()
    expect(await startNativeSentry({ ...h.options, dsn: '' })).toBe(false)
    expect(await startNativeSentry({ ...h.options, dsn: '   ' })).toBe(false)
    expect(await startNativeSentry({ ...h.options, dsn: undefined })).toBe(false)
    expect(capacitor.init).not.toHaveBeenCalled()
  })

  it('passes the option set that keeps the native SDK to crashes only', async () => {
    await startNativeSentry(harness().options)
    const o = initOptions()
    expect(o.dsn).toBe(DSN)
    expect(o.environment).toBe('development')
    expect(o.sendDefaultPii).toBe(false)
    expect(o.enableAutoSessionTracking).toBe(false)
    expect(o.enableWatchdogTerminationTracking).toBe(false)
    expect(o.enableAppHangTracking).toBe(false)
    expect(o.enableCaptureFailedRequests).toBe(false)
    expect(o.tracesSampleRate).toBeUndefined()
    expect(o.replaysSessionSampleRate).toBeUndefined()
    expect(typeof o.beforeSend).toBe('function')
    expect(typeof o.beforeBreadcrumb).toBe('function')
  })

  it('release and dist come from the installed bundle, not the web build constant', async () => {
    await startNativeSentry(harness().options)
    expect(initOptions().release).toBe('com.catalystgrowth.platform@1.0.0+6')
    expect(initOptions().dist).toBe('6')
    expect(nativeReleaseFrom({ id: 'a.b', version: '2.0.1', build: '17' })).toEqual({
      release: 'a.b@2.0.1+17',
      dist: '17',
    })
  })

  it('without bundle info it passes no release at all rather than a wrong one', async () => {
    getInfo.mockRejectedValue(new Error('no plugin'))
    await startNativeSentry(harness().options)
    expect('release' in initOptions()).toBe(false)
    expect('dist' in initOptions()).toBe(false)
  })

  it('filters the SDK defaults through the web list plus the plugin list, nothing else', async () => {
    await startNativeSentry(harness().options)
    const defaults = [
      'InboundFilters',
      'FunctionToString',
      'Breadcrumbs',
      'GlobalHandlers',
      'LinkedErrors',
      'Dedupe',
      'RewriteFrames',
      'Release',
      'EventOrigin',
      'SdkInfo',
      'DeviceContext',
      // not allowed, must be dropped:
      'LogEnricher',
      'BrowserSession',
      'CultureContext',
      'Replay',
      'CapacitorHttp',
      'Spotlight',
      'SomethingNewInAFutureVersion',
    ].map((name) => ({ name }))
    const names = (initOptions().integrations(defaults) as Array<{ name: string }>).map(
      (i) => i.name,
    )
    expect(names).toEqual([
      'InboundFilters',
      'FunctionToString',
      'LinkedErrors',
      'Dedupe',
      'RewriteFrames',
      'Release',
      'EventOrigin',
      'SdkInfo',
      'DeviceContext',
      'Breadcrumbs',
      'GlobalHandlers',
      'Vue',
    ])
  })

  it('pins the plugin allow-list literally', () => {
    expect([...NATIVE_ALLOWED_INTEGRATIONS]).toEqual([
      'RewriteFrames',
      'Release',
      'EventOrigin',
      'SdkInfo',
      'DeviceContext',
    ])
  })

  it('console and DOM breadcrumbs are off, Vue props are not attached', async () => {
    const h = harness()
    await startNativeSentry(h.options)
    const built = initOptions().integrations([]) as Array<{ name: string; options: any }>
    const crumbs = built.find((i) => i.name === 'Breadcrumbs')?.options
    expect(crumbs).toMatchObject({ console: false, dom: false })
    const vue = built.find((i) => i.name === 'Vue')?.options
    expect(vue.attachProps).toBe(false)
    expect(vue.app).toBe(h.app)
  })

  it('applies the user only after the JS client exists, never before init resolved', async () => {
    state.clientAfterPolls = 3
    const h = harness({ id: ULID, type: 'creator' })
    await startNativeSentry(h.options)
    const initAt = calls.log.indexOf('init')
    const userAt = calls.log.findIndex((entry) => entry.startsWith('setUser:'))
    expect(initAt).toBe(0)
    expect(userAt).toBeGreaterThan(initAt)
    expect(capacitor.getClient.mock.calls.length).toBeGreaterThanOrEqual(4)
    expect(calls.log).toContain(`setUser:${JSON.stringify({ id: ULID })}`)
    expect(calls.log).toContain('setTag:user_type=creator')
  })

  it('returns false and stops buffering when the client never appears', async () => {
    vi.useFakeTimers()
    state.clientAfterPolls = Number.POSITIVE_INFINITY
    const h = harness()
    startEarlyErrorBuffer(h.app)
    const started = startNativeSentry(h.options)
    await vi.advanceTimersByTimeAsync(11_000)
    expect(await started).toBe(false)
    expect(calls.log.some((entry) => entry.startsWith('setUser:'))).toBe(false)
    vi.useRealTimers()
  })

  it('replays an error thrown at boot, after the client exists and after the user is applied', async () => {
    state.clientAfterPolls = 4
    const h = harness({ id: ULID, type: 'creator' })
    startEarlyErrorBuffer(h.app)
    const started = startNativeSentry(h.options)
    // The app throws synchronously while the native SDK is still starting.
    window.dispatchEvent(
      new ErrorEvent('error', { error: new Error('thrown at boot'), message: 'thrown at boot' }),
    )
    expect(capacitor.captureException).not.toHaveBeenCalled()
    await started
    expect(capacitor.captureException).toHaveBeenCalledTimes(1)
    const capturedAt = calls.log.indexOf('capture:thrown at boot')
    expect(capturedAt).toBeGreaterThan(calls.log.indexOf(`setUser:${JSON.stringify({ id: ULID })}`))
  })

  it('reacts to sign-in and sign-out after start', async () => {
    const h = harness()
    await startNativeSentry(h.options)
    calls.log.length = 0
    h.user.value = { id: ULID, type: 'agency_user' }
    await Promise.resolve()
    await Promise.resolve()
    expect(calls.log).toContain(`setUser:${JSON.stringify({ id: ULID })}`)
    expect(calls.log).toContain('setTag:user_type=agency')
    h.user.value = { id: null, type: null }
    await Promise.resolve()
    await Promise.resolve()
    expect(calls.log).toContain(`setUser:${JSON.stringify({ id: SIGNED_OUT_USER_ID })}`)
  })
})

describe('applyNativeUser', () => {
  it('signed out is the fixed string, never a device value, and clears the type tag', () => {
    applyNativeUser({ id: null, type: null })
    expect(calls.log).toEqual(['setUser:{"id":"signed-out"}', 'setTag:user_type=undefined'])
    expect(SIGNED_OUT_USER_ID).toBe('signed-out')
  })

  it('signed in is { id } only', () => {
    applyNativeUser({ id: ULID, type: 'creator' })
    expect(calls.log[0]).toBe(`setUser:${JSON.stringify({ id: ULID })}`)
  })
})
