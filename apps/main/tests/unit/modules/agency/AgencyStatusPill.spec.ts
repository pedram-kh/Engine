/**
 * AH-114 (S2.1) — `AgencyStatusPill`: each tone renders its class, the dot and the
 * word; a table value takes its tone and label from `AGENCY_STATUS_TONES`; and the
 * pill never renders without a word. Colour itself is pinned in a real browser by
 * `tools/walk/pins-s21.mjs` (jsdom has no cascade).
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { createI18n } from 'vue-i18n'

import enApp from '@/core/i18n/locales/en/app.json'
import AgencyStatusPill from '@/modules/agency/components/AgencyStatusPill.vue'
import {
  AGENCY_STATUS_TONES,
  type AgencyStatusKind,
  type AgencyStatusTone,
} from '@/modules/agency/status/agencyStatusTones'

function mountPill(props: Record<string, unknown>) {
  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    availableLocales: ['en'],
    messages: { en: enApp } as never,
  })
  return mount(AgencyStatusPill, { props, global: { plugins: [i18n] } })
}

const TONES: AgencyStatusTone[] = ['success', 'info', 'warning', 'danger', 'neutral', 'ai']

describe('AgencyStatusPill', () => {
  it.each(TONES)('renders the %s tone: class, dot and word', (tone) => {
    const wrapper = mountPill({ tone, label: 'Approved' })
    const pill = wrapper.get('[data-test="agency-status-pill"]')

    expect(pill.classes()).toContain('agency-status-pill')
    expect(pill.classes()).toContain(`agency-status-pill--${tone}`)
    expect(pill.attributes('data-tone')).toBe(tone)
    expect(pill.find('.agency-status-pill__dot').exists()).toBe(true)
    expect(pill.find('.agency-status-pill__dot').attributes('aria-hidden')).toBe('true')
    expect(pill.find('.agency-status-pill__label').text()).toBe('Approved')
  })

  it('takes tone and word from the table', () => {
    const wrapper = mountPill({ kind: 'campaign', status: 'paused' })
    expect(wrapper.get('[data-test="agency-status-pill"]').attributes('data-tone')).toBe('warning')
    expect(wrapper.text()).toBe('Paused')
  })

  it('maps every table value to a pill with its table tone and a non-empty word', () => {
    for (const kind of Object.keys(AGENCY_STATUS_TONES) as AgencyStatusKind[]) {
      const table = AGENCY_STATUS_TONES[kind] as Record<string, { tone: string | null }>
      for (const [status, entry] of Object.entries(table)) {
        const wrapper = mountPill({ kind, status })
        if (entry.tone === null) {
          expect(
            wrapper.find('[data-test="agency-status-pill"]').exists(),
            `${kind}.${status}`,
          ).toBe(false)
          continue
        }
        const pill = wrapper.get('[data-test="agency-status-pill"]')
        expect(pill.attributes('data-tone'), `${kind}.${status}`).toBe(entry.tone)
        expect(wrapper.text().trim(), `${kind}.${status}`).not.toBe('')
      }
    }
  })

  it('renders nothing for a status that shows no pill', () => {
    expect(mountPill({ kind: 'discovery', status: 'none' }).html()).toBe('<!--v-if-->')
  })

  it('renders a neutral pill with the raw word for a value the table does not know', () => {
    const wrapper = mountPill({ kind: 'campaign', status: 'archived_later' })
    expect(wrapper.get('[data-test="agency-status-pill"]').attributes('data-tone')).toBe('neutral')
    expect(wrapper.text()).toBe('archived_later')
  })

  it('never renders without a word', () => {
    for (const props of [
      { tone: 'success', label: '' },
      { tone: 'success', label: '   ' },
      { tone: 'success' },
      { kind: 'campaign' },
      { kind: 'campaign', status: null },
      { kind: 'campaign', status: '' },
    ]) {
      expect(
        mountPill(props).find('[data-test="agency-status-pill"]').exists(),
        JSON.stringify(props),
      ).toBe(false)
    }
  })
})
