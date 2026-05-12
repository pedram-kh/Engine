import { describe, expect, it } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createPinia } from 'pinia'
import { createVuetify } from 'vuetify'
import { createMemoryHistory, createRouter, type Router, type RouteRecordRaw } from 'vue-router'
import { defineComponent, h } from 'vue'

import App from '@/App.vue'

// Three lightweight `defineComponent` stubs follow; the lint rule
// `vue/one-component-per-file` does not apply to a test file whose
// purpose is exactly to exercise a layout switcher against multiple
// route targets. Disabling is scoped to this file rather than turned
// off globally.
/* eslint-disable vue/one-component-per-file */

/**
 * Tests for the chunk-7.5 layout-switcher refactor of `App.vue`.
 *
 * Mirror of `apps/main/tests/unit/App.spec.ts` (chunk 6.8). `App.vue`
 * is a thin shell: it picks `AuthLayout` for `meta.layout` values
 * `'auth'` and `'error'` and a bare `<v-app><v-main>` shell otherwise.
 * The contract under test is exactly that switch — the layout-internal
 * markup is covered by `AuthLayout`'s own architecture test
 * (`auth-layout-shape.spec.ts`).
 *
 * The test mounts `App` with a memory-history router so navigation is
 * synchronous and JSDOM-compatible. It uses synthetic component stubs
 * for the routed pages so the test does not pull in the full auth-page
 * dependency graph just to verify a layout switch.
 */

const AuthMarker = defineComponent({
  name: 'AuthMarker',
  setup() {
    return () => h('div', { 'data-test': 'route-page-auth' }, 'auth-marker')
  },
})

const AppMarker = defineComponent({
  name: 'AppMarker',
  setup() {
    return () => h('div', { 'data-test': 'route-page-app' }, 'app-marker')
  },
})

const ErrorMarker = defineComponent({
  name: 'ErrorMarker',
  setup() {
    return () => h('div', { 'data-test': 'route-page-error' }, 'error-marker')
  },
})

const TEST_ROUTES: RouteRecordRaw[] = [
  { path: '/auth-route', name: 'test.auth', component: AuthMarker, meta: { layout: 'auth' } },
  { path: '/app-route', name: 'test.app', component: AppMarker, meta: { layout: 'app' } },
  { path: '/error-route', name: 'test.error', component: ErrorMarker, meta: { layout: 'error' } },
  // Route without `meta.layout` — falls through to the `'app'` default.
  { path: '/no-meta', name: 'test.no-meta', component: AppMarker },
]

function createI18nForApp() {
  return createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    availableLocales: ['en'],
    messages: {
      en: {
        app: {
          title: 'Catalyst Engine — Admin',
          locale: { switcher: 'Language', en: 'English' },
        },
      },
    },
  })
}

async function mountWithRoute(
  initialPath: string,
): Promise<{ wrapper: ReturnType<typeof mount>; router: Router }> {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: TEST_ROUTES,
  })
  await router.push(initialPath)
  await router.isReady()

  const wrapper = mount(App, {
    global: {
      plugins: [createPinia(), createI18nForApp(), createVuetify(), router],
    },
  })
  await flushPromises()

  return { wrapper, router }
}

describe('App.vue — layout switcher (admin, chunk 7.5)', () => {
  it("renders AuthLayout for routes with meta.layout === 'auth'", async () => {
    const { wrapper } = await mountWithRoute('/auth-route')

    expect(wrapper.find('[data-test="auth-brand"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="auth-card"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="route-page-auth"]').exists()).toBe(true)
  })

  it("renders AuthLayout for routes with meta.layout === 'error'", async () => {
    const { wrapper } = await mountWithRoute('/error-route')

    expect(wrapper.find('[data-test="auth-brand"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="auth-card"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="route-page-error"]').exists()).toBe(true)
  })

  it("renders the bare app shell for routes with meta.layout === 'app'", async () => {
    const { wrapper } = await mountWithRoute('/app-route')

    expect(wrapper.find('[data-test="auth-brand"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="auth-card"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="route-page-app"]').exists()).toBe(true)
  })

  it("renders the bare app shell when meta.layout is undefined (falls through to 'app')", async () => {
    const { wrapper } = await mountWithRoute('/no-meta')

    expect(wrapper.find('[data-test="auth-brand"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="route-page-app"]').exists()).toBe(true)
  })
})
