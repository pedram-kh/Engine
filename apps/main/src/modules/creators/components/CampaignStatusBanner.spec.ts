/**
 * AH-107 (Q2) — the banner for a paused / completed / cancelled campaign.
 * Display only; the page-level "actions still render" pins live in the
 * assignment-detail spec, where the actions are.
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enCreator from '@/core/i18n/locales/en/creator.json'

import CampaignStatusBanner from './CampaignStatusBanner.vue'

function mountBanner(status: string | null | undefined) {
  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    messages: { en: enCreator } as never,
  }) as unknown as ReturnType<typeof createI18n>

  return mount(CampaignStatusBanner, {
    props: { status },
    global: {
      plugins: [
        i18n,
        createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives }),
      ],
    },
  })
}

const BANNER = '[data-testid="creator-campaign-status-banner"]'

describe('CampaignStatusBanner', () => {
  it.each([
    ['paused', 'This campaign is paused.'],
    ['completed', 'This campaign has ended.'],
    ['cancelled', 'This campaign was cancelled.'],
  ])('shows a banner for %s', (status, copy) => {
    const wrapper = mountBanner(status)
    expect(wrapper.find(BANNER).exists()).toBe(true)
    expect(wrapper.find(BANNER).text()).toContain(copy)
  })

  it.each(['draft', 'active', undefined, null, 'archived'])('shows nothing for %s', (status) => {
    expect(mountBanner(status).find(BANNER).exists()).toBe(false)
  })
})
