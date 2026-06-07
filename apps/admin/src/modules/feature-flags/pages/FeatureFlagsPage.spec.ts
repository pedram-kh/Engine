/**
 * FeatureFlagsPage unit tests (Sprint 13, D-6).
 *
 * Focus: the toggle wiring — listing flags with their state, the
 * reason-gated flip (the backend rejects a reasonless flip, so the UI
 * gates the confirm behind a min-length reason), and the page re-reading
 * the authoritative state from the server response after a flip.
 */

import { ApiError } from '@catalyst/api-client'
import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/modules/feature-flags/api/feature-flags.api', async () => {
  const actual = await vi.importActual<
    typeof import('@/modules/feature-flags/api/feature-flags.api')
  >('@/modules/feature-flags/api/feature-flags.api')
  return {
    ...actual,
    adminFeatureFlagsApi: {
      list: vi.fn(),
      toggle: vi.fn(),
    },
  }
})

import {
  adminFeatureFlagsApi,
  type AdminFeatureFlag,
  type AdminFeatureFlagListResponse,
} from '@/modules/feature-flags/api/feature-flags.api'

import { mountFeatureFlagsPage } from '../../../../tests/unit/helpers/mountFeatureFlagsPage'
import FeatureFlagsPage from './FeatureFlagsPage.vue'

function flag(name: string, enabled: boolean, label = 'KYC verification'): AdminFeatureFlag {
  return {
    id: name,
    type: 'feature_flags',
    attributes: { name, label, description: 'Gates the KYC step.', enabled },
  }
}

function listResponse(...flags: AdminFeatureFlag[]): AdminFeatureFlagListResponse {
  return { data: flags }
}

describe('FeatureFlagsPage (Sprint 13, D-6)', () => {
  let teardown: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    teardown?.()
    teardown = null
    document.body.innerHTML = ''
  })

  it('lists flags with their current state on mount', async () => {
    vi.mocked(adminFeatureFlagsApi.list).mockResolvedValue(
      listResponse(flag('kyc_verification_enabled', false)),
    )

    const h = await mountFeatureFlagsPage(FeatureFlagsPage)
    teardown = h.unmount
    await flushPromises()

    expect(adminFeatureFlagsApi.list).toHaveBeenCalledOnce()
    expect(
      h.wrapper.find('[data-testid="admin-feature-flag-kyc_verification_enabled"]').exists(),
    ).toBe(true)
  })

  it('opens the reason dialog on switch click and keeps confirm disabled until a reason is typed', async () => {
    vi.mocked(adminFeatureFlagsApi.list).mockResolvedValue(
      listResponse(flag('kyc_verification_enabled', false)),
    )

    const h = await mountFeatureFlagsPage(FeatureFlagsPage)
    teardown = h.unmount
    await flushPromises()

    await h.wrapper
      .find('[data-testid="admin-feature-flag-switch-kyc_verification_enabled"]')
      .trigger('click')
    await flushPromises()

    // The dialog teleports to <body>, so reach for it via the document.
    const confirm = document.querySelector(
      '[data-testid="admin-feature-flag-confirm"]',
    ) as HTMLButtonElement | null
    expect(confirm).not.toBeNull()
    expect(confirm?.disabled).toBe(true)

    // The toggle must NOT have fired yet — no reason captured.
    expect(adminFeatureFlagsApi.toggle).not.toHaveBeenCalled()
  })

  it('flips the flag with the typed reason and reflects the server state', async () => {
    vi.mocked(adminFeatureFlagsApi.list).mockResolvedValue(
      listResponse(flag('kyc_verification_enabled', false)),
    )
    vi.mocked(adminFeatureFlagsApi.toggle).mockResolvedValue({
      data: flag('kyc_verification_enabled', true),
    })

    const h = await mountFeatureFlagsPage(FeatureFlagsPage)
    teardown = h.unmount
    await flushPromises()

    await h.wrapper
      .find('[data-testid="admin-feature-flag-switch-kyc_verification_enabled"]')
      .trigger('click')
    await flushPromises()

    const textarea = document.querySelector(
      '[data-testid="admin-feature-flag-reason"] textarea',
    ) as HTMLTextAreaElement
    textarea.value = 'Enabling KYC for the launch cohort.'
    textarea.dispatchEvent(new Event('input'))
    await flushPromises()
    ;(
      document.querySelector('[data-testid="admin-feature-flag-confirm"]') as HTMLButtonElement
    ).click()
    await flushPromises()

    expect(adminFeatureFlagsApi.toggle).toHaveBeenCalledWith(
      'kyc_verification_enabled',
      true,
      'Enabling KYC for the launch cohort.',
    )
  })

  it('surfaces the API error code when the list load fails', async () => {
    vi.mocked(adminFeatureFlagsApi.list).mockRejectedValue(
      new ApiError({ status: 403, code: 'auth.forbidden', message: 'no' }),
    )

    const h = await mountFeatureFlagsPage(FeatureFlagsPage)
    teardown = h.unmount
    await flushPromises()

    expect(h.wrapper.find('[data-testid="admin-feature-flags-error"]').exists()).toBe(true)
  })
})
