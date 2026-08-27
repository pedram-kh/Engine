/**
 * Test harness for the admin SPA's automations pages (AH-090, D6).
 *
 * Mirror of `mountFeatureFlagsPage.ts` tuned for the automations route table.
 * The i18n bundle is built with the SAME `deepMergeLocale` the real app
 * uses so the shared `admin.*` namespace across every module locale file
 * survives (a shallow spread would clobber siblings).
 */

import { mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia, type Pinia } from 'pinia'
import { createMemoryHistory, createRouter, type Router, type RouteLocationRaw } from 'vue-router'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'
import { createI18n } from 'vue-i18n'

import { deepMergeLocale } from '@/core/i18n/deepMerge'
import enAgencies from '@/core/i18n/locales/en/agencies.json'
import enApp from '@/core/i18n/locales/en/app.json'
import enAudit from '@/core/i18n/locales/en/audit.json'
import enAutomations from '@/core/i18n/locales/en/automations.json'
import enAuth from '@/core/i18n/locales/en/auth.json'
import enCreators from '@/core/i18n/locales/en/creators.json'
import enFeatureFlags from '@/core/i18n/locales/en/feature-flags.json'
import itAgencies from '@/core/i18n/locales/it/agencies.json'
import itApp from '@/core/i18n/locales/it/app.json'
import itAudit from '@/core/i18n/locales/it/audit.json'
import itAutomations from '@/core/i18n/locales/it/automations.json'
import itAuth from '@/core/i18n/locales/it/auth.json'
import itCreators from '@/core/i18n/locales/it/creators.json'
import itFeatureFlags from '@/core/i18n/locales/it/feature-flags.json'
import ptAgencies from '@/core/i18n/locales/pt/agencies.json'
import ptApp from '@/core/i18n/locales/pt/app.json'
import ptAudit from '@/core/i18n/locales/pt/audit.json'
import ptAutomations from '@/core/i18n/locales/pt/automations.json'
import ptAuth from '@/core/i18n/locales/pt/auth.json'
import ptCreators from '@/core/i18n/locales/pt/creators.json'
import ptFeatureFlags from '@/core/i18n/locales/pt/feature-flags.json'

import { automationsRoutes } from '@/modules/automations/routes'

import type { Component } from 'vue'

export interface MountAutomationsPageOptions {
  initialRoute?: RouteLocationRaw
  locale?: 'en' | 'pt' | 'it'
  props?: Record<string, unknown>
  stubs?: Record<string, Component | boolean>
}

export interface MountAutomationsPageResult<T> {
  wrapper: VueWrapper<T>
  router: Router
  pinia: Pinia
  i18n: ReturnType<typeof createI18n>
  vuetify: ReturnType<typeof createVuetify>
  unmount: () => void
}

export async function mountAutomationsPage<T = unknown>(
  component: Component,
  options: MountAutomationsPageOptions = {},
): Promise<MountAutomationsPageResult<T>> {
  const pinia = createPinia()
  setActivePinia(pinia)

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [...automationsRoutes, { path: '/', name: 'home', component: { template: '<div/>' } }],
  })

  const vuetify = createVuetify({
    components: vuetifyComponents,
    directives: vuetifyDirectives,
  })

  const messages = {
    en: deepMergeLocale(
      enApp,
      enAuth,
      enCreators,
      enAgencies,
      enAudit,
      enFeatureFlags,
      enAutomations,
    ),
    pt: deepMergeLocale(
      ptApp,
      ptAuth,
      ptCreators,
      ptAgencies,
      ptAudit,
      ptFeatureFlags,
      ptAutomations,
    ),
    it: deepMergeLocale(
      itApp,
      itAuth,
      itCreators,
      itAgencies,
      itAudit,
      itFeatureFlags,
      itAutomations,
    ),
  } as unknown as Record<'en' | 'pt' | 'it', Record<string, unknown>>

  const i18n = createI18n({
    legacy: false,
    locale: options.locale ?? 'en',
    fallbackLocale: 'en',
    availableLocales: ['en', 'pt', 'it'],
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    messages: messages as any,
  }) as unknown as ReturnType<typeof createI18n>

  const initial = options.initialRoute ?? '/automations'
  try {
    await router.push(initial)
  } catch {
    /* duplicated-navigation warning swallowed */
  }
  await router.isReady()

  const wrapper = mount(component, {
    props: options.props ?? {},
    global: {
      plugins: [pinia, router, i18n, vuetify],
      stubs: {
        RouterLink: true,
        ...(options.stubs ?? {}),
      },
    },
    attachTo: document.createElement('div'),
  }) as unknown as VueWrapper<T>

  return {
    wrapper,
    router,
    pinia,
    i18n,
    vuetify,
    unmount: () => {
      wrapper.unmount()
    },
  }
}
