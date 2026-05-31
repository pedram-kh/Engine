/**
 * Unit tests for the shared `CKpiCard` tile (`@catalyst/ui`, Sprint 4
 * Chunk 1 D-c1-10), under the package's theme-aware harness.
 *
 * What this pins:
 *   - Real state: a numeric `value` renders verbatim; the pre-localized
 *     `label` renders (i18n-free contract).
 *   - Placeholder state (D-c1-4): `null` / `undefined` `value` renders the
 *     muted em dash (`—`) with the placeholder modifier class — NOT a
 *     "coming soon" string.
 *   - Loading: renders the skeleton instead of the value.
 *   - Theme-awareness: renders correctly under BOTH the dark and light
 *     Catalyst themes (the card carries Vuetify's `v-theme--dark` /
 *     `v-theme--light`). This is the D-c1-10 "first specimen proven on the
 *     1a theme-aware harness in dark mode" requirement.
 */

import { VCard } from 'vuetify/components'
import { describe, expect, it } from 'vitest'

import CKpiCard from '../../src/components/CKpiCard.vue'

import { mountThemed, type ThemeMode } from '../helpers/mountThemed'

describe('CKpiCard — real state', () => {
  it('renders the pre-localized label and a numeric value', () => {
    const h = mountThemed(CKpiCard, {
      props: { label: 'Creators in roster', value: 42, dataTest: 'kpi-roster' },
    })
    try {
      expect(h.wrapper.find('[data-test="kpi-roster"]').exists()).toBe(true)
      expect(h.wrapper.find('.c-kpi-card__label').text()).toBe('Creators in roster')
      const value = h.wrapper.find('[data-test="kpi-card-value"]')
      expect(value.text()).toBe('42')
      expect(value.classes()).not.toContain('c-kpi-card__value--placeholder')
    } finally {
      h.unmount()
    }
  })

  it('renders a zero value as "0", not the placeholder dash', () => {
    const h = mountThemed(CKpiCard, { props: { label: 'Pending', value: 0 } })
    try {
      const value = h.wrapper.find('[data-test="kpi-card-value"]')
      expect(value.text()).toBe('0')
      expect(value.classes()).not.toContain('c-kpi-card__value--placeholder')
    } finally {
      h.unmount()
    }
  })
})

describe('CKpiCard — placeholder state (D-c1-4)', () => {
  it('renders a muted em dash when value is null', () => {
    const h = mountThemed(CKpiCard, { props: { label: 'Active campaigns', value: null } })
    try {
      const value = h.wrapper.find('[data-test="kpi-card-value"]')
      expect(value.text()).toBe('—')
      expect(value.classes()).toContain('c-kpi-card__value--placeholder')
    } finally {
      h.unmount()
    }
  })

  it('treats an omitted value as a placeholder (default null)', () => {
    const h = mountThemed(CKpiCard, { props: { label: 'Payments due' } })
    try {
      const value = h.wrapper.find('[data-test="kpi-card-value"]')
      expect(value.text()).toBe('—')
      expect(value.classes()).toContain('c-kpi-card__value--placeholder')
    } finally {
      h.unmount()
    }
  })

  it('renders no "coming soon" copy in the placeholder state', () => {
    const h = mountThemed(CKpiCard, { props: { label: 'Active campaigns', value: null } })
    try {
      expect(h.wrapper.text().toLowerCase()).not.toContain('coming soon')
    } finally {
      h.unmount()
    }
  })
})

describe('CKpiCard — loading state', () => {
  it('renders the skeleton in place of the value when loading', () => {
    const h = mountThemed(CKpiCard, { props: { label: 'Creators in roster', loading: true } })
    try {
      expect(h.wrapper.find('[data-test="kpi-card-skeleton"]').exists()).toBe(true)
      expect(h.wrapper.find('[data-test="kpi-card-value"]').exists()).toBe(false)
    } finally {
      h.unmount()
    }
  })
})

describe('CKpiCard — theme-aware rendering (1a harness)', () => {
  it.each<ThemeMode>(['dark', 'light'])('renders under the %s Catalyst theme', (mode) => {
    const h = mountThemed(CKpiCard, { props: { label: 'Creators in roster', value: 7 }, mode })
    try {
      const card = h.wrapper.findComponent(VCard)
      expect(card.classes()).toContain(`v-theme--${mode}`)
      expect(h.wrapper.find('[data-test="kpi-card-value"]').text()).toBe('7')
    } finally {
      h.unmount()
    }
  })
})
