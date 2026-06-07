/**
 * Test harness for the admin SPA's agencies-module pages (Sprint 13, D-3).
 *
 * Mirror of `mountCreatorPage.ts` tuned for the agencies route table:
 *   - `/agencies/:ulid` resolves to `AgencyDetailPage` so
 *     `useRoute().params.ulid` is populated as in production.
 *   - The i18n bundle is built with the SAME `deepMergeLocale` the real
 *     app uses, so the shared `admin.*` namespace from `creators.json`
 *     and `agencies.json` both survive (a shallow spread would clobber
 *     one). Specs assert on the real strings.
 *   - A real Vuetify instance lets `v-dialog` / `v-snackbar` /
 *     `v-data-table-server` mount under JSDOM.
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
import enAuth from '@/core/i18n/locales/en/auth.json'
import enCreators from '@/core/i18n/locales/en/creators.json'
import itAgencies from '@/core/i18n/locales/it/agencies.json'
import itApp from '@/core/i18n/locales/it/app.json'
import itAuth from '@/core/i18n/locales/it/auth.json'
import itCreators from '@/core/i18n/locales/it/creators.json'
import ptAgencies from '@/core/i18n/locales/pt/agencies.json'
import ptApp from '@/core/i18n/locales/pt/app.json'
import ptAuth from '@/core/i18n/locales/pt/auth.json'
import ptCreators from '@/core/i18n/locales/pt/creators.json'

import { agenciesRoutes } from '@/modules/agencies/routes'

import type { Component } from 'vue'

export interface MountAgencyPageOptions {
  initialRoute?: RouteLocationRaw
  locale?: 'en' | 'pt' | 'it'
  props?: Record<string, unknown>
  stubs?: Record<string, Component | boolean>
}

export interface MountAgencyPageResult<T> {
  wrapper: VueWrapper<T>
  router: Router
  pinia: Pinia
  i18n: ReturnType<typeof createI18n>
  vuetify: ReturnType<typeof createVuetify>
  unmount: () => void
}

export async function mountAgencyPage<T = unknown>(
  component: Component,
  options: MountAgencyPageOptions = {},
): Promise<MountAgencyPageResult<T>> {
  const pinia = createPinia()
  setActivePinia(pinia)

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [...agenciesRoutes, { path: '/', name: 'home', component: { template: '<div/>' } }],
  })

  const vuetify = createVuetify({
    components: vuetifyComponents,
    directives: vuetifyDirectives,
  })

  const messages = {
    en: deepMergeLocale(enApp, enAuth, enCreators, enAgencies),
    pt: deepMergeLocale(ptApp, ptAuth, ptCreators, ptAgencies),
    it: deepMergeLocale(itApp, itAuth, itCreators, itAgencies),
  } as unknown as Record<'en' | 'pt' | 'it', Record<string, unknown>>

  const i18n = createI18n({
    legacy: false,
    locale: options.locale ?? 'en',
    fallbackLocale: 'en',
    availableLocales: ['en', 'pt', 'it'],
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    messages: messages as any,
  }) as unknown as ReturnType<typeof createI18n>

  const initial = options.initialRoute ?? '/agencies'
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
