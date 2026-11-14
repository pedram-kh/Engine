/**
 * AH-121 C2: the main SPA's init reads VITE_SENTRY_DSN_MAIN and the auth store.
 */
/* eslint-disable @typescript-eslint/no-explicit-any -- recorded Sentry payloads and mock call args are untyped JSON */

import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'

const { startSentry, startNativeSentry, isNative } = vi.hoisted(() => ({
  startSentry: vi.fn(() => true),
  startNativeSentry: vi.fn(async () => true),
  isNative: vi.fn(() => false),
}))
vi.mock('@/core/observability/sentryClient', () => ({ startSentry }))
vi.mock('@/core/observability/sentryNative', () => ({ startNativeSentry }))
vi.mock('@/core/platform/isNativePlatform', () => ({ isNativePlatform: isNative }))

import { resetEarlyErrors } from '@/core/observability/earlyErrors'
import { initSentry } from '@/core/observability/initSentry'
import { useAuthStore } from '@/modules/auth/stores/useAuthStore'

const ULID = '01HZX3K9Q5M7R2T8V4W6Y0A1B2'

beforeEach(() => {
  setActivePinia(createPinia())
  startSentry.mockClear()
  startNativeSentry.mockClear()
  isNative.mockReturnValue(false)
  resetEarlyErrors()
  vi.unstubAllEnvs()
})

describe('initSentry (main)', () => {
  it('hands startSentry the main DSN variable and the build release', () => {
    vi.stubEnv('VITE_SENTRY_DSN_MAIN', 'https://k@o0.ingest.example.invalid/1')
    vi.stubEnv('VITE_SENTRY_DSN_ADMIN', 'https://other@o0.ingest.example.invalid/2')
    const app = createApp({ render: () => null })
    const router = createRouter({ history: createMemoryHistory(), routes: [] })
    expect(initSentry(app, router)).toBe(true)
    const options = (startSentry.mock.calls as unknown as Array<[Record<string, any>]>)[0]?.[0]
    expect(options?.dsn).toBe('https://k@o0.ingest.example.invalid/1')
    expect(options?.release).toBe(__SENTRY_RELEASE__)
    expect(options?.app).toBe(app)
    expect(options?.router).toBe(router)
  })

  it('the user getter reads the auth store: ulid and type when signed in, nulls when not', () => {
    initSentry(
      createApp({ render: () => null }),
      createRouter({ history: createMemoryHistory(), routes: [] }),
    )
    const options = (startSentry.mock.calls as unknown as Array<[Record<string, any>]>)[0]?.[0]
    expect(options?.user()).toEqual({ id: null, type: null })
    const auth = useAuthStore()
    auth.user = {
      id: ULID,
      type: 'users',
      attributes: { user_type: 'agency_user', email: 'a@b.co' },
    } as never
    expect(options?.user()).toEqual({ id: ULID, type: 'agency_user' })
    expect(Object.keys(options?.user())).toEqual(['id', 'type'])
    auth.clearUser()
    expect(options?.user()).toEqual({ id: null, type: null })
  })
})

describe('initSentry (main, native shell)', () => {
  const make = () => ({
    app: createApp({ render: () => null }),
    router: createRouter({ history: createMemoryHistory(), routes: [] }),
  })

  it('uses the APP DSN, never the MAIN one, and never the browser client', async () => {
    isNative.mockReturnValue(true)
    vi.stubEnv('VITE_SENTRY_DSN_MAIN', 'https://main@o0.ingest.example.invalid/1')
    vi.stubEnv('VITE_SENTRY_DSN_APP', 'https://app@o0.ingest.example.invalid/9')
    const { app, router } = make()
    expect(initSentry(app, router)).toBe(true)
    await vi.waitFor(() => expect(startNativeSentry).toHaveBeenCalledOnce())
    const options = (
      startNativeSentry.mock.calls as unknown as Array<[Record<string, any>]>
    )[0]?.[0]
    expect(options?.dsn).toBe('https://app@o0.ingest.example.invalid/9')
    expect(options?.app).toBe(app)
    expect(startSentry).not.toHaveBeenCalled()
  })

  it('with only the MAIN DSN set, the native shell sends nothing', () => {
    isNative.mockReturnValue(true)
    vi.stubEnv('VITE_SENTRY_DSN_MAIN', 'https://main@o0.ingest.example.invalid/1')
    vi.stubEnv('VITE_SENTRY_DSN_APP', '')
    expect(initSentry(...(Object.values(make()) as [never, never]))).toBe(false)
    expect(startNativeSentry).not.toHaveBeenCalled()
    expect(startSentry).not.toHaveBeenCalled()
  })

  it('on the web, the APP DSN is ignored', () => {
    vi.stubEnv('VITE_SENTRY_DSN_APP', 'https://app@o0.ingest.example.invalid/9')
    vi.stubEnv('VITE_SENTRY_DSN_MAIN', '')
    initSentry(...(Object.values(make()) as [never, never]))
    expect(startNativeSentry).not.toHaveBeenCalled()
    const options = (startSentry.mock.calls as unknown as Array<[Record<string, any>]>)[0]?.[0]
    expect(options?.dsn).toBe('')
  })

  it.each([
    ['native-production', 'production'],
    ['native-development', 'development'],
    ['whatever', 'development'],
  ])('maps Vite mode %s to the Sentry environment %s', async (mode, environment) => {
    isNative.mockReturnValue(true)
    vi.stubEnv('VITE_SENTRY_DSN_APP', 'https://app@o0.ingest.example.invalid/9')
    vi.stubEnv('MODE', mode)
    initSentry(...(Object.values(make()) as [never, never]))
    await vi.waitFor(() => expect(startNativeSentry).toHaveBeenCalledOnce())
    const options = (
      startNativeSentry.mock.calls as unknown as Array<[Record<string, any>]>
    )[0]?.[0]
    expect(options?.environment).toBe(environment)
  })
})
