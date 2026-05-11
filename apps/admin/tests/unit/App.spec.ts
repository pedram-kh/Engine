/**
 * Tests for the chunk-7.4 minimal-shell `App.vue`.
 *
 * Sub-chunk 7.4 ships the router infrastructure but not the auth
 * pages or the `AuthLayout`. `App.vue` is therefore the bare
 * `<v-app><v-main><router-view /></v-main></v-app>` frame: it lets the
 * router resolve and render the placeholder pages without the
 * `meta.layout`-driven switching main's `App.vue` performs (chunk 6.8).
 * Sub-chunk 7.5 will expand this into a layout switcher matching main;
 * those tests land then.
 *
 * The test under this contract is narrow on purpose: mount `App` with a
 * memory-history router carrying a single stub route, and confirm that
 * (a) the routed component renders inside `App`, and (b) the `v-app`
 * Vuetify wrapper is present so theme/scoping still works.
 */

import { describe, expect, it } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createPinia } from 'pinia'
import { createVuetify } from 'vuetify'
import { createMemoryHistory, createRouter, type RouteRecordRaw } from 'vue-router'
import { defineComponent, h } from 'vue'

import App from '@/App.vue'

const RouteMarker = defineComponent({
  name: 'RouteMarker',
  setup() {
    return () => h('div', { 'data-test': 'route-page' }, 'route-marker')
  },
})

const TEST_ROUTES: RouteRecordRaw[] = [{ path: '/', name: 'test.root', component: RouteMarker }]

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
        },
      },
    },
  })
}

async function mountApp() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: TEST_ROUTES,
  })
  await router.push('/')
  await router.isReady()

  const wrapper = mount(App, {
    global: {
      plugins: [createPinia(), createI18nForApp(), createVuetify(), router],
    },
  })
  await flushPromises()

  return { wrapper, router }
}

describe('App.vue — minimal router-view shell (chunk 7.4)', () => {
  it('renders the routed component inside the v-app shell', async () => {
    const { wrapper } = await mountApp()

    expect(wrapper.find('[data-test="route-page"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('route-marker')
  })

  it('mounts a Vuetify v-app wrapper', async () => {
    const { wrapper } = await mountApp()

    expect(wrapper.find('.v-application').exists()).toBe(true)
  })
})
