/**
 * AH-114 (S3.3) — `AgencyCreatorCard`: draws only what it is given (no location line without a
 * country or age, no language line, no chip row), collapses categories past two into "+N",
 * shows the shared status pill for a connection and its own word for none, falls back to a
 * status-coloured cover with the initial when there is no picture, and opens on click, Enter
 * and Space. Size, colour and type are pinned in a real browser by `tools/walk/pins-s33.mjs`.
 */

import type { DiscoveryConnectionState } from '@catalyst/api-client'
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'
import { createI18n } from 'vue-i18n'

import enApp from '@/core/i18n/locales/en/app.json'
import AgencyCreatorCard from '@/modules/agency/components/AgencyCreatorCard.vue'
import { BRAND_MARK_TONES, brandMarkTone } from '@/modules/agency/status/agencyBrandTone'

const ID = '01HCREATOR000000000000000X'

type Props = InstanceType<typeof AgencyCreatorCard>['$props']

function mountCard(overrides: Partial<Props> = {}) {
  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    messages: { en: enApp } as never,
  })
  return mount(AgencyCreatorCard, {
    props: {
      id: ID,
      name: 'Maya Thompson',
      avatarUrl: null,
      avatarAlt: 'Maya Thompson',
      initial: 'M',
      country: 'United States',
      age: 28,
      language: 'English',
      categories: ['Lifestyle', 'Tech'],
      connection: 'roster' as unknown as DiscoveryConnectionState,
      notConnectedLabel: 'Not connected',
      ...overrides,
    } as Props,
    global: {
      plugins: [
        i18n,
        createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives }),
      ],
    },
  })
}

describe('AgencyCreatorCard', () => {
  it('carries the page anchors: card, name, age', () => {
    const wrapper = mountCard()

    expect(wrapper.attributes('data-test')).toBe(`discover-card-${ID}`)
    expect(wrapper.get(`[data-test="discover-name-${ID}"]`).text()).toBe('Maya Thompson')
    expect(wrapper.get(`[data-test="discover-age-${ID}"]`).text()).toBe('28')
  })

  it('location reads "Country  ·  age" (two spaces each side, as drawn); one half alone has no separator', () => {
    expect(mountCard().get('.agency-creator-card__line').text()).toBe('United States  ·  28')
    expect(mountCard({ age: null }).get('.agency-creator-card__line').text()).toBe('United States')
    expect(mountCard({ country: null }).get('.agency-creator-card__line').text()).toBe('28')
  })

  it('draws no location line without a country or an age, no language line without a language', () => {
    const wrapper = mountCard({ country: null, age: null, language: null })

    expect(wrapper.findAll('.agency-creator-card__line')).toHaveLength(0)
  })

  it('draws no chip row without categories, and two categories show as two chips', () => {
    expect(mountCard({ categories: [] }).find('.agency-creator-card__chips').exists()).toBe(false)

    const two = mountCard()
    expect(two.findAll('.agency-creator-card__chip').map((c) => c.text())).toStrictEqual([
      'Lifestyle',
      'Tech',
    ])
    expect(two.find(`[data-test="discover-cats-more-${ID}"]`).exists()).toBe(false)
  })

  it('collapses categories past two into a "+N" chip', () => {
    const wrapper = mountCard({ categories: ['A', 'B', 'C', 'D', 'E'] })

    expect(wrapper.findAll('.agency-creator-card__chip').map((c) => c.text())).toStrictEqual([
      'A',
      'B',
      '+3',
    ])
    expect(wrapper.get(`[data-test="discover-cats-more-${ID}"]`).text()).toBe('+3')
  })

  it.each([
    ['roster', 'Connected'],
    ['pending_request', 'Request pending'],
    ['declined', 'Declined'],
    ['ended', 'Previously connected'],
  ] as const)('a %s creator shows the status pill "%s"', (relationship, word) => {
    const state = {
      roster: 'connected',
      pending_request: 'pending',
      declined: 'declined',
      ended: 'ended',
    }[relationship] as DiscoveryConnectionState
    const wrapper = mountCard({ connection: state })

    expect(wrapper.get(`[data-test="discover-connection-${state}-${ID}"]`).text()).toBe(word)
    expect(wrapper.find(`[data-test="discover-notconnected-${ID}"]`).exists()).toBe(false)
  })

  it("no connection shows the page's own word and no pill", () => {
    const wrapper = mountCard({ connection: 'none' })

    expect(wrapper.get(`[data-test="discover-notconnected-${ID}"]`).text()).toBe('Not connected')
    expect(wrapper.find('.agency-status-pill').exists()).toBe(false)
  })

  it('no picture: a status-coloured cover with the initial, the tone chosen from the id', () => {
    const wrapper = mountCard()
    const cover = wrapper.get('.agency-creator-card__cover')

    expect(cover.find('img').exists()).toBe(false)
    expect(cover.get('.agency-creator-card__initial').text()).toBe('M')
    const tone = brandMarkTone(ID)
    expect(BRAND_MARK_TONES).toContain(tone)
    expect(cover.classes()).toContain(`agency-creator-card__cover--${tone}`)
  })

  it('a picture replaces the initial and carries its alt text', () => {
    const wrapper = mountCard({ avatarUrl: 'https://cdn.test/a.jpg', avatarAlt: 'Maya at work' })
    const img = wrapper.get('.agency-creator-card__cover img')

    expect(img.attributes('src')).toBe('https://cdn.test/a.jpg')
    expect(img.attributes('alt')).toBe('Maya at work')
    expect(wrapper.find('.agency-creator-card__initial').exists()).toBe(false)
  })

  it('opens on a click, on Enter and on Space', async () => {
    const wrapper = mountCard()

    await wrapper.trigger('click')
    await wrapper.trigger('keydown', { key: 'Enter' })
    await wrapper.trigger('keydown', { key: ' ' })

    expect(wrapper.emitted('open')).toHaveLength(3)
  })

  it("is one focusable link named for the creator; the frame's heart, video count and verified badge are not drawn", () => {
    const wrapper = mountCard()

    expect(wrapper.attributes('role')).toBe('link')
    expect(wrapper.attributes('tabindex')).toBe('0')
    expect(wrapper.attributes('aria-label')).toBe('Maya Thompson')
    expect(wrapper.find('.mdi-heart').exists()).toBe(false)
    expect(wrapper.find('.mdi-play').exists()).toBe(false)
    expect(wrapper.find('.mdi-check-decagram').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('videos')
  })
})
