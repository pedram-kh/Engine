/**
 * AH-100 D4 — the onboarding wizard chrome inside the Capacitor creator app.
 *
 * Pins: on native the Figma frame-01 header replaces both animated chromes at
 * any width, "Save and exit" sits inline in the top bar (not in the drawer),
 * and the native modifiers are bound. Each native assertion has its web
 * polarity beside it so the web chrome selection is pinned unchanged.
 *
 * The animated chromes are stubbed: what's pinned is which chrome the layout
 * picks, not how either one animates.
 */

import type { CreatorResource } from '@catalyst/api-client'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enApp from '@/core/i18n/locales/en/app.json'
import enCreator from '@/core/i18n/locales/en/creator.json'

const platform = vi.hoisted(() => ({ native: false }))
vi.mock('@/core/platform/isNativePlatform', () => ({
  isNativePlatform: () => platform.native,
}))

vi.mock('../api/onboarding.api', () => ({
  onboardingApi: { bootstrap: vi.fn() },
}))

import { onboardingApi } from '../api/onboarding.api'
import OnboardingLayout from './OnboardingLayout.vue'

function makeCreator(score: number): CreatorResource {
  return {
    id: '01HQ',
    type: 'creators',
    attributes: {
      display_name: 'Test',
      application_status: 'incomplete',
      profile_completeness_score: score,
      click_through_accepted_at: null,
    },
    wizard: {
      next_step: 'profile',
      is_submitted: false,
      steps: [
        { id: 'profile', is_complete: false },
        { id: 'social', is_complete: false },
        { id: 'portfolio', is_complete: false },
        { id: 'kyc', is_complete: false },
        { id: 'tax', is_complete: false },
        { id: 'payout', is_complete: false },
        { id: 'contract', is_complete: false },
        { id: 'review', is_complete: false },
      ],
      flags: {},
    },
  } as unknown as CreatorResource
}

function setViewportWidth(width: number): void {
  Object.defineProperty(window, 'innerWidth', { value: width, writable: true, configurable: true })
  window.dispatchEvent(new Event('resize'))
}

async function mountLayout(options: { native?: boolean; mobile?: boolean } = {}) {
  setViewportWidth(options.mobile === true ? 390 : 1280)
  platform.native = options.native === true
  vi.mocked(onboardingApi.bootstrap).mockResolvedValue({ data: makeCreator(21) } as never)

  const pinia = createPinia()
  setActivePinia(pinia)

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      {
        path: '/onboarding/profile',
        name: 'onboarding.profile',
        component: { template: '<div />' },
      },
      { path: '/sign-in', name: 'auth.sign-in', component: { template: '<div />' } },
    ],
  })
  await router.push('/onboarding/profile')
  await router.isReady()

  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    messages: { en: { ...enApp, ...enCreator } },
    missingWarn: false,
    fallbackWarn: false,
  })

  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })

  const wrapper = mount(
    { components: { OnboardingLayout }, template: '<v-app><OnboardingLayout /></v-app>' },
    {
      attachTo: document.body,
      global: {
        plugins: [pinia, router, i18n, vuetify],
        stubs: {
          AnimatedWizardChrome: { name: 'AnimatedWizardChrome', template: '<div><slot /></div>' },
          AnimatedWizardChromeMobile: {
            name: 'AnimatedWizardChromeMobile',
            template: '<div><slot /></div>',
          },
          ImpersonationBanner: true,
          VSelect: { template: '<div />' },
        },
      },
    },
  )
  await flushPromises()

  return wrapper
}

describe('OnboardingLayout — creator app chrome (AH-100)', () => {
  let wrapper: Awaited<ReturnType<typeof mountLayout>> | null = null

  afterEach(() => {
    wrapper?.unmount()
    wrapper = null
    platform.native = false
    setViewportWidth(1280)
  })

  it('native at a desktop width: the frame-01 header, neither animated chrome', async () => {
    wrapper = await mountLayout({ native: true })

    const header = wrapper.find('[data-test="native-wizard-header"]')
    expect(header.exists()).toBe(true)
    expect(header.find('[data-test="native-wizard-header-position"]').text()).toMatch(/2/)
    expect(header.find('[data-test="native-wizard-header-completeness"]').text()).toContain('21')
    expect(wrapper.findComponent({ name: 'AnimatedWizardChrome' }).exists()).toBe(false)
    expect(wrapper.findComponent({ name: 'AnimatedWizardChromeMobile' }).exists()).toBe(false)
  })

  it('native: segments fill through the active step and no further', async () => {
    wrapper = await mountLayout({ native: true })

    const segments = wrapper.findAll('[data-test="native-wizard-header-segment"]')
    const filled = segments.filter((s) =>
      s.classes().includes('native-wizard-header__segment--filled'),
    )
    expect(segments.length).toBeGreaterThan(2)
    // Route is the profile step: account (index 0) + profile (index 1).
    expect(filled.length).toBe(2)
  })

  it('native: "Save and exit" is inline in the top bar, beside the menu', async () => {
    wrapper = await mountLayout({ native: true })

    const topbar = wrapper.find('[data-test="onboarding-topbar"]')
    expect(topbar.classes()).toContain('onboarding-topbar--native')
    expect(topbar.find('[data-test="save-and-exit-btn"]').exists()).toBe(true)
    expect(topbar.find('[data-test="onboarding-menu-btn"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="onboarding-main"]').classes()).toContain(
      'onboarding-main--native',
    )
  })

  it('native: the open menu drawer does not repeat "Save and exit"; web: it carries it', async () => {
    wrapper = await mountLayout({ native: true })
    await wrapper.find('[data-test="onboarding-menu-btn"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="onboarding-menu-locale-switcher"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="onboarding-menu-save-and-exit-btn"]').exists()).toBe(false)
    wrapper.unmount()

    wrapper = await mountLayout({ mobile: true })
    await wrapper.find('[data-test="onboarding-menu-btn"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="onboarding-menu-save-and-exit-btn"]').exists()).toBe(true)
  })

  it('web desktop: the animated chrome, no native header or modifiers', async () => {
    wrapper = await mountLayout()

    expect(wrapper.findComponent({ name: 'AnimatedWizardChrome' }).exists()).toBe(true)
    expect(wrapper.find('[data-test="native-wizard-header"]').exists()).toBe(false)
    expect(wrapper.html()).not.toContain('--native')
  })

  it('web mobile: the mobile chrome, Save and exit not inline', async () => {
    wrapper = await mountLayout({ mobile: true })

    expect(wrapper.findComponent({ name: 'AnimatedWizardChromeMobile' }).exists()).toBe(true)
    expect(wrapper.find('[data-test="native-wizard-header"]').exists()).toBe(false)
    expect(
      wrapper.find('[data-test="onboarding-topbar"] [data-test="save-and-exit-btn"]').exists(),
    ).toBe(false)
  })
})
