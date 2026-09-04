import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { mountAuthPage } from '../../../../tests/unit/helpers/mountAuthPage'

vi.mock('../../onboarding/api/onboarding.api', () => ({
  onboardingApi: {
    bootstrap: vi.fn(),
    getContractTerms: vi.fn(),
  },
}))

import { onboardingApi } from '../../onboarding/api/onboarding.api'
import CreatorMasterAgreementSection from './CreatorMasterAgreementSection.vue'

let teardown: (() => void) | null = null

beforeEach(() => {
  vi.clearAllMocks()
  vi.mocked(onboardingApi.bootstrap).mockResolvedValue({
    data: { id: '01', type: 'creators', attributes: {} as never, wizard: {} as never },
  } as never)
  vi.mocked(onboardingApi.getContractTerms).mockResolvedValue({
    data: { html: '<h1>Master Agreement</h1><p>Terms body.</p>', version: '1.1', locale: 'en' },
  })
})

afterEach(() => {
  teardown?.()
  teardown = null
})

describe('CreatorMasterAgreementSection', () => {
  it('renders-with-acceptance: header + date, collapsed by default, expand reveals the text', async () => {
    const { wrapper, unmount } = await mountAuthPage(CreatorMasterAgreementSection, {
      props: {
        hasSignedMasterContract: false,
        clickThroughAcceptedAt: '2026-06-01T10:00:00+00:00',
      },
      initialRoute: { path: '/creator/profile' },
    })
    teardown = unmount
    await flushPromises()

    expect(wrapper.find('[data-testid="creator-profile-master-agreement"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="master-agreement-accepted-header"]').text()).toContain(
      "You've accepted the master agreement",
    )
    expect(wrapper.find('[data-testid="master-agreement-accepted-date"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="master-agreement-accepted-date"]').text()).toContain(
      'Accepted on',
    )

    // Collapsed by default — the terms fetch has not fired, the panel isn't
    // in the DOM.
    expect(wrapper.find('[data-testid="master-agreement-panel"]').exists()).toBe(false)
    expect(onboardingApi.getContractTerms).not.toHaveBeenCalled()

    await wrapper.find('[data-testid="master-agreement-toggle"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="master-agreement-panel"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="master-agreement-terms"]').html()).toContain('Terms body.')
    expect(onboardingApi.getContractTerms).toHaveBeenCalledTimes(1)
  })

  it('renders-with-acceptance: vendor-signed with no click-through timestamp shows the header with no date line', async () => {
    const { wrapper, unmount } = await mountAuthPage(CreatorMasterAgreementSection, {
      props: { hasSignedMasterContract: true, clickThroughAcceptedAt: null },
      initialRoute: { path: '/creator/profile' },
    })
    teardown = unmount
    await flushPromises()

    expect(wrapper.find('[data-testid="creator-profile-master-agreement"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="master-agreement-accepted-header"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="master-agreement-accepted-date"]').exists()).toBe(false)
  })

  it('hidden-without-acceptance: no section at all when neither acceptance flag is set', async () => {
    const { wrapper, unmount } = await mountAuthPage(CreatorMasterAgreementSection, {
      props: { hasSignedMasterContract: false, clickThroughAcceptedAt: null },
      initialRoute: { path: '/creator/profile' },
    })
    teardown = unmount
    await flushPromises()

    expect(wrapper.find('[data-testid="creator-profile-master-agreement"]').exists()).toBe(false)
  })

  it('bounded-scroll presence: the expanded panel renders the height-bounded scroll region', async () => {
    const { wrapper, unmount } = await mountAuthPage(CreatorMasterAgreementSection, {
      props: {
        hasSignedMasterContract: false,
        clickThroughAcceptedAt: '2026-06-01T10:00:00+00:00',
      },
      initialRoute: { path: '/creator/profile' },
    })
    teardown = unmount
    await flushPromises()

    await wrapper.find('[data-testid="master-agreement-toggle"]').trigger('click')
    await flushPromises()

    // Cheap DOM assertion: the same bounded-scroll class the wizard's own
    // click-through region renders (`max-height` + `overflow-y: auto` in
    // MasterAgreementTerms.vue) — proves the page cannot grow by the
    // document's length without asserting computed CSS.
    expect(wrapper.find('[data-testid="master-agreement-terms"]').classes()).toContain(
      'master-agreement-terms__scroll',
    )
  })
})
