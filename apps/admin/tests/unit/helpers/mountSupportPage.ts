/**
 * Test harness for the admin SPA's support-module pages (Sprint 13, D-9).
 *
 * Mirror of `mountAgencyPage.ts` tuned for the support route table
 * (user search + impersonation start). A real Vuetify instance lets
 * `v-dialog` / `v-snackbar` / `v-list` mount under JSDOM, and the i18n
 * bundle is built with the SAME `deepMergeLocale` the real app uses so
 * the shared `admin.*` namespace from `support.json` survives.
 */

import { mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia, type Pinia } from 'pinia'
import { createMemoryHistory, createRouter, type Router, type RouteLocationRaw } from 'vue-router'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'
import { createI18n } from 'vue-i18n'

import { deepMergeLocale } from '@/core/i18n/deepMerge'
import enApp from '@/core/i18n/locales/en/app.json'
import enAuth from '@/core/i18n/locales/en/auth.json'
import enSupport from '@/core/i18n/locales/en/support.json'
import itApp from '@/core/i18n/locales/it/app.json'
import itAuth from '@/core/i18n/locales/it/auth.json'
import itSupport from '@/core/i18n/locales/it/support.json'
import ptApp from '@/core/i18n/locales/pt/app.json'
import ptAuth from '@/core/i18n/locales/pt/auth.json'
import ptSupport from '@/core/i18n/locales/pt/support.json'

import { supportRoutes } from '@/modules/support/routes'

import type { Component } from 'vue'

export interface MountSupportPageOptions {
  initialRoute?: RouteLocationRaw
  locale?: 'en' | 'pt' | 'it'
  props?: Record<string, unknown>
  stubs?: Record<string, Component | boolean>
}

export interface MountSupportPageResult<T> {
  wrapper: VueWrapper<T>
  router: Router
  pinia: Pinia
  i18n: ReturnType<typeof createI18n>
  vuetify: ReturnType<typeof createVuetify>
  unmount: () => void
}

export async function mountSupportPage<T = unknown>(
  component: Component,
  options: MountSupportPageOptions = {},
): Promise<MountSupportPageResult<T>> {
  const pinia = createPinia()
  setActivePinia(pinia)

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [...supportRoutes, { path: '/', name: 'home', component: { template: '<div/>' } }],
  })

  const vuetify = createVuetify({
    components: vuetifyComponents,
    directives: vuetifyDirectives,
  })

  const messages = {
    en: deepMergeLocale(enApp, enAuth, enSupport),
    pt: deepMergeLocale(ptApp, ptAuth, ptSupport),
    it: deepMergeLocale(itApp, itAuth, itSupport),
  } as unknown as Record<'en' | 'pt' | 'it', Record<string, unknown>>

  const i18n = createI18n({
    legacy: false,
    locale: options.locale ?? 'en',
    fallbackLocale: 'en',
    availableLocales: ['en', 'pt', 'it'],
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    messages: messages as any,
  }) as unknown as ReturnType<typeof createI18n>

  const initial = options.initialRoute ?? '/support/users'
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
