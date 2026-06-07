/**
 * UserSearchPage unit tests (Sprint 13, D-9).
 *
 * Focus: the impersonation-START surface wiring — debounced search,
 * the MANDATORY reason gate (confirm disabled below the min length),
 * and that a confirmed start posts the reason + opens the main SPA in a
 * new tab (the dual-session hand-off). The backend owns no-escalation +
 * TTL + the audit trail; this spec asserts the SPA sends the right call
 * and respects the reason friction.
 */

import { ApiError } from '@catalyst/api-client'
import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/modules/support/api/impersonation.api', async () => {
  const actual = await vi.importActual<typeof import('@/modules/support/api/impersonation.api')>(
    '@/modules/support/api/impersonation.api',
  )
  return {
    ...actual,
    impersonationApi: {
      searchUsers: vi.fn(),
      start: vi.fn(),
      end: vi.fn(),
    },
  }
})

import {
  impersonationApi,
  type ImpersonationCandidateResponse,
  type ImpersonationStartResult,
} from '@/modules/support/api/impersonation.api'

import { mountSupportPage } from '../../../../tests/unit/helpers/mountSupportPage'
import UserSearchPage from './UserSearchPage.vue'

function candidates(): ImpersonationCandidateResponse {
  return {
    data: [
      {
        id: '01HQUSER0000000000000000AB',
        type: 'users',
        attributes: {
          name: 'Dana Creator',
          email: 'dana@example.com',
          user_type: 'creator',
        },
      },
    ],
  }
}

function startResult(): ImpersonationStartResult {
  return {
    data: {
      id: '01HQSESSION00000000000000AB',
      type: 'impersonation_sessions',
      attributes: {
        handoff_token: 'tok_abc123',
        main_spa_url: 'https://app.catalyst.test',
        impersonated_user_ulid: '01HQUSER0000000000000000AB',
        impersonated_user_name: 'Dana Creator',
        expires_at: '2026-06-07T05:00:00Z',
      },
    },
  }
}

describe('UserSearchPage (Sprint 13, D-9)', () => {
  let teardown: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
    vi.useFakeTimers()
  })

  afterEach(() => {
    vi.useRealTimers()
    teardown?.()
    teardown = null
    document.body.innerHTML = ''
  })

  async function searchFor(query: string) {
    vi.mocked(impersonationApi.searchUsers).mockResolvedValue(candidates())
    const h = await mountSupportPage(UserSearchPage, {
      initialRoute: { name: 'app.support.search' },
    })
    teardown = h.unmount

    await h.wrapper.find('[data-testid="admin-user-search-input"] input').setValue(query)
    vi.advanceTimersByTime(300)
    await flushPromises()
    return h
  }

  it('debounces the search and renders candidate rows', async () => {
    const h = await searchFor('dana')

    expect(impersonationApi.searchUsers).toHaveBeenCalledWith('dana')
    expect(
      h.wrapper.find('[data-testid="admin-user-search-row-01HQUSER0000000000000000AB"]').exists(),
    ).toBe(true)
  })

  it('keeps the start confirm disabled until the reason meets the min length', async () => {
    const h = await searchFor('dana')

    await h.wrapper
      .find('[data-testid="admin-user-search-impersonate-01HQUSER0000000000000000AB"]')
      .trigger('click')
    await flushPromises()

    const confirm = () =>
      document.querySelector<HTMLButtonElement>('[data-testid="admin-impersonate-confirm"]')

    expect(confirm()?.disabled).toBe(true)

    const textarea = document.querySelector<HTMLTextAreaElement>(
      '[data-testid="admin-impersonate-reason"] textarea',
    )
    expect(textarea).not.toBeNull()
    textarea!.value = 'Investigating a checkout bug'
    textarea!.dispatchEvent(new Event('input'))
    await flushPromises()

    expect(confirm()?.disabled).toBe(false)
  })

  it('starts impersonation with the reason and opens the main SPA in a new tab', async () => {
    vi.mocked(impersonationApi.start).mockResolvedValue(startResult())
    const openSpy = vi.fn()
    vi.stubGlobal('open', openSpy)

    const h = await searchFor('dana')

    await h.wrapper
      .find('[data-testid="admin-user-search-impersonate-01HQUSER0000000000000000AB"]')
      .trigger('click')
    await flushPromises()

    const textarea = document.querySelector<HTMLTextAreaElement>(
      '[data-testid="admin-impersonate-reason"] textarea',
    )!
    textarea.value = 'Investigating a reported checkout bug'
    textarea.dispatchEvent(new Event('input'))
    await flushPromises()

    document.querySelector<HTMLButtonElement>('[data-testid="admin-impersonate-confirm"]')!.click()
    await flushPromises()

    expect(impersonationApi.start).toHaveBeenCalledWith(
      '01HQUSER0000000000000000AB',
      'Investigating a reported checkout bug',
    )
    // The hand-off opens the main SPA in a new tab with the one-time token
    // in the URL fragment — the admin's own tab/session is untouched.
    expect(openSpy).toHaveBeenCalledTimes(1)
    const call = openSpy.mock.calls[0] ?? []
    expect(call[0]).toContain('https://app.catalyst.test/impersonation/claim#token=tok_abc123')
    expect(call[1]).toBe('_blank')

    vi.unstubAllGlobals()
  })

  it('surfaces the API error code when start fails', async () => {
    vi.mocked(impersonationApi.start).mockRejectedValue(
      new ApiError({ status: 422, code: 'admin.impersonation.target_admin', message: 'no' }),
    )

    const h = await searchFor('dana')

    await h.wrapper
      .find('[data-testid="admin-user-search-impersonate-01HQUSER0000000000000000AB"]')
      .trigger('click')
    await flushPromises()

    const textarea = document.querySelector<HTMLTextAreaElement>(
      '[data-testid="admin-impersonate-reason"] textarea',
    )!
    textarea.value = 'Trying to impersonate this user'
    textarea.dispatchEvent(new Event('input'))
    await flushPromises()

    document.querySelector<HTMLButtonElement>('[data-testid="admin-impersonate-confirm"]')!.click()
    await flushPromises()

    expect(
      document.querySelector('[data-testid="admin-impersonate-error"]')?.textContent,
    ).toBeTruthy()
  })

  it('shows the empty state when no users match', async () => {
    vi.mocked(impersonationApi.searchUsers).mockResolvedValue({ data: [] })
    const h = await mountSupportPage(UserSearchPage, {
      initialRoute: { name: 'app.support.search' },
    })
    teardown = h.unmount

    await h.wrapper.find('[data-testid="admin-user-search-input"] input').setValue('nobody')
    vi.advanceTimersByTime(300)
    await flushPromises()

    expect(h.wrapper.find('[data-testid="admin-user-search-empty"]').exists()).toBe(true)
  })
})
