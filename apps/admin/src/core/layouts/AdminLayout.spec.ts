/**
 * AdminLayout shell tests (Sprint 13, D-1 / D-2).
 *
 * The shell is the sub-step-1 blocker: it mounts the persistent env
 * banner, the 220px sidebar, and the declarative nav (live leaves +
 * coming-soon payment placeholders). These tests pin:
 *   - the env banner renders and reflects VITE_DEPLOY_ENV (D-2),
 *   - the sidebar + wordmark render,
 *   - live nav leaves render and coming-soon leaves carry the "soon"
 *     affordance (D-13),
 *   - badge counts surface from the nav-badge store,
 *   - the routed page renders through the default slot.
 */

import { mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'
import { createI18n } from 'vue-i18n'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

import enApp from '@/core/i18n/locales/en/app.json'
import { routes } from '@/modules/auth/routes'
import { useNavBadges } from '@/core/stores/useNavBadges'
import AdminLayout from './AdminLayout.vue'

const SlotMarker = defineComponent({
  name: 'SlotMarker',
  setup: () => () => h('div', { 'data-testid': 'slot-marker' }, 'routed-page'),
})

async function mountLayout(): Promise<VueWrapper> {
  const pinia = createPinia()
  setActivePinia(pinia)

  const router = createRouter({ history: createMemoryHistory(), routes })
  await router.push('/')
  await router.isReady()

  const vuetify = createVuetify({
    components: vuetifyComponents,
    directives: vuetifyDirectives,
  })

  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    availableLocales: ['en', 'pt', 'it'],
    messages: { en: { ...enApp }, pt: { ...enApp }, it: { ...enApp } },
  }) as unknown as ReturnType<typeof createI18n>

  return mount(AdminLayout, {
    slots: { default: () => h(SlotMarker) },
    global: { plugins: [pinia, router, i18n, vuetify] },
    attachTo: document.createElement('div'),
  }) as unknown as VueWrapper
}

describe('AdminLayout — shell (Sprint 13, D-1)', () => {
  beforeEach(() => {
    vi.unstubAllEnvs()
  })

  afterEach(() => {
    vi.unstubAllEnvs()
    document.body.innerHTML = ''
  })

  it('renders the env banner, sidebar, and routed page', async () => {
    const wrapper = await mountLayout()

    expect(wrapper.find('[data-testid="admin-env-banner"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="admin-sidebar"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="slot-marker"]').text()).toBe('routed-page')

    wrapper.unmount()
  })

  it('defaults the env banner to local when VITE_DEPLOY_ENV is unset', async () => {
    const wrapper = await mountLayout()

    expect(wrapper.find('[data-test="admin-env-banner-local"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="admin-env-banner"]').text()).toContain('Local')

    wrapper.unmount()
  })

  it('paints the production banner when VITE_DEPLOY_ENV=production', async () => {
    vi.stubEnv('VITE_DEPLOY_ENV', 'production')
    const wrapper = await mountLayout()

    expect(wrapper.find('[data-test="admin-env-banner-production"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="admin-env-banner"]').text()).toContain('Production')

    wrapper.unmount()
  })

  it('renders live nav leaves and a coming-soon affordance on payment surfaces (D-13)', async () => {
    const wrapper = await mountLayout()

    // Live top-level leaf.
    expect(wrapper.find('[data-testid="nav-agencies"]').exists()).toBe(true)
    // Live group child.
    expect(wrapper.find('[data-testid="nav-creatorApprovals"]').exists()).toBe(true)
    // Coming-soon payment child carries the "soon" chip, not a badge.
    expect(wrapper.find('[data-testid="nav-soon-disputes"]').exists()).toBe(true)

    wrapper.unmount()
  })

  it('surfaces nav badge counts from the nav-badge store', async () => {
    const wrapper = await mountLayout()
    const badges = useNavBadges()

    badges.setCounts({ creatorApprovals: 7 })
    await wrapper.vm.$nextTick()

    expect(wrapper.find('[data-testid="nav-badge-creatorApprovals"]').text()).toContain('7')

    wrapper.unmount()
  })
})
