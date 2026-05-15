/**
 * Sprint 3 Chunk 4 sub-step 6 — Vitest coverage for the brand restore
 * UI that lands on the existing BrandListPage.
 *
 * Scope focuses on the NEW surface only (restore dialog + per-row
 * button visibility + happy/error/cancel paths + success toast). The
 * existing page surfaces (list rendering, status filter, archive
 * flow, pagination) are not exercised here — they are covered at the
 * E2E layer in Sprint 2's brand-management spec and don't get
 * regression coverage in Chunk 4 to keep the new-surface tests
 * focused.
 *
 * Defense-in-depth (#40 / Sprint 2 § 5.17): the role-gated visibility
 * test pairs with the backend's `BrandPolicy::restore` Pest coverage
 * — the policy is the SOT, the UI gate is a UX nicety.
 */

import type { BrandResource } from '@catalyst/api-client'
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

import BrandListPage from './BrandListPage.vue'

vi.mock('../api/brands.api', () => ({
  brandsApi: {
    list: vi.fn(),
    restore: vi.fn(),
    archive: vi.fn(),
  },
}))

import { brandsApi } from '../api/brands.api'

// localStorage stub so useAgencyStore can persist its `currentAgencyId`
// during the test without polluting the JSDOM environment.
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

function makeBrand(overrides: Partial<BrandResource['attributes']> = {}): BrandResource {
  return {
    id: '01HZA1B2C3D4E5F6G7H8J9K0M1',
    type: 'brands',
    attributes: {
      name: 'Acme Corp',
      slug: 'acme-corp',
      description: null,
      industry: null,
      website_url: null,
      logo_path: null,
      default_currency: 'EUR',
      default_language: 'en',
      status: 'archived',
      brand_safety_rules: null,
      exclusivity_window_days: null,
      client_portal_enabled: false,
      created_at: '2026-05-15T10:00:00.000000Z',
      updated_at: '2026-05-15T10:00:00.000000Z',
      ...overrides,
    },
  } as unknown as BrandResource
}

async function mountBrandList(
  options: {
    brands?: BrandResource[]
    isAdmin?: boolean
    initialFilter?: 'active' | 'archived' | 'all'
  } = {},
): Promise<{ wrapper: ReturnType<typeof mount>; cleanup: () => void }> {
  const pinia = createPinia()
  setActivePinia(pinia)

  vi.mocked(brandsApi.list).mockResolvedValue({
    data: options.brands ?? [],
    meta: { total: (options.brands ?? []).length, current_page: 1, per_page: 25, last_page: 1 },
    links: { first: '', last: '', prev: null, next: null },
  } as unknown as Awaited<ReturnType<typeof brandsApi.list>>)

  const agency = useAgencyStore()
  agency.initFromUser([
    {
      agency_id: 'agency-ulid',
      agency_name: 'Test Agency',
      role: options.isAdmin === false ? 'agency_manager' : 'agency_admin',
    },
  ])

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'app.dashboard', component: { template: '<div />' } },
      { path: '/brands', name: 'brands.list', component: { template: '<div />' } },
      { path: '/brands/new', name: 'brands.create', component: { template: '<div />' } },
      { path: '/brands/:ulid', name: 'brands.detail', component: { template: '<div />' } },
      { path: '/brands/:ulid/edit', name: 'brands.edit', component: { template: '<div />' } },
    ],
  })
  await router.push('/brands')
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

  const wrapper = mount(BrandListPage, {
    global: {
      plugins: [pinia, router, i18n, vuetify],
    },
    attachTo: document.createElement('div'),
  })

  // Apply non-default filter AFTER mount (the page's initial fetch
  // runs `onMounted` with status='active'; specs that want to test
  // archived-row behaviour switch the filter and wait for the
  // re-fetch to settle).
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

describe('BrandListPage — restore UI (Sprint 3 Chunk 4 sub-step 6)', () => {
  let cleanup: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    cleanup?.()
    cleanup = null
  })

  it('renders the restore button on an archived row when the user is agency_admin', async () => {
    const brand = makeBrand({ status: 'archived' })
    const harness = await mountBrandList({
      brands: [brand],
      isAdmin: true,
      initialFilter: 'archived',
    })
    cleanup = harness.cleanup
    expect(harness.wrapper.find(`[data-test="brand-restore-${brand.id}"]`).exists()).toBe(true)
  })

  it('does NOT render the restore button when the user is NOT agency_admin (manager)', async () => {
    const brand = makeBrand({ status: 'archived' })
    const harness = await mountBrandList({
      brands: [brand],
      isAdmin: false,
      initialFilter: 'archived',
    })
    cleanup = harness.cleanup
    expect(harness.wrapper.find(`[data-test="brand-restore-${brand.id}"]`).exists()).toBe(false)
  })

  it('does NOT render the restore button on active rows even for admins (only archived rows get one)', async () => {
    const brand = makeBrand({ status: 'active' })
    const harness = await mountBrandList({
      brands: [brand],
      isAdmin: true,
      initialFilter: 'all',
    })
    cleanup = harness.cleanup
    expect(harness.wrapper.find(`[data-test="brand-restore-${brand.id}"]`).exists()).toBe(false)
  })

  it('opens the restore confirmation dialog when the restore button is clicked', async () => {
    const brand = makeBrand({ status: 'archived', name: 'Phoenix Co' })
    const harness = await mountBrandList({
      brands: [brand],
      isAdmin: true,
      initialFilter: 'archived',
    })
    cleanup = harness.cleanup
    await harness.wrapper.find(`[data-test="brand-restore-${brand.id}"]`).trigger('click')
    await flushPromises()
    const title = document.querySelector('[data-test="restore-dialog-title"]')
    const message = document.querySelector('[data-test="restore-dialog-message"]')
    expect(title?.textContent).toContain('Restore brand')
    expect(message?.textContent).toContain('Phoenix Co')
  })

  it('cancelling the dialog does NOT call the restore API', async () => {
    const brand = makeBrand({ status: 'archived' })
    const harness = await mountBrandList({
      brands: [brand],
      isAdmin: true,
      initialFilter: 'archived',
    })
    cleanup = harness.cleanup
    await harness.wrapper.find(`[data-test="brand-restore-${brand.id}"]`).trigger('click')
    await flushPromises()
    const cancelBtn = document.querySelector(
      '[data-test="restore-dialog-cancel"]',
    ) as HTMLElement | null
    cancelBtn?.click()
    await flushPromises()
    expect(brandsApi.restore).not.toHaveBeenCalled()
  })

  it('happy path: confirming restore calls the API and triggers a list refresh + success toast', async () => {
    const brand = makeBrand({ status: 'archived', name: 'Phoenix Co' })
    const harness = await mountBrandList({
      brands: [brand],
      isAdmin: true,
      initialFilter: 'archived',
    })
    cleanup = harness.cleanup

    vi.mocked(brandsApi.restore).mockResolvedValue({
      data: { ...brand, attributes: { ...brand.attributes, status: 'active' } } as BrandResource,
    })

    await harness.wrapper.find(`[data-test="brand-restore-${brand.id}"]`).trigger('click')
    await flushPromises()
    // Snapshot the list-call count BEFORE confirming so the assertion
    // is robust to bootstrap-triggered refetches (filter watcher,
    // currentAgencyId watcher, etc.).
    const listCallsBefore = vi.mocked(brandsApi.list).mock.calls.length
    const confirmBtn = document.querySelector(
      '[data-test="restore-dialog-confirm"]',
    ) as HTMLElement | null
    confirmBtn?.click()
    await flushPromises()

    expect(brandsApi.restore).toHaveBeenCalledWith('agency-ulid', brand.id)
    // Confirming should kick exactly one additional list refresh.
    expect(vi.mocked(brandsApi.list).mock.calls.length).toBe(listCallsBefore + 1)
    // Success toast bound to the snackbar.
    const toast = document.querySelector('[data-test="restore-success-toast"]')
    expect(toast?.textContent ?? '').toContain('Phoenix Co')
  })

  it('error path: surfaces the localized error in the dialog when the API rejects', async () => {
    const brand = makeBrand({ status: 'archived' })
    const harness = await mountBrandList({
      brands: [brand],
      isAdmin: true,
      initialFilter: 'archived',
    })
    cleanup = harness.cleanup

    vi.mocked(brandsApi.restore).mockRejectedValue(new Error('500'))

    await harness.wrapper.find(`[data-test="brand-restore-${brand.id}"]`).trigger('click')
    await flushPromises()
    const confirmBtn = document.querySelector(
      '[data-test="restore-dialog-confirm"]',
    ) as HTMLElement | null
    confirmBtn?.click()
    await flushPromises()

    const errorAlert = document.querySelector('[data-test="restore-dialog-error"]')
    expect(errorAlert?.textContent).toContain('Failed to restore brand')
    // Dialog stays open so the user can retry.
    expect(document.querySelector('[data-test="restore-dialog-title"]')).not.toBeNull()
  })
})
