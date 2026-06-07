/**
 * useImpersonationStore unit tests (Sprint 13, D-10).
 *
 * Focus: the display-cache contract — hydrate() reflects the server's
 * status verbatim, swallows the anonymous 401, and end() always clears
 * locally even when the server already tore the session down (401).
 */

import { ApiError } from '@catalyst/api-client'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../api/impersonation.api', async () => {
  const actual = await vi.importActual<typeof import('../api/impersonation.api')>(
    '../api/impersonation.api',
  )
  return {
    ...actual,
    impersonationApi: {
      claim: vi.fn(),
      status: vi.fn(),
      end: vi.fn(),
    },
  }
})

import { impersonationApi } from '../api/impersonation.api'
import { useImpersonationStore } from './useImpersonationStore'

describe('useImpersonationStore (Sprint 13, D-10)', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('hydrate() marks active with the server expiry', async () => {
    vi.mocked(impersonationApi.status).mockResolvedValue({
      data: { active: true, expires_at: '2026-06-07T05:00:00Z' },
    })
    const store = useImpersonationStore()

    await store.hydrate()

    expect(store.active).toBe(true)
    expect(store.expiresAt).toBe('2026-06-07T05:00:00Z')
    expect(store.expiresAtMs).toBe(new Date('2026-06-07T05:00:00Z').getTime())
  })

  it('hydrate() clears when the server reports inactive', async () => {
    vi.mocked(impersonationApi.status).mockResolvedValue({ data: { active: false } })
    const store = useImpersonationStore()
    store.setActive('2026-06-07T05:00:00Z')

    await store.hydrate()

    expect(store.active).toBe(false)
    expect(store.expiresAt).toBeNull()
  })

  it('hydrate() swallows the anonymous-session 401', async () => {
    vi.mocked(impersonationApi.status).mockRejectedValue(
      new ApiError({ status: 401, code: 'auth.unauthenticated', message: 'no' }),
    )
    const store = useImpersonationStore()

    await expect(store.hydrate()).resolves.toBeUndefined()
    expect(store.active).toBe(false)
  })

  it('hydrate() is best-effort: a non-401 error leaves the banner off, never throws', async () => {
    vi.mocked(impersonationApi.status).mockRejectedValue(
      new ApiError({ status: 500, code: 'server.error', message: 'boom' }),
    )
    const store = useImpersonationStore()

    await expect(store.hydrate()).resolves.toBeUndefined()
    expect(store.active).toBe(false)
  })

  it('end() calls the API and clears local state', async () => {
    vi.mocked(impersonationApi.end).mockResolvedValue({ data: { ended: true } })
    const store = useImpersonationStore()
    store.setActive('2026-06-07T05:00:00Z')

    await store.end()

    expect(impersonationApi.end).toHaveBeenCalledOnce()
    expect(store.active).toBe(false)
    expect(store.expiresAt).toBeNull()
  })

  it('end() treats a 401 (already torn down server-side) as success', async () => {
    vi.mocked(impersonationApi.end).mockRejectedValue(
      new ApiError({ status: 401, code: 'auth.unauthenticated', message: 'no' }),
    )
    const store = useImpersonationStore()
    store.setActive('2026-06-07T05:00:00Z')

    await expect(store.end()).resolves.toBeUndefined()
    expect(store.active).toBe(false)
  })
})
