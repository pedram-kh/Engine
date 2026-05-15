import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { mountAuthPage } from '../../../../tests/unit/helpers/mountAuthPage'

vi.mock('../api/onboarding.api', () => ({
  onboardingApi: {
    bootstrap: vi.fn(),
    clickThroughAccept: vi.fn(),
    getContractTerms: vi.fn(),
  },
}))

import { onboardingApi } from '../api/onboarding.api'
import { useOnboardingStore } from '../stores/useOnboardingStore'
import ClickThroughAccept from './ClickThroughAccept.vue'

let teardown: (() => void) | null = null

beforeEach(() => {
  vi.clearAllMocks()
  vi.mocked(onboardingApi.bootstrap).mockResolvedValue({
    data: {
      id: '01',
      type: 'creators',
      attributes: {} as never,
      wizard: {} as never,
    },
  } as never)
})

afterEach(() => {
  teardown?.()
  teardown = null
})

describe('ClickThroughAccept', () => {
  it('renders the server-side terms HTML once loaded', async () => {
    vi.mocked(onboardingApi.getContractTerms).mockResolvedValue({
      data: {
        html: '<h1>Master Agreement</h1>',
        version: '1.0',
        locale: 'en',
      },
    })

    const { wrapper, unmount } = await mountAuthPage(ClickThroughAccept, {
      initialRoute: { path: '/onboarding/contract' },
    })
    teardown = unmount
    await flushPromises()

    const terms = wrapper.find('[data-testid="click-through-terms"]')
    expect(terms.exists()).toBe(true)
    expect(terms.html()).toContain('Master Agreement')
    expect(wrapper.find('[data-testid="click-through-version"]').text()).toContain('1.0')
  })

  it('disables the submit button until the checkbox is checked', async () => {
    vi.mocked(onboardingApi.getContractTerms).mockResolvedValue({
      data: { html: '<p>terms</p>', version: '1.0', locale: 'en' },
    })

    const { wrapper, unmount } = await mountAuthPage(ClickThroughAccept, {
      initialRoute: { path: '/onboarding/contract' },
    })
    teardown = unmount
    await flushPromises()

    const button = wrapper.find('[data-testid="click-through-submit"]')
    expect(button.attributes('disabled')).toBeDefined()
  })

  it('calls clickThroughAcceptContract on submit and emits accepted', async () => {
    vi.mocked(onboardingApi.getContractTerms).mockResolvedValue({
      data: { html: '<p>terms</p>', version: '1.0', locale: 'en' },
    })

    const { wrapper, unmount } = await mountAuthPage(ClickThroughAccept, {
      initialRoute: { path: '/onboarding/contract' },
    })
    teardown = unmount
    await flushPromises()

    const store = useOnboardingStore()
    const clickThroughSpy = vi
      .spyOn(store, 'clickThroughAcceptContract')
      .mockResolvedValue(undefined)

    // Programmatically check the box; v-checkbox emits update:modelValue
    // which the script-setup binding tracks via v-model.
    const checkbox = wrapper.find('input[type="checkbox"]')
    expect(checkbox.exists()).toBe(true)
    await checkbox.setValue(true)
    await flushPromises()

    await wrapper.find('[data-testid="click-through-submit"]').trigger('click')
    await flushPromises()

    expect(clickThroughSpy).toHaveBeenCalledTimes(1)
    expect(wrapper.emitted('accepted')).toBeTruthy()
  })

  it('surfaces a retry CTA on load failure', async () => {
    vi.mocked(onboardingApi.getContractTerms).mockRejectedValueOnce(new Error('boom'))

    const { wrapper, unmount } = await mountAuthPage(ClickThroughAccept, {
      initialRoute: { path: '/onboarding/contract' },
    })
    teardown = unmount
    await flushPromises()

    expect(wrapper.find('[data-testid="click-through-load-error"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="click-through-retry-load"]').exists()).toBe(true)
  })
})
