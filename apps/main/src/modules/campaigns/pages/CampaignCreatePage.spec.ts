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
  brandsApi: { list: vi.fn() },
}))

vi.mock('@/core/stores/useAgencyStore', () => ({
  useAgencyStore: () => ({ currentAgencyId: 'agency-ulid' }),
}))

const push = vi.fn()
vi.mock('vue-router', () => ({
  useRouter: () => ({ push }),
}))

import { brandsApi } from '@/modules/brands/api/brands.api'
import { campaignsApi } from '../api/campaigns.api'
import CampaignCreatePage from './CampaignCreatePage.vue'

async function mountPage() {
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
    vi.mocked(brandsApi.list).mockResolvedValue({ data: [] } as never)
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
