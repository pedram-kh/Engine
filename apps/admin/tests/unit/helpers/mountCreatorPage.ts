/**
 * Test harness for the admin SPA's creators-module pages — Sprint 3
 * Chunk 4 sub-step 9 (per-field edit modals + EditFieldRow integration).
 *
 * Mirror of `mountAuthPage.ts` (sub-chunk 7.5) tuned for the creators
 * route table:
 *   - `/creators/:ulid` resolves to the `CreatorDetailPage` so
 *     `useRoute().params.ulid` is populated as in production.
 *   - The admin i18n bundle pulls in the `creators.json` namespace
 *     (which carries the new `admin.creators.detail.fields.*` and
 *     `admin.creators.detail.edit.*` keys added in this sub-step) so
 *     specs assert on the real strings, not stubs.
 *   - A real Vuetify instance + the visualViewport polyfill (in
 *     `tests/unit/setup.ts`) let `v-dialog` and `v-snackbar` mount
 *     under JSDOM without `ReferenceError: visualViewport is not
 *     defined`.
 */

import { mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia, type Pinia } from 'pinia'
import { createMemoryHistory, createRouter, type Router, type RouteLocationRaw } from 'vue-router'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'
import { createI18n } from 'vue-i18n'

import enApp from '@/core/i18n/locales/en/app.json'
import enAuth from '@/core/i18n/locales/en/auth.json'
import enCreators from '@/core/i18n/locales/en/creators.json'
import ptApp from '@/core/i18n/locales/pt/app.json'
import ptAuth from '@/core/i18n/locales/pt/auth.json'
import ptCreators from '@/core/i18n/locales/pt/creators.json'
import itApp from '@/core/i18n/locales/it/app.json'
import itAuth from '@/core/i18n/locales/it/auth.json'
import itCreators from '@/core/i18n/locales/it/creators.json'

import { creatorsRoutes } from '@/modules/creators/routes'

import type { Component } from 'vue'

export interface MountCreatorPageOptions {
  initialRoute?: RouteLocationRaw
  locale?: 'en' | 'pt' | 'it'
  props?: Record<string, unknown>
  stubs?: Record<string, Component | boolean>
}

export interface MountCreatorPageResult<T> {
  wrapper: VueWrapper<T>
  router: Router
  pinia: Pinia
  i18n: ReturnType<typeof createI18n>
  vuetify: ReturnType<typeof createVuetify>
  unmount: () => void
}

export async function mountCreatorPage<T = unknown>(
  component: Component,
  options: MountCreatorPageOptions = {},
): Promise<MountCreatorPageResult<T>> {
  const pinia = createPinia()
  setActivePinia(pinia)

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [...creatorsRoutes, { path: '/', name: 'home', component: { template: '<div/>' } }],
  })

  const vuetify = createVuetify({
    components: vuetifyComponents,
    directives: vuetifyDirectives,
  })

  const messages = {
    en: { ...enApp, ...enAuth, ...enCreators },
    pt: { ...ptApp, ...ptAuth, ...ptCreators },
    it: { ...itApp, ...itAuth, ...itCreators },
  }

  const i18n = createI18n({
    legacy: false,
    locale: options.locale ?? 'en',
    fallbackLocale: 'en',
    availableLocales: ['en', 'pt', 'it'],
    messages,
  }) as unknown as ReturnType<typeof createI18n>

  const initial = options.initialRoute ?? '/creators/01HQABCD'
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
