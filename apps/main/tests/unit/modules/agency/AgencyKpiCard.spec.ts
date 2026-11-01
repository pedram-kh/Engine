/**
 * AH-114 (S2.4) — `AgencyKpiCard`, the agency fork of `CKpiCard`.
 *
 * Same contract as `CKpiCard` (label, value, muted dash for null, skeleton while loading, the
 * root `data-test`, the inner anchors) plus two optional pieces that appear only when the page
 * supplies them: an icon tile and, when the card links, a chevron. Geometry and colour are
 * pinned in a real browser by `tools/walk/pins-s24.mjs`.
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import AgencyKpiCard from '@/modules/agency/components/AgencyKpiCard.vue'

async function mountCard(props: Record<string, unknown>) {
  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/roster', name: 'roster', component: { template: '<div />' } }],
  })
  await router.push('/roster')
  await router.isReady()
  return mount(AgencyKpiCard, {
    props: { label: 'Creators in roster', ...props },
    global: { plugins: [vuetify, router] },
  })
}

describe('AgencyKpiCard', () => {
  it('shows the label and the value', async () => {
    const wrapper = await mountCard({ value: 42 })
    expect(wrapper.text()).toContain('Creators in roster')
    expect(wrapper.get('[data-test="kpi-card-value"]').text()).toBe('42')
  })

  it('shows 0 as 0, not as the placeholder', async () => {
    const wrapper = await mountCard({ value: 0 })
    expect(wrapper.get('[data-test="kpi-card-value"]').text()).toBe('0')
    expect(wrapper.get('[data-test="kpi-card-value"]').classes()).not.toContain(
      'agency-kpi__value--placeholder',
    )
  })

  it('shows a muted dash for a null or missing value', async () => {
    for (const props of [{ value: null }, {}]) {
      const wrapper = await mountCard(props)
      const value = wrapper.get('[data-test="kpi-card-value"]')
      expect(value.text()).toBe('—')
      expect(value.classes()).toContain('agency-kpi__value--placeholder')
    }
  })

  it('shows a skeleton instead of the value while loading', async () => {
    const wrapper = await mountCard({ value: 42, loading: true })
    expect(wrapper.find('[data-test="kpi-card-skeleton"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="kpi-card-value"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('Creators in roster')
  })

  it('puts the data-test on the root', async () => {
    const wrapper = await mountCard({ value: 1, dataTest: 'kpi-roster' })
    expect(wrapper.attributes('data-test')).toBe('kpi-roster')
  })

  it('is a plain card with no tile and no chevron when given neither', async () => {
    const wrapper = await mountCard({ value: 1 })
    expect(wrapper.element.tagName).toBe('DIV')
    expect(wrapper.find('[data-test="agency-kpi-icon"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="agency-kpi-chevron"]').exists()).toBe(false)
    expect(wrapper.classes()).not.toContain('agency-kpi--link')
  })

  it('draws the icon tile only when an icon is supplied', async () => {
    const wrapper = await mountCard({ value: 1, icon: 'mdi-account-group-outline' })
    const tile = wrapper.get('[data-test="agency-kpi-icon"]')
    expect(tile.find('.mdi-account-group-outline').exists()).toBe(true)
    expect(wrapper.find('[data-test="agency-kpi-chevron"]').exists()).toBe(false)
  })

  it('fills the tile with the chosen tone, "ai" when none is given', async () => {
    const dflt = await mountCard({ value: 1, icon: 'mdi-account' })
    expect(dflt.get('[data-test="agency-kpi-icon"]').classes()).toContain('agency-kpi__tile--ai')

    for (const tone of ['ai', 'success', 'info', 'warning']) {
      const wrapper = await mountCard({ value: 1, icon: 'mdi-account', tileTone: tone })
      expect(wrapper.get('[data-test="agency-kpi-icon"]').classes()).toContain(
        `agency-kpi__tile--${tone}`,
      )
    }
  })

  it('becomes a link with a chevron only when it has somewhere to go', async () => {
    const wrapper = await mountCard({ value: 1, to: { name: 'roster' } })
    expect(wrapper.element.tagName).toBe('A')
    expect(wrapper.attributes('href')).toBe('/roster')
    expect(wrapper.classes()).toContain('agency-kpi--link')
    expect(wrapper.find('[data-test="agency-kpi-chevron"]').exists()).toBe(true)
    expect(wrapper.get('[data-test="agency-kpi-chevron"]').attributes('aria-hidden')).toBe('true')
  })
})
