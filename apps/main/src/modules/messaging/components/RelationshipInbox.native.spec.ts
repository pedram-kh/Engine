/**
 * AH-100d — the Messages empty state in the creator app. Pedram's second
 * re-walk saw a blank green pill where "Start a conversation" belongs.
 *
 * The label was never missing: the key resolves in all 24 locales. The web's
 * tonal variant puts Vuetify's `text-primary` (`color: ... !important`) on the
 * label, and the native sheet filled the same button with the mint accent, so
 * the text was mint on mint. On the app the CTA is the filled primary button
 * (Vuetify's `bg-primary`, which the native layer gives the on-accent label).
 * jsdom does not paint, so the pin is the variant, and that the text is there.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enApp from '@/core/i18n/locales/en/app.json'
import frApp from '@/core/i18n/locales/fr/app.json'

const platform = vi.hoisted(() => ({ native: false }))
vi.mock('@/core/platform/isNativePlatform', () => ({
  isNativePlatform: () => platform.native,
}))

import RelationshipInbox from './RelationshipInbox.vue'

async function mountEmpty(native: boolean, locale: 'en' | 'fr' = 'en') {
  platform.native = native
  const i18n = createI18n({
    legacy: false,
    locale,
    messages: { en: enApp, fr: frApp } as never,
  })
  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/', name: 'root', component: { template: '<div />' } }],
  })
  const wrapper = mount(RelationshipInbox, {
    props: { items: [], loading: false, loadError: false },
    global: { plugins: [i18n, vuetify, router] },
    attachTo: document.createElement('div'),
  })
  await flushPromises()
  return wrapper
}

afterEach(() => {
  platform.native = false
})

describe('RelationshipInbox empty state — native (AH-100d)', () => {
  it('draws the start-conversation button as the filled primary button, with its label', async () => {
    const btn = (await mountEmpty(true)).find('[data-test="relationship-inbox-start"]')

    expect(btn.text()).toBe('Start a conversation')
    expect(btn.classes()).toContain('v-btn--variant-flat')
    expect(btn.classes()).not.toContain('v-btn--variant-tonal')
    expect(btn.classes()).toContain('bg-primary')
  })

  it('has a non-empty label in another locale too (the key is not the problem)', async () => {
    const btn = (await mountEmpty(true, 'fr')).find('[data-test="relationship-inbox-start"]')

    expect(btn.text()).toBe('Démarrer une conversation')
  })

  it('emits start when pressed', async () => {
    const w = await mountEmpty(true)
    await w.find('[data-test="relationship-inbox-start"]').trigger('click')

    expect(w.emitted('start')).toHaveLength(1)
  })
})

describe('RelationshipInbox empty state — web is unchanged', () => {
  it('keeps the tonal button', async () => {
    const btn = (await mountEmpty(false)).find('[data-test="relationship-inbox-start"]')

    expect(btn.text()).toBe('Start a conversation')
    expect(btn.classes()).toContain('v-btn--variant-tonal')
    expect(btn.classes()).not.toContain('v-btn--variant-flat')
  })
})
