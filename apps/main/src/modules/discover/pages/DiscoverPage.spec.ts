/**
 * Sprint 6.6a — Vitest coverage for the creator discovery page (the global
 * pool browse). Covers: agency-scoped load, card rendering with the
 * calling-agency-only connection annotation, the filter + debounced search
 * threading, the two empty states, the error path, and card-click navigation
 * to the public profile.
 *
 * The heavy Vuetify selects/text-field are stubbed (the specs drive the refs
 * directly); the card grid renders for real so the connection annotation DOM
 * is asserted. Each mount builds its own Vuetify + i18n + pinia + router.
 */

import type { DiscoveryCreatorListItem } from '@catalyst/api-client'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'
import { createI18n } from 'vue-i18n'

import enApp from '@/core/i18n/locales/en/app.json'
import enCreator from '@/core/i18n/locales/en/creator.json'
import { useAgencyStore } from '@/core/stores/useAgencyStore'

import DiscoverPage from './DiscoverPage.vue'

vi.mock('../api/discovery.api', () => ({
  discoveryApi: { list: vi.fn() },
}))

import { discoveryApi } from '../api/discovery.api'

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

function makeCard(
  overrides: Partial<DiscoveryCreatorListItem['attributes']> = {},
  id = '01HZA1B2C3D4E5F6G7H8J9K0M1',
): DiscoveryCreatorListItem {
  return {
    id,
    type: 'creator_discovery',
    attributes: {
      display_name: 'Ada Lovelace',
      country_code: 'GB',
      primary_language: 'en',
      categories: ['tech'],
      avatar_url: null,
      is_connected: false,
      relationship_status: null,
      ...overrides,
    },
  }
}

async function mountDiscover(
  options: {
    cards?: DiscoveryCreatorListItem[]
    agencyId?: string | null
    reject?: boolean
    lastPage?: number
  } = {},
): Promise<{
  wrapper: ReturnType<typeof mount>
  router: ReturnType<typeof createRouter>
  cleanup: () => void
}> {
  const pinia = createPinia()
  setActivePinia(pinia)

  if (options.reject === true) {
    vi.mocked(discoveryApi.list).mockRejectedValue(new Error('500'))
  } else {
    const cards = options.cards ?? []
    vi.mocked(discoveryApi.list).mockResolvedValue({
      data: cards,
      meta: { total: cards.length, page: 1, per_page: 24, last_page: options.lastPage ?? 1 },
    })
  }

  const agency = useAgencyStore()
  if (options.agencyId !== null) {
    agency.initFromUser([
      {
        agency_id: options.agencyId ?? 'agency-ulid',
        agency_name: 'Test Agency',
        role: 'agency_admin',
      },
    ])
  }

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'app.dashboard', component: { template: '<div />' } },
      { path: '/discover', name: 'discover.list', component: { template: '<div />' } },
      { path: '/discover/:ulid', name: 'discover.detail', component: { template: '<div />' } },
    ],
  })
  await router.push('/discover')
  await router.isReady()

  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    availableLocales: ['en'],
    messages: { en: { ...enApp, ...enCreator } } as never,
  }) as unknown as ReturnType<typeof createI18n>

  const vuetify = createVuetify({
    components: vuetifyComponents,
    directives: vuetifyDirectives,
  })

  const wrapper = mount(DiscoverPage, {
    global: {
      plugins: [pinia, router, i18n, vuetify],
      stubs: { VSelect: true, VTextField: true, VImg: true },
    },
    attachTo: document.createElement('div'),
  })

  await flushPromises()

  return {
    wrapper,
    router,
    cleanup: () => {
      wrapper.unmount()
      Object.keys(localStorageStore).forEach((k) => delete localStorageStore[k])
    },
  }
}

describe('DiscoverPage (Sprint 6.6a)', () => {
  let cleanup: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    cleanup?.()
    cleanup = null
  })

  it('loads scoped to the current agency and renders cards with the connection annotation', async () => {
    const connected = makeCard(
      { display_name: 'Connected Cara', is_connected: true, relationship_status: 'roster' },
      '01CONNECTEDXXXXXXXXXXXXXXXX',
    )
    const stranger = makeCard(
      { display_name: 'Stranger Sam', is_connected: false, relationship_status: null },
      '01STRANGERXXXXXXXXXXXXXXXXX',
    )
    const harness = await mountDiscover({ cards: [connected, stranger], agencyId: 'agency-xyz' })
    cleanup = harness.cleanup

    expect(vi.mocked(discoveryApi.list).mock.calls[0]?.[0]).toBe('agency-xyz')

    // The connected card shows its (calling-agency-only) status chip.
    expect(
      harness.wrapper.find(`[data-test="discover-connected-${connected.id}"]`).text(),
    ).toContain('Roster')
    // The stranger card shows the not-connected affordance, not a status chip.
    expect(harness.wrapper.find(`[data-test="discover-connected-${stranger.id}"]`).exists()).toBe(
      false,
    )
    expect(
      harness.wrapper.find(`[data-test="discover-notconnected-${stranger.id}"]`).exists(),
    ).toBe(true)
  })

  it('does NOT call the API when there is no current agency', async () => {
    const harness = await mountDiscover({ agencyId: null })
    cleanup = harness.cleanup
    expect(discoveryApi.list).not.toHaveBeenCalled()
  })

  it('re-queries with each structured filter', async () => {
    const harness = await mountDiscover({ cards: [makeCard()] })
    cleanup = harness.cleanup
    const vm = harness.wrapper.vm as unknown as {
      countryFilter: string | null
      languageFilter: string | null
      categoryFilter: string | null
    }

    vi.mocked(discoveryApi.list).mockClear()
    vm.countryFilter = 'PT'
    vm.languageFilter = 'it'
    vm.categoryFilter = 'fitness'
    await flushPromises()
    expect(vi.mocked(discoveryApi.list).mock.calls.at(-1)?.[1]).toMatchObject({
      country: 'PT',
      language: 'it',
      category: 'fitness',
    })
  })

  it('debounces the search box and threads the trimmed q (reused Chunk-1 FTS)', async () => {
    const harness = await mountDiscover({ cards: [makeCard()] })
    cleanup = harness.cleanup
    const vm = harness.wrapper.vm as unknown as { searchQuery: string }

    vi.useFakeTimers()
    try {
      vi.mocked(discoveryApi.list).mockClear()
      vm.searchQuery = '  ada  '
      await harness.wrapper.vm.$nextTick()

      await vi.advanceTimersByTimeAsync(299)
      expect(discoveryApi.list).not.toHaveBeenCalled()

      await vi.advanceTimersByTimeAsync(1)
      expect(discoveryApi.list).toHaveBeenCalledTimes(1)
      expect(vi.mocked(discoveryApi.list).mock.calls.at(-1)?.[1]).toMatchObject({ q: 'ada' })
    } finally {
      vi.useRealTimers()
    }
  })

  it('shows the empty and no-match states', async () => {
    const harness = await mountDiscover({ cards: [] })
    cleanup = harness.cleanup

    expect(harness.wrapper.find('[data-test="discover-empty-state"]').exists()).toBe(true)
    expect(harness.wrapper.find('[data-test="discover-empty-filtered"]').exists()).toBe(false)
    ;(harness.wrapper.vm as unknown as { countryFilter: string | null }).countryFilter = 'PT'
    await flushPromises()
    expect(harness.wrapper.find('[data-test="discover-empty-filtered"]').exists()).toBe(true)
    expect(harness.wrapper.find('[data-test="discover-empty-state"]').exists()).toBe(false)
  })

  it('surfaces a localized error when the API rejects', async () => {
    const harness = await mountDiscover({ reject: true })
    cleanup = harness.cleanup
    expect(harness.wrapper.find('[data-test="discover-error"]').text()).toContain("Couldn't load")
  })

  it('navigates to the public profile on a card click', async () => {
    const card = makeCard({}, '01CARDCLICKXXXXXXXXXXXXXXXX')
    const harness = await mountDiscover({ cards: [card] })
    cleanup = harness.cleanup
    const pushSpy = vi.spyOn(harness.router, 'push')

    await harness.wrapper.find(`[data-test="discover-card-${card.id}"]`).trigger('click')
    await flushPromises()

    expect(pushSpy).toHaveBeenCalledWith({ name: 'discover.detail', params: { ulid: card.id } })
  })
})
