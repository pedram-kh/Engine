/**
 * AH-107 — the campaign-status chip. Surface rules (Q3): the assignment surface
 * shows every status, the job surface shows Paused only. Absent / unknown renders
 * nothing (an older backend omits the field; the SPA ships independently).
 *
 * The squeeze guard (source inspection) is the AH-106 lesson applied to the new
 * chip: `.v-chip` is `min-width: 0; overflow: hidden`, so unless the chip is
 * `flex: 0 0 auto` it clips its own label when a row is tight.
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enCreator from '@/core/i18n/locales/en/creator.json'

import CampaignStatusChip from './CampaignStatusChip.vue'

const STATUSES = ['draft', 'active', 'paused', 'completed', 'cancelled'] as const
const LABEL: Record<(typeof STATUSES)[number], string> = {
  draft: 'Upcoming',
  active: 'Active',
  paused: 'Paused',
  completed: 'Completed',
  cancelled: 'Cancelled',
}

function mountChip(props: { status: string | null | undefined; surface: 'assignment' | 'job' }) {
  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    messages: { en: enCreator } as never,
  }) as unknown as ReturnType<typeof createI18n>

  return mount(CampaignStatusChip, {
    props,
    attrs: { 'data-testid': 'chip' },
    global: {
      plugins: [
        i18n,
        createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives }),
      ],
    },
  })
}

describe('CampaignStatusChip', () => {
  it.each(STATUSES)('assignment surface shows %s with its label', (status) => {
    const wrapper = mountChip({ status, surface: 'assignment' })
    expect(wrapper.find('[data-testid="chip"]').text()).toBe(LABEL[status])
    expect(wrapper.find('[data-testid="chip"]').attributes('data-campaign-status')).toBe(status)
  })

  it.each(STATUSES)('job surface shows %s only when it is paused', (status) => {
    const wrapper = mountChip({ status, surface: 'job' })
    expect(wrapper.find('[data-testid="chip"]').exists()).toBe(status === 'paused')
  })

  it.each([undefined, null, 'archived'])('renders nothing for %s on either surface', (status) => {
    for (const surface of ['assignment', 'job'] as const) {
      expect(mountChip({ status, surface }).find('[data-testid="chip"]').exists()).toBe(false)
    }
  })

  it('never renders a raw i18n key', () => {
    for (const status of STATUSES) {
      expect(mountChip({ status, surface: 'assignment' }).text()).not.toContain('creator.ui.')
    }
  })

  describe('the chip is never squeezed (AH-106 lesson)', () => {
    it('carries the campaign-status-chip class', () => {
      const wrapper = mountChip({ status: 'paused', surface: 'assignment' })
      expect(wrapper.find('[data-testid="chip"]').classes()).toContain('campaign-status-chip')
    })

    it('pins flex: 0 0 auto on that class in the component source', () => {
      const source = readFileSync(resolve(__dirname, 'CampaignStatusChip.vue'), 'utf8')
      const rule = /\.campaign-status-chip\s*\{([^}]*)\}/.exec(source)
      expect(rule?.[1]).toMatch(/flex:\s*0\s+0\s+auto/)
    })
  })
})
