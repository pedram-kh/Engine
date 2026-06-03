/**
 * Sprint 6 Chunk 2b — Vitest coverage for the talent-pool list page.
 *
 * Per the Chunk-1 jsdom/Playwright split: list LOGIC (counts-not-previews
 * D-2b-7, role-gated write affordances, the restore round-trip) is unit-tested
 * here with real (light) Vuetify; the full DOM + navigation is Playwright.
 */

import type { TalentPoolResource } from '@catalyst/api-client'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'
import { createI18n } from 'vue-i18n'

import enApp from '@/core/i18n/locales/en/app.json'
import { useAgencyStore } from '@/core/stores/useAgencyStore'

import PoolListPage from './PoolListPage.vue'

vi.mock('../api/talentPools.api', () => ({
  talentPoolsApi: {
    list: vi.fn(),
    archive: vi.fn(),
    restore: vi.fn(),
  },
}))

import { talentPoolsApi } from '../api/talentPools.api'

const localStorageStore: Record<string, string> = {}
Object.defineProperty(globalThis, 'localStorage', {
  value: {
    getItem: (k: string): string | null => localStorageStore[k] ?? null,
    setItem: (k: string, v: string): void => {
      localStorageStore[k] = v
    },
    removeItem: (k: string): void => {
      delete localStorageStore[k]
    },
  },
  writable: true,
})

function makePool(overrides: Partial<TalentPoolResource['attributes']> = {}): TalentPoolResource {
  return {
    id: '01HZA1B2C3D4E5F6G7H8J9K0M1',
    type: 'talent_pools',
    attributes: {
      name: 'Acme Q3',
      description: null,
      brand_id: null,
      brand_name: null,
      is_archived: false,
      creators_count: 12,
      created_at: '2026-06-01T10:00:00.000000Z',
      updated_at: '2026-06-01T10:00:00.000000Z',
      ...overrides,
    },
  }
}

async function mountList(
  options: {
    pools?: TalentPoolResource[]
    role?: 'agency_admin' | 'agency_manager' | 'agency_staff'
    initialFilter?: 'active' | 'archived' | 'all'
  } = {},
): Promise<{ wrapper: ReturnType<typeof mount>; cleanup: () => void }> {
  const pinia = createPinia()
  setActivePinia(pinia)

  vi.mocked(talentPoolsApi.list).mockResolvedValue({
    data: options.pools ?? [],
    meta: { total: (options.pools ?? []).length, current_page: 1, per_page: 25, last_page: 1 },
    links: { first: '', last: '', prev: null, next: null },
  } as unknown as Awaited<ReturnType<typeof talentPoolsApi.list>>)

  const agency = useAgencyStore()
  agency.initFromUser([
    {
      agency_id: 'agency-ulid',
      agency_name: 'Test Agency',
      role: options.role ?? 'agency_admin',
    },
  ])

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/talent-pools', name: 'pools.list', component: { template: '<div />' } },
      { path: '/talent-pools/new', name: 'pools.create', component: { template: '<div />' } },
      { path: '/talent-pools/:ulid', name: 'pools.detail', component: { template: '<div />' } },
      { path: '/talent-pools/:ulid/edit', name: 'pools.edit', component: { template: '<div />' } },
    ],
  })
  await router.push('/talent-pools')
  await router.isReady()

  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    availableLocales: ['en'],
    messages: { en: enApp } as never,
  }) as unknown as ReturnType<typeof createI18n>

  const vuetify = createVuetify({
    components: vuetifyComponents,
    directives: vuetifyDirectives,
  })

  const wrapper = mount(PoolListPage, {
    global: { plugins: [pinia, router, i18n, vuetify] },
    attachTo: document.createElement('div'),
  })

  if (options.initialFilter !== undefined && options.initialFilter !== 'active') {
    ;(wrapper.vm as unknown as { statusFilter: string }).statusFilter = options.initialFilter
    await flushPromises()
  }
  await flushPromises()

  return {
    wrapper,
    cleanup: () => {
      wrapper.unmount()
      Object.keys(localStorageStore).forEach((k) => delete localStorageStore[k])
    },
  }
}

describe('PoolListPage (Sprint 6 Chunk 2b)', () => {
  let cleanup: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    cleanup?.()
    cleanup = null
  })

  it('renders the membership COUNT, not member rows (D-2b-7)', async () => {
    const pool = makePool({ creators_count: 12 })
    const harness = await mountList({ pools: [pool] })
    cleanup = harness.cleanup

    expect(harness.wrapper.find(`[data-test="pool-count-${pool.id}"]`).text()).toBe('12')
  })

  it('shows the create button for admin/manager', async () => {
    const harness = await mountList({ role: 'agency_manager', pools: [makePool()] })
    cleanup = harness.cleanup
    expect(harness.wrapper.find('[data-test="pool-create-btn"]').exists()).toBe(true)
  })

  it('hides the create + edit + archive affordances for staff', async () => {
    const pool = makePool()
    const harness = await mountList({ role: 'agency_staff', pools: [pool] })
    cleanup = harness.cleanup

    expect(harness.wrapper.find('[data-test="pool-create-btn"]').exists()).toBe(false)
    expect(harness.wrapper.find(`[data-test="pool-edit-${pool.id}"]`).exists()).toBe(false)
    expect(harness.wrapper.find(`[data-test="pool-archive-${pool.id}"]`).exists()).toBe(false)
    // The read (view) affordance stays for everyone.
    expect(harness.wrapper.find(`[data-test="pool-view-${pool.id}"]`).exists()).toBe(true)
  })

  it('renders the restore button on an archived row for admin/manager', async () => {
    const pool = makePool({ is_archived: true })
    const harness = await mountList({
      role: 'agency_admin',
      pools: [pool],
      initialFilter: 'archived',
    })
    cleanup = harness.cleanup
    expect(harness.wrapper.find(`[data-test="pool-restore-${pool.id}"]`).exists()).toBe(true)
    // An archived row does not offer edit/archive.
    expect(harness.wrapper.find(`[data-test="pool-archive-${pool.id}"]`).exists()).toBe(false)
  })

  it('confirming restore calls the API and shows a success toast', async () => {
    const pool = makePool({ is_archived: true, name: 'Phoenix Pool' })
    const harness = await mountList({
      role: 'agency_admin',
      pools: [pool],
      initialFilter: 'archived',
    })
    cleanup = harness.cleanup

    vi.mocked(talentPoolsApi.restore).mockResolvedValue({
      data: { ...pool, attributes: { ...pool.attributes, is_archived: false } },
    })

    await harness.wrapper.find(`[data-test="pool-restore-${pool.id}"]`).trigger('click')
    await flushPromises()
    const confirmBtn = document.querySelector(
      '[data-test="pool-restore-dialog-confirm"]',
    ) as HTMLElement | null
    confirmBtn?.click()
    await flushPromises()

    expect(talentPoolsApi.restore).toHaveBeenCalledWith('agency-ulid', pool.id)
    const toast = document.querySelector('[data-test="pool-restore-success-toast"]')
    expect(toast?.textContent ?? '').toContain('Phoenix Pool')
  })

  it('shows the agency-wide label when a pool has no brand', async () => {
    const harness = await mountList({ pools: [makePool({ brand_name: null })] })
    cleanup = harness.cleanup
    expect(harness.wrapper.text()).toContain('Agency-wide')
  })
})
