import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { mountAuthPage } from '../../tests/unit/helpers/mountAuthPage'

vi.mock('../modules/onboarding/api/onboarding.api', () => ({
  onboardingApi: {
    bootstrap: vi.fn(),
    getContractTerms: vi.fn(),
  },
}))

import { onboardingApi } from '../modules/onboarding/api/onboarding.api'
import MasterAgreementTerms from './MasterAgreementTerms.vue'

let teardown: (() => void) | null = null

beforeEach(() => {
  vi.clearAllMocks()
  vi.mocked(onboardingApi.bootstrap).mockResolvedValue({
    data: { id: '01', type: 'creators', attributes: {} as never, wizard: {} as never },
  } as never)
})

afterEach(() => {
  teardown?.()
  teardown = null
})

describe('MasterAgreementTerms', () => {
  it('renders the server-side terms HTML and version once loaded', async () => {
    vi.mocked(onboardingApi.getContractTerms).mockResolvedValue({
      data: { html: '<h1>Master Agreement</h1>', version: '1.1', locale: 'en' },
    })

    const { wrapper, unmount } = await mountAuthPage(MasterAgreementTerms, {
      props: { label: 'Test' },
      initialRoute: { path: '/onboarding/contract' },
    })
    teardown = unmount
    await flushPromises()

    const terms = wrapper.find('[data-testid="master-agreement-terms"]')
    expect(terms.exists()).toBe(true)
    expect(terms.html()).toContain('Master Agreement')
    expect(wrapper.find('[data-testid="master-agreement-version"]').text()).toContain('1.1')
  })

  it('is height-bounded with internal scroll (cheap DOM assertion — the class carrying the CSS)', async () => {
    vi.mocked(onboardingApi.getContractTerms).mockResolvedValue({
      data: { html: '<p>terms</p>', version: '1.0', locale: 'en' },
    })

    const { wrapper, unmount } = await mountAuthPage(MasterAgreementTerms, {
      props: { label: 'Test' },
      initialRoute: { path: '/onboarding/contract' },
    })
    teardown = unmount
    await flushPromises()

    // The scroll region carries `.master-agreement-terms__scroll`, whose CSS
    // rule (`max-height: 360px; overflow-y: auto`) is what keeps this box
    // from growing by the document's length — the same class both hosts
    // render, never a per-host copy.
    expect(wrapper.find('[data-testid="master-agreement-terms"]').classes()).toContain(
      'master-agreement-terms__scroll',
    )
  })

  it('emits reached-end=true on mount when the content does not overflow', async () => {
    vi.mocked(onboardingApi.getContractTerms).mockResolvedValue({
      data: { html: '<p>short terms</p>', version: '1.0', locale: 'en' },
    })

    const { wrapper, unmount } = await mountAuthPage(MasterAgreementTerms, {
      props: { label: 'Test' },
      initialRoute: { path: '/onboarding/contract' },
    })
    teardown = unmount
    await flushPromises()

    // jsdom defaults scrollHeight/clientHeight to 0 — no overflow — so the
    // gate opens on mount alone without any scroll event firing.
    expect(wrapper.emitted('reachedEnd')).toBeTruthy()
    expect(wrapper.emitted('reachedEnd')?.at(-1)).toEqual([true])
  })

  it('surfaces a retry CTA on load failure', async () => {
    vi.mocked(onboardingApi.getContractTerms).mockRejectedValueOnce(new Error('boom'))

    const { wrapper, unmount } = await mountAuthPage(MasterAgreementTerms, {
      props: { label: 'Test' },
      initialRoute: { path: '/onboarding/contract' },
    })
    teardown = unmount
    await flushPromises()

    expect(wrapper.find('[data-testid="master-agreement-load-error"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="master-agreement-retry-load"]').exists()).toBe(true)
  })
})
