/**
 * PL-1.2 W3 — the wave card on a campaign's Overview: shows the wave, links, changes, unlinks, explains a lock,
 * and offers the controls to an admin or manager only.
 */
import { ApiError } from '@catalyst/api-client'
import type { CampaignResource } from '@catalyst/api-client'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'
import { VSelect } from 'vuetify/components'

import enApp from '@/core/i18n/locales/en/app.json'

vi.mock('../api/campaigns.api', () => ({
  campaignsApi: { waveOptions: vi.fn(), linkWave: vi.fn(), unlinkWave: vi.fn() },
}))

import { campaignsApi } from '../api/campaigns.api'
import CampaignWaveCard from './CampaignWaveCard.vue'

const WAVE = '01WAVEULIDXXXXXXXXXXXXXXX'

function campaign(wave: { link_locked: boolean } | null): CampaignResource {
  return {
    id: '01CAMPAIGNULIDXXXXXXXXXX',
    type: 'campaigns',
    attributes: { campaign_type: 'for_wave' },
    relationships: {
      brand: { data: { id: '01BRANDULIDXXXXXXXXXXXXXX', type: 'brands', name: 'Acme' } },
      ...(wave
        ? {
            wave: {
              data: {
                id: WAVE,
                type: 'waves',
                attributes: {
                  name: 'Spring push',
                  go_live_on: '2026-11-07',
                  status: 'draft',
                  link_locked: wave.link_locked,
                },
              },
            },
          }
        : { wave: null }),
    },
  } as unknown as CampaignResource
}

async function mountCard(c: CampaignResource, canManage = true) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      {
        path: '/brands/:ulid/waves/:waveUlid',
        name: 'waves.detail',
        component: { template: '<i/>' },
      },
    ],
  })
  await router.push('/')
  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    availableLocales: ['en'],
    messages: { en: enApp } as never,
  }) as unknown as ReturnType<typeof createI18n>
  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })
  return mount(CampaignWaveCard, {
    props: { agencyId: 'agency-ulid', campaign: c, canManage },
    global: { plugins: [router, i18n, vuetify] },
    attachTo: document.createElement('div'),
  })
}

const err = (code: string) =>
  new ApiError({ status: 422, code, message: 'x', details: [], raw: null } as never)

beforeEach(() => {
  vi.clearAllMocks()
  vi.mocked(campaignsApi.waveOptions).mockResolvedValue({
    data: [
      {
        id: WAVE,
        type: 'wave_options',
        attributes: { name: 'Autumn push', go_live_on: '2026-12-01' },
      },
    ],
  })
  vi.mocked(campaignsApi.linkWave).mockResolvedValue({ data: {} } as never)
  vi.mocked(campaignsApi.unlinkWave).mockResolvedValue(undefined)
})
afterEach(() => {
  document.body.innerHTML = ''
})

describe('CampaignWaveCard (PL-1.2 W3)', () => {
  it('shows the linked wave by name with Change and Unlink for a manager', async () => {
    const w = await mountCard(campaign({ link_locked: false }))
    expect(w.find('[data-test="campaign-wave-link"]').text()).toBe('Spring push')
    expect(w.find('[data-test="campaign-wave-change-btn"]').attributes('disabled')).toBeUndefined()
    expect(w.find('[data-test="campaign-wave-unlink-btn"]').attributes('disabled')).toBeUndefined()
    expect(w.find('[data-test="campaign-wave-locked"]').exists()).toBe(false)
  })

  it("with no wave it offers Link, lists this brand's free waves, and links the chosen one", async () => {
    const w = await mountCard(campaign(null))
    expect(w.find('[data-test="campaign-wave-none"]').exists()).toBe(true)
    await w.find('[data-test="campaign-wave-link-btn"]').trigger('click')
    await flushPromises()
    expect(campaignsApi.waveOptions).toHaveBeenCalledWith('agency-ulid', '01CAMPAIGNULIDXXXXXXXXXX')
    w.findComponent(VSelect).vm.$emit('update:modelValue', WAVE)
    await flushPromises()
    await w.find('[data-test="campaign-wave-confirm"]').trigger('click')
    await flushPromises()
    expect(campaignsApi.linkWave).toHaveBeenCalledWith(
      'agency-ulid',
      '01CAMPAIGNULIDXXXXXXXXXX',
      WAVE,
    )
    expect(w.emitted('changed')).toHaveLength(1)
  })

  it('Unlink calls the API and asks the page to reload', async () => {
    const w = await mountCard(campaign({ link_locked: false }))
    await w.find('[data-test="campaign-wave-unlink-btn"]').trigger('click')
    await flushPromises()
    expect(campaignsApi.unlinkWave).toHaveBeenCalledTimes(1)
    expect(w.emitted('changed')).toHaveLength(1)
  })

  it('a locked link disables Change and Unlink and says why', async () => {
    const w = await mountCard(campaign({ link_locked: true }))
    expect(w.find('[data-test="campaign-wave-change-btn"]').attributes('disabled')).toBeDefined()
    expect(w.find('[data-test="campaign-wave-unlink-btn"]').attributes('disabled')).toBeDefined()
    expect(w.find('[data-test="campaign-wave-locked"]').text()).toContain('Selected or further')
  })

  it('a refused change that raced a move shows the lock reason and reloads', async () => {
    vi.mocked(campaignsApi.unlinkWave).mockRejectedValue(err('wave.campaign_link_locked'))
    const w = await mountCard(campaign({ link_locked: false }))
    await w.find('[data-test="campaign-wave-unlink-btn"]').trigger('click')
    await flushPromises()
    expect(w.find('[data-test="campaign-wave-error"]').text()).toContain('Selected or further')
    expect(w.emitted('changed')).toHaveLength(1)
  })

  it('a staff member sees the wave but no controls', async () => {
    const w = await mountCard(campaign({ link_locked: false }), false)
    expect(w.find('[data-test="campaign-wave-link"]').exists()).toBe(true)
    expect(w.find('[data-test="campaign-wave-change-btn"]').exists()).toBe(false)
    expect(w.find('[data-test="campaign-wave-unlink-btn"]').exists()).toBe(false)
    expect(w.find('[data-test="campaign-wave-link-btn"]').exists()).toBe(false)
  })
})
