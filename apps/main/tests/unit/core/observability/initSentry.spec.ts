/**
 * AH-121 C2: the main SPA's init reads VITE_SENTRY_DSN_MAIN and the auth store.
 */
/* eslint-disable @typescript-eslint/no-explicit-any -- recorded Sentry payloads and mock call args are untyped JSON */

import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'

const { startSentry } = vi.hoisted(() => ({ startSentry: vi.fn(() => true) }))
vi.mock('@/core/observability/sentryClient', () => ({ startSentry }))

import { initSentry } from '@/core/observability/initSentry'
import { useAuthStore } from '@/modules/auth/stores/useAuthStore'

const ULID = '01HZX3K9Q5M7R2T8V4W6Y0A1B2'

beforeEach(() => {
  setActivePinia(createPinia())
  startSentry.mockClear()
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
