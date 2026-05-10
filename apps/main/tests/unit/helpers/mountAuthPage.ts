/**
 * Test harness for chunk 6.6 / 6.7 component tests.
 *
 * Mounts an auth page with:
 *   - A fresh Pinia (so each test starts with a clean store).
 *   - The full i18n bundle (so we render the same strings the user
 *     sees in production — no mock translation table).
 *   - A real Vue Router instance (memory history) carrying the
 *     chunk-6.5 route table — so `useRoute()` / `useRouter()` resolve
 *     to real records and `router.push({ name: 'auth.sign-in' })`
 *     does what production does.
 *   - A real Vuetify instance (so `<v-text-field>` etc. render).
 *
 * The store and router are exposed on the returned harness so tests
 * can stub action returns / inspect navigation calls. The mock for
 * `@/modules/auth/api/auth.api` is set up by individual specs via
 * `vi.mock(...)` — this helper does not enforce a particular API
 * mocking strategy.
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
import ptApp from '@/core/i18n/locales/pt/app.json'
import ptAuth from '@/core/i18n/locales/pt/auth.json'
import itApp from '@/core/i18n/locales/it/app.json'
import itAuth from '@/core/i18n/locales/it/auth.json'

import { routes } from '@/modules/auth/routes'

import type { Component } from 'vue'

export interface MountAuthPageOptions {
  /**
   * Initial route. Defaults to `/`. Pass a name + query for the
   * `?reason=session_expired` and `?token=...&email=...` flows.
   */
  initialRoute?: RouteLocationRaw
  /**
   * Initial locale. Defaults to `en`. Pass `'pt'` / `'it'` to verify
   * the same code paths render the matching translation.
   */
  locale?: 'en' | 'pt' | 'it'
  /**
   * Extra props for the page component.
   */
  props?: Record<string, unknown>
  /**
   * Extra global plugins to install (rare).
   */
  extraPlugins?: ReadonlyArray<unknown>
  /**
   * Extra component stubs. Defaults stub out `RouterLink` so tests
   * can assert on its `to` prop without rendering a real link.
   */
  stubs?: Record<string, Component | boolean>
}

export interface MountAuthPageResult<T> {
  wrapper: VueWrapper<T>
  router: Router
  pinia: Pinia
  i18n: ReturnType<typeof createI18n>
  vuetify: ReturnType<typeof createVuetify>
  /**
   * Tear down the harness (pinia + router unmount). Always call this
   * in an `afterEach` to avoid cross-test bleed.
   */
  unmount: () => void
}

export async function mountAuthPage<T = unknown>(
  component: Component,
  options: MountAuthPageOptions = {},
): Promise<MountAuthPageResult<T>> {
  const pinia = createPinia()
  setActivePinia(pinia)

  const router = createRouter({
    history: createMemoryHistory(),
    routes,
  })

  const vuetify = createVuetify({
    components: vuetifyComponents,
    directives: vuetifyDirectives,
  })

  const messages = {
    en: { ...enApp, ...enAuth },
    pt: { ...ptApp, ...ptAuth },
    it: { ...itApp, ...itAuth },
  }

  // We cast to a loose type so the helper plugs into mount() without
  // pulling the SPA's full message-schema generic chain into every
  // spec file. The schema-checked instance lives in
  // `apps/main/src/core/i18n/index.ts`; this helper deliberately
  // mirrors its messages but stays untyped so the cast at the
  // mount() boundary is the only TypeScript-aware place.
  const i18n = createI18n({
    legacy: false,
    locale: options.locale ?? 'en',
    fallbackLocale: 'en',
    availableLocales: ['en', 'pt', 'it'],
    messages,
  }) as unknown as ReturnType<typeof createI18n>

  // Navigate FIRST so `useRoute()` reads the right path/query when the
  // component mounts and `onMounted` hooks run. Vue-router's `push()`
  // is async even in memory mode — without awaiting it the route
  // would still be the default `/` when the component evaluates
  // `route.query`.
  const initial = options.initialRoute ?? '/'
  try {
    await router.push(initial)
  } catch {
    // Memory history may emit a duplicated-navigation warning when
    // the initial route resolves to the same path; safe to swallow.
  }
  await router.isReady()

  const wrapper = mount(component, {
    props: options.props ?? {},
    global: {
      plugins: [
        pinia,
        router,
        i18n,
        vuetify,
        ...((options.extraPlugins ?? []) as unknown as Array<never>),
      ],
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

/**
 * Convenience: navigate the test router to a new route and wait for
 * navigation guards to settle. Useful when a component triggers a
 * `router.push` mid-test and the next assertion depends on the
 * `currentRoute` having updated.
 */
export async function navigateAndSettle(router: Router, to: RouteLocationRaw): Promise<void> {
  await router.push(to)
  await router.isReady()
}
