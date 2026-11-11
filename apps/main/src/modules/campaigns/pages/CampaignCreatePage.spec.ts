/**
 * Vitest coverage for the campaign create page's AH-069 posting default (D1).
 *
 * This spec exists for exactly one reason: it is the ONLY place the Q1 product
 * default is expressed. The DB column defaults to `true` (the safety floor, so
 * that a caller which omits the field gets the lifecycle that has always
 * shipped); the create FORM defaults to `false` (hand off at approval, the
 * product decision) and always sends the field explicitly. Nothing on the
 * server can assert the second half — if this spec goes, the product default
 * can be deleted silently and every new campaign quietly expects posting again.
 */

import { ApiError } from '@catalyst/api-client'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enApp from '@/core/i18n/locales/en/app.json'

vi.mock('../api/campaigns.api', () => ({
  campaignsApi: { create: vi.fn() },
}))

vi.mock('@/modules/brands/api/brands.api', () => ({
  brandsApi: { list: vi.fn(), listOptions: vi.fn() },
}))

vi.mock('@/core/stores/useAgencyStore', () => ({
  useAgencyStore: () => ({ currentAgencyId: 'agency-ulid' }),
}))

// AH-118: the page reads `/me` features through the auth store; the real store
// pulls in the agency store this spec replaces, so it is replaced here too.
const features: { campaign_region_restriction?: boolean; campaign_brand_exclusivity?: boolean } = {}
vi.mock('@/modules/auth/stores/useAuthStore', () => ({
  useAuthStore: () => ({ user: { attributes: { features } } }),
}))

const push = vi.fn()
vi.mock('vue-router', () => ({
  useRouter: () => ({ push }),
}))

import { brandsApi } from '@/modules/brands/api/brands.api'
import { campaignsApi } from '../api/campaigns.api'
import CampaignForm from '../components/CampaignForm.vue'
import CampaignCreatePage from './CampaignCreatePage.vue'

async function mountPage(
  flags: { campaign_region_restriction?: boolean; campaign_brand_exclusivity?: boolean } = {},
) {
  delete features.campaign_region_restriction
  delete features.campaign_brand_exclusivity
  Object.assign(features, flags)

  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    availableLocales: ['en'],
    messages: { en: enApp } as never,
  }) as unknown as ReturnType<typeof createI18n>

  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })

  const wrapper = mount(CampaignCreatePage, {
    global: { plugins: [i18n, vuetify], stubs: { RouterLink: true } },
    attachTo: document.body,
  })

  await flushPromises()

  return wrapper
}

describe('CampaignCreatePage — the AH-069 posting default (D1, Q1)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(brandsApi.listOptions).mockResolvedValue({ data: [] })
  })

  it('pre-sets the toggle to OFF — a form-created campaign hands off at approval', async () => {
    const wrapper = await mountPage()

    const toggle = wrapper.find('[data-test="campaign-creator-posts-content"] input')
    expect(toggle.exists()).toBe(true)
    expect((toggle.element as HTMLInputElement).checked).toBe(false)
  })

  it('sends creator_posts_content explicitly, so the column default never decides', async () => {
    // The whole two-layer design rests on this: the form NAMES the value, so the
    // opposite DB default is free to be the safety net for other callers.
    vi.mocked(campaignsApi.create).mockResolvedValue({ data: { id: 'new-ulid' } } as never)

    const wrapper = await mountPage()

    await wrapper.find('[data-test="campaign-name"] input').setValue('Autumn UGC')
    await wrapper.find('[data-test="campaign-form"]').trigger('submit')
    await flushPromises()

    expect(campaignsApi.create).toHaveBeenCalledWith(
      'agency-ulid',
      expect.objectContaining({ creator_posts_content: false }),
    )
  })

  it('sends true when the agency turns posting back on before saving', async () => {
    vi.mocked(campaignsApi.create).mockResolvedValue({ data: { id: 'new-ulid' } } as never)

    const wrapper = await mountPage()

    await wrapper.find('[data-test="campaign-name"] input').setValue('Autumn UGC')
    await wrapper.find('[data-test="campaign-creator-posts-content"] input').setValue(true)
    await wrapper.find('[data-test="campaign-form"]').trigger('submit')
    await flushPromises()

    expect(campaignsApi.create).toHaveBeenCalledWith(
      'agency-ulid',
      expect.objectContaining({ creator_posts_content: true }),
    )
  })
})

describe('CampaignCreatePage — the brand select (AH-085)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('§5.34 — fetches via listOptions (unpaginated), not the paginated list(), and every brand past the old 25-row page is reachable in the select', async () => {
    // page_size (25) + 5 — the exact shape the old `per_page: 100` /
    // hardcoded-`paginate(25)` mismatch silently truncated.
    const brands = Array.from({ length: 30 }, (_, i) => ({
      id: `brand-${i}`,
      type: 'brands' as const,
      attributes: { name: `Brand ${String(i).padStart(2, '0')}` },
    }))
    vi.mocked(brandsApi.listOptions).mockResolvedValue({ data: brands })

    const wrapper = await mountPage()

    expect(brandsApi.listOptions).toHaveBeenCalledWith('agency-ulid', 'active')
    expect(brandsApi.list).not.toHaveBeenCalled()

    const form = wrapper.findComponent(CampaignForm)
    expect(form.props('brands')).toHaveLength(30)
    expect(form.props('brands')).toContainEqual({ id: 'brand-29', name: 'Brand 29' })
  })
})

describe('CampaignCreatePage — the region switch (AH-118)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(brandsApi.listOptions).mockResolvedValue({ data: [] })
    vi.mocked(campaignsApi.create).mockResolvedValue({ data: { id: 'new-ulid' } } as never)
  })

  it('shows no switch while the flag is off, and sends no restrict field', async () => {
    const wrapper = await mountPage()
    expect(wrapper.find('[data-test="campaign-restrict-to-regions"]').exists()).toBe(false)

    await wrapper.find('[data-test="campaign-name"] input').setValue('Autumn UGC')
    await wrapper.find('[data-test="campaign-form"]').trigger('submit')
    await flushPromises()

    const payload = vi.mocked(campaignsApi.create).mock.calls[0]?.[1] as unknown as Record<
      string,
      unknown
    >
    expect('restrict_to_listing_regions' in payload).toBe(false)
  })

  it('shows the switch OFF by default while the flag is on', async () => {
    const wrapper = await mountPage({ campaign_region_restriction: true })
    const toggle = wrapper.find('[data-test="campaign-restrict-to-regions"] input')
    expect(toggle.exists()).toBe(true)
    expect((toggle.element as HTMLInputElement).checked).toBe(false)
    // Nothing to restrict to yet: disabled until a region is chosen.
    expect((toggle.element as HTMLInputElement).disabled).toBe(true)
  })
})

describe('CampaignCreatePage — brand exclusivity (AH-120)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(brandsApi.listOptions).mockResolvedValue({
      data: [
        { id: 'acme-ulid', type: 'brands', attributes: { name: 'Acme' } },
        { id: 'zava-ulid', type: 'brands', attributes: { name: 'Zava' } },
      ],
    } as never)
    vi.mocked(campaignsApi.create).mockResolvedValue({ data: { id: 'new-ulid' } } as never)
  })

  it('shows no exclusivity control while the flag is off, and sends neither field', async () => {
    const wrapper = await mountPage()
    expect(wrapper.find('[data-test="campaign-exclusive-brands"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="campaign-exclusivity-ends-on"]').exists()).toBe(false)

    await wrapper.find('[data-test="campaign-name"] input').setValue('Autumn UGC')
    await wrapper.find('[data-test="campaign-form"]').trigger('submit')
    await flushPromises()

    const payload = vi.mocked(campaignsApi.create).mock.calls[0]?.[1] as unknown as Record<
      string,
      unknown
    >
    expect('exclusive_brand_ids' in payload).toBe(false)
    expect('exclusivity_ends_on' in payload).toBe(false)
  })

  it('shows both controls while the flag is on', async () => {
    const wrapper = await mountPage({ campaign_brand_exclusivity: true })

    expect(wrapper.find('[data-test="campaign-exclusive-brands"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="campaign-exclusivity-ends-on"]').exists()).toBe(true)
  })

  it('shows the localized message when the server says the flag is off', async () => {
    vi.mocked(campaignsApi.create).mockRejectedValue(
      new ApiError({
        status: 422,
        code: 'campaign.brand_exclusivity_disabled',
        message: 'Blocking creators is not available yet.',
        details: [],
        requestId: 'r1',
      } as never),
    )
    const wrapper = await mountPage({ campaign_brand_exclusivity: true })

    await wrapper.find('[data-test="campaign-name"] input').setValue('Autumn UGC')
    await wrapper.find('[data-test="campaign-form"]').trigger('submit')
    await flushPromises()

    expect(wrapper.find('[data-test="campaign-form-error"]').text()).toBe(
      'Brand exclusivity is not available yet.',
    )
  })
})
