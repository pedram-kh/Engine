/**
 * AH-104d S6 — Security (account deletion) reachable from inside the wizard.
 *
 * `OnboardingAccountMenu` sits after "Save and exit" on desktop web and in the
 * creator app's header; web-mobile gets a plain Security row in its existing
 * drawer instead. All three follow `features.account_deletion`, and render
 * NOTHING while it is off (not an empty button). Nothing else in the chrome moves.
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
import { useAuthStore } from '@/modules/auth/stores/useAuthStore'

const platform = vi.hoisted(() => ({ native: false }))
vi.mock('@/core/platform/isNativePlatform', () => ({
  isNativePlatform: () => platform.native,
}))

vi.mock('../api/onboarding.api', () => ({
  onboardingApi: { bootstrap: vi.fn() },
}))

import { onboardingApi } from '../api/onboarding.api'
import OnboardingLayout from './OnboardingLayout.vue'

/** VMenu renders its content in a teleported overlay on open; inline keeps it reachable. */
const VMenuStub = {
  name: 'VMenu',
  template: '<div><slot name="activator" v-bind="{ props: {} }" /><slot /></div>',
}

function makeCreator(): CreatorResource {
  return {
    id: '01HQ',
    type: 'creators',
    attributes: {
      display_name: 'Test',
      application_status: 'incomplete',
      profile_completeness_score: 21,
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

type Shell = 'desktop' | 'native' | 'web-mobile'

async function mountLayout(
  shell: Shell,
  /** `features.account_deletion`; `undefined` = an older payload with no `features`. */
  flag: boolean | undefined,
  options: { signedOut?: boolean } = {},
) {
  setViewportWidth(shell === 'web-mobile' ? 390 : 1280)
  platform.native = shell === 'native'
  vi.mocked(onboardingApi.bootstrap).mockResolvedValue({ data: makeCreator() } as never)

  const pinia = createPinia()
  setActivePinia(pinia)
  if (options.signedOut !== true) {
    useAuthStore().user = {
      id: '01USERULIDXXXXXXXXXXXXXXXXX',
      type: 'users',
      attributes: {
        email: 'new@creator.example',
        email_verified_at: '2026-10-26T10:00:00Z',
        name: 'Nova Creator',
        user_type: 'creator',
        preferred_language: 'en',
        preferred_currency: null,
        timezone: null,
        ...(flag === undefined ? {} : { features: { account_deletion: flag } }),
      },
    } as never
  }

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      {
        path: '/onboarding/profile',
        name: 'onboarding.profile',
        component: { template: '<div />' },
      },
      { path: '/creator/security', name: 'creator.security', component: { template: '<div />' } },
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
          VMenu: VMenuStub,
        },
      },
    },
  )
  await flushPromises()
  return wrapper
}

const BTN = '[data-test="onboarding-account-menu-btn"]'
const MENU = '[data-test="onboarding-account-menu"]'
const SECURITY = '[data-test="onboarding-account-security"]'
const DRAWER_SECURITY = '[data-test="onboarding-menu-security"]'
const TOPBAR = '[data-test="onboarding-topbar"]'

describe('OnboardingLayout — account menu (AH-104d)', () => {
  let wrapper: Awaited<ReturnType<typeof mountLayout>> | null = null

  afterEach(() => {
    wrapper?.unmount()
    wrapper = null
    platform.native = false
    setViewportWidth(1280)
  })

  describe('flag on', () => {
    it('desktop web: the avatar button sits in the topbar and opens to the email and Security', async () => {
      wrapper = await mountLayout('desktop', true)

      const topbar = wrapper.find(TOPBAR)
      expect(topbar.find(BTN).exists()).toBe(true)
      expect(topbar.find(BTN).attributes('aria-label')).toBe('Menu')
      expect(topbar.find(`${BTN} .v-avatar`).text()).toBe('N')
      expect(wrapper.find('[data-test="onboarding-account-email"]').text()).toBe(
        'new@creator.example',
      )
      const security = wrapper.find(SECURITY)
      expect(security.text()).toContain('Security')
      expect(security.attributes('href')).toBe('/creator/security')
    })

    it('the menu holds Security and nothing else (no locale, theme or sign-out)', async () => {
      wrapper = await mountLayout('desktop', true)

      expect(wrapper.findAll(`${MENU} .v-list-item`)).toHaveLength(1)
      expect(wrapper.find(`${MENU} .v-select`).exists()).toBe(false)
      expect(wrapper.find(`${MENU} [data-test="sign-out-btn"]`).exists()).toBe(false)
    })

    it('desktop web: nothing else in the topbar moved (locale switcher and Save and exit remain)', async () => {
      wrapper = await mountLayout('desktop', true)

      const topbar = wrapper.find(TOPBAR)
      expect(topbar.find('[data-test="onboarding-locale-switcher"]').exists()).toBe(true)
      expect(topbar.find('[data-test="save-and-exit-btn"]').exists()).toBe(true)
      expect(topbar.find('[data-test="onboarding-menu-btn"]').exists()).toBe(false)
    })

    it('Save and exit still signs out and goes to sign-in (the menu did not take it over)', async () => {
      wrapper = await mountLayout('desktop', true)
      const logout = vi.spyOn(useAuthStore(), 'logout').mockResolvedValue(undefined)

      await wrapper.find('[data-test="save-and-exit-btn"]').trigger('click')
      await flushPromises()

      expect(logout).toHaveBeenCalledTimes(1)
    })

    it('creator app: the button is in the header beside Save and exit, and the hamburger is still gone', async () => {
      wrapper = await mountLayout('native', true)

      const topbar = wrapper.find(TOPBAR)
      expect(topbar.find(BTN).exists()).toBe(true)
      expect(topbar.find('[data-test="save-and-exit-btn"]').exists()).toBe(true)
      expect(wrapper.find('[data-test="onboarding-menu-btn"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="onboarding-menu-drawer"]').exists()).toBe(false)
      expect(wrapper.find(SECURITY).attributes('href')).toBe('/creator/security')
    })

    it('web mobile: a plain Security row in the drawer, not a second menu', async () => {
      wrapper = await mountLayout('web-mobile', true)

      expect(wrapper.find(BTN).exists()).toBe(false)
      expect(wrapper.find(MENU).exists()).toBe(false)
      const row = wrapper.find(`[data-test="onboarding-menu-drawer"] ${DRAWER_SECURITY}`)
      expect(row.exists()).toBe(true)
      expect(row.text()).toContain('Security')
      expect(row.attributes('href')).toBe('/creator/security')
      expect(wrapper.find('[data-test="onboarding-menu-btn"]').exists()).toBe(true)
      expect(wrapper.find('[data-test="onboarding-menu-locale-switcher"]').exists()).toBe(true)
      expect(wrapper.find('[data-test="onboarding-menu-save-and-exit-btn"]').exists()).toBe(true)
    })

    it('desktop and native never show the drawer row (the menu is their entry)', async () => {
      wrapper = await mountLayout('desktop', true)
      expect(wrapper.find(DRAWER_SECURITY).exists()).toBe(false)
      wrapper.unmount()

      wrapper = await mountLayout('native', true)
      expect(wrapper.find(DRAWER_SECURITY).exists()).toBe(false)
    })
  })

  describe.each<[string, boolean | undefined, boolean]>([
    ['off', false, false],
    ['absent (an older API payload)', undefined, false],
    ['on but signed out (no user)', true, true],
  ])('flag %s: nothing renders, not even an empty button', (_label, flag, signedOut) => {
    it.each<Shell>(['desktop', 'native', 'web-mobile'])('%s', async (shell) => {
      wrapper = await mountLayout(shell, flag, { signedOut })

      expect(wrapper.find(BTN).exists()).toBe(false)
      expect(wrapper.find(MENU).exists()).toBe(false)
      expect(wrapper.find(SECURITY).exists()).toBe(false)
      expect(wrapper.find(DRAWER_SECURITY).exists()).toBe(false)
      expect(wrapper.find(`${TOPBAR} .v-avatar`).exists()).toBe(false)
    })
  })
})
