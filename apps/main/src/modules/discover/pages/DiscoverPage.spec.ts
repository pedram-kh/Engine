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
import pageSource from './DiscoverPage.vue?raw'

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
      age: 36,
      primary_language: 'en',
      accent: null,
      content_companions: null,
      categories: ['tech'],
      avatar_url: null,
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
    /** `meta.total` (defaults to the number of cards). */
    total?: number
    /** The URL the page mounts on — the browse context lives in its query. */
    url?: string
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
      meta: {
        total: options.total ?? cards.length,
        page: 1,
        per_page: 24,
        last_page: options.lastPage ?? 1,
      },
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
  await router.push(options.url ?? '/discover')
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

  it('loads scoped to the current agency and renders cards with the three-state connection annotation', async () => {
    const connected = makeCard(
      { display_name: 'Connected Cara', relationship_status: 'roster' },
      '01CONNECTEDXXXXXXXXXXXXXXXX',
    )
    const pending = makeCard(
      { display_name: 'Pending Pat', relationship_status: 'pending_request' },
      '01PENDINGXXXXXXXXXXXXXXXXXX',
    )
    const declined = makeCard(
      { display_name: 'Declined Dan', relationship_status: 'declined' },
      '01DECLINEDXXXXXXXXXXXXXXXXX',
    )
    const ended = makeCard(
      { display_name: 'Ended Ellie', relationship_status: 'ended' },
      '01ENDEDXXXXXXXXXXXXXXXXXXXX',
    )
    const stranger = makeCard(
      { display_name: 'Stranger Sam', relationship_status: null },
      '01STRANGERXXXXXXXXXXXXXXXXX',
    )
    const harness = await mountDiscover({
      cards: [connected, pending, declined, ended, stranger],
      agencyId: 'agency-xyz',
    })
    cleanup = harness.cleanup

    expect(vi.mocked(discoveryApi.list).mock.calls[0]?.[0]).toBe('agency-xyz')

    // roster → "Connected"; pending_request → "Request pending"; declined →
    // "Declined" (D-5/D-11: keyed on the status, not a boolean).
    expect(
      harness.wrapper.find(`[data-test="discover-connection-connected-${connected.id}"]`).text(),
    ).toContain('Connected')
    expect(
      harness.wrapper.find(`[data-test="discover-connection-pending-${pending.id}"]`).text(),
    ).toContain('Request pending')
    expect(
      harness.wrapper.find(`[data-test="discover-connection-declined-${declined.id}"]`).text(),
    ).toContain('Declined')
    // AH-051 (D-3): `ended` reads as a TRUTHFUL "Previously connected" chip —
    // NOT the not-connected empty state (it must not derive to `none`).
    expect(
      harness.wrapper.find(`[data-test="discover-connection-ended-${ended.id}"]`).text(),
    ).toContain('Previously connected')
    expect(harness.wrapper.find(`[data-test="discover-notconnected-${ended.id}"]`).exists()).toBe(
      false,
    )
    // The stranger card shows the not-connected affordance, no status chip.
    expect(
      harness.wrapper.find(`[data-test="discover-connection-connected-${stranger.id}"]`).exists(),
    ).toBe(false)
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

  it('resumes the browse context held in the URL instead of restarting at page 1', async () => {
    const harness = await mountDiscover({
      cards: [makeCard()],
      url: '/discover?page=3&q=ada&country=PT&category=tech',
    })
    cleanup = harness.cleanup

    expect(vi.mocked(discoveryApi.list).mock.calls[0]?.[1]).toMatchObject({
      page: 3,
      q: 'ada',
      country: 'PT',
      category: 'tech',
    })
  })

  it('ignores a hand-edited URL that names values the filters do not offer', async () => {
    const harness = await mountDiscover({
      cards: [makeCard()],
      url: '/discover?page=zero&country=ZZ&category=nonsense',
    })
    cleanup = harness.cleanup

    const params = vi.mocked(discoveryApi.list).mock.calls[0]?.[1]
    expect(params).toMatchObject({ page: 1 })
    expect(params).not.toHaveProperty('country')
    expect(params).not.toHaveProperty('category')
  })

  it('writes the page into the URL so the profile can be left and re-entered here', async () => {
    const harness = await mountDiscover({ cards: [makeCard()], lastPage: 5 })
    cleanup = harness.cleanup
    const replaceSpy = vi.spyOn(harness.router, 'replace')
    ;(harness.wrapper.vm as unknown as { page: number }).page = 3
    await flushPromises()

    expect(harness.router.currentRoute.value.query.page).toBe('3')
    // Replace, not push: paging must not stack history entries.
    expect(replaceSpy).toHaveBeenCalled()
  })

  it('re-queries without q when the search box is cleared (Vuetify writes null, not "")', async () => {
    const harness = await mountDiscover({ cards: [makeCard()], url: '/discover?q=ada' })
    cleanup = harness.cleanup

    vi.useFakeTimers()
    try {
      vi.mocked(discoveryApi.list).mockClear()
      ;(harness.wrapper.vm as unknown as { searchQuery: string | null }).searchQuery = null
      await harness.wrapper.vm.$nextTick()
      await vi.advanceTimersByTimeAsync(300)

      expect(vi.mocked(discoveryApi.list).mock.calls.at(-1)?.[1]).not.toHaveProperty('q')
    } finally {
      vi.useRealTimers()
    }
    expect(harness.wrapper.find('[data-test="discover-error"]').exists()).toBe(false)
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

// AH-114 S3.3 — the agency restyle of Discover: header, filter pills, result count, four-column
// grid of agency cards, and the Previous / Next footer. The anchors the older tests use
// (`discover-card-*`, `discover-connection-*`, `discover-notconnected-*`, the empty states and
// the error) are unchanged; what moved is named below.
describe('DiscoverPage — agency restyle (AH-114, S3.3)', () => {
  let cleanup: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    cleanup?.()
    cleanup = null
    document.body.innerHTML = ''
  })

  const text = (wrapper: ReturnType<typeof mount>, test: string) =>
    wrapper.find(`[data-test="${test}"]`).text()

  it('header: the title and subtitle, no actions', async () => {
    const harness = await mountDiscover({ cards: [makeCard()] })
    cleanup = harness.cleanup

    expect(text(harness.wrapper, 'discover-heading')).toBe('Discover creators')
    expect(harness.wrapper.find('.agency-page-header__subtitle').text()).toBe(
      'Browse and search the global creator pool.',
    )
    expect(harness.wrapper.find('.agency-page-header__actions').exists()).toBe(false)
  })

  it('result count: the pool total, plural-aware', async () => {
    const many = await mountDiscover({ cards: [makeCard()], total: 412 })
    expect(text(many.wrapper, 'discover-result-count')).toBe('412 creators')
    many.cleanup()

    const one = await mountDiscover({ cards: [makeCard()], total: 1 })
    cleanup = one.cleanup
    expect(text(one.wrapper, 'discover-result-count')).toBe('1 creator')
  })

  it('result count: not drawn after a failed load (no zero, no stale number)', async () => {
    const harness = await mountDiscover({ reject: true })
    cleanup = harness.cleanup

    expect(harness.wrapper.find('[data-test="discover-result-count"]').exists()).toBe(false)
  })

  it('three filter pills, and picking one re-queries with it; picking it again re-queries without', async () => {
    const harness = await mountDiscover({ cards: [makeCard()], total: 5 })
    cleanup = harness.cleanup
    expect(harness.wrapper.findAll('.agency-filter-pill')).toHaveLength(3)

    await harness.wrapper
      .get('[data-test="discover-country-filter"] [data-test="agency-filter-pill-button"]')
      .trigger('click')
    await flushPromises()
    vi.mocked(discoveryApi.list).mockClear()
    document.querySelector<HTMLElement>('[data-test="agency-filter-pill-option"]')?.click()
    await flushPromises()

    const country = vi.mocked(discoveryApi.list).mock.calls.at(-1)?.[1]?.country
    expect(typeof country).toBe('string')
    expect(harness.wrapper.get('[data-test="discover-country-filter"]').classes()).toContain(
      'agency-filter-pill--applied',
    )

    vi.mocked(discoveryApi.list).mockClear()
    await harness.wrapper.get('[data-test="agency-filter-pill-clear"]').trigger('click')
    await flushPromises()

    expect(vi.mocked(discoveryApi.list).mock.calls.at(-1)?.[1]).not.toHaveProperty('country')
  })

  it('draws one agency card per creator in the grid', async () => {
    const cards = [
      makeCard({}, '01AAAAAAAAAAAAAAAAAAAAAAAA'),
      makeCard({}, '01BBBBBBBBBBBBBBBBBBBBBBBB'),
    ]
    const harness = await mountDiscover({ cards })
    cleanup = harness.cleanup

    const grid = harness.wrapper.get('[data-test="discover-grid"]')
    expect(grid.findAll('.agency-creator-card')).toHaveLength(2)
    expect(grid.get('[data-test="discover-name-01AAAAAAAAAAAAAAAAAAAAAAAA"]').text()).toBe(
      'Ada Lovelace',
    )
  })

  it('a card shows the country, age, language and category the creator has', async () => {
    const harness = await mountDiscover({
      cards: [
        makeCard({ categories: ['tech', 'fitness', 'travel'] }, '01AAAAAAAAAAAAAAAAAAAAAAAA'),
      ],
    })
    cleanup = harness.cleanup

    const card = harness.wrapper.get('[data-test="discover-card-01AAAAAAAAAAAAAAAAAAAAAAAA"]')
    expect(card.findAll('.agency-creator-card__line')[0]?.text()).toContain('36')
    expect(card.findAll('.agency-creator-card__chip')).toHaveLength(3)
    expect(card.get('[data-test="discover-cats-more-01AAAAAAAAAAAAAAAAAAAAAAAA"]').text()).toBe(
      '+1',
    )
  })

  it('Enter on a card opens the profile', async () => {
    const card = makeCard({}, '01CARDKEYXXXXXXXXXXXXXXXXX')
    const harness = await mountDiscover({ cards: [card] })
    cleanup = harness.cleanup
    const pushSpy = vi.spyOn(harness.router, 'push')

    await harness.wrapper.get(`[data-test="discover-card-${card.id}"]`).trigger('keydown', {
      key: 'Enter',
    })

    expect(pushSpy).toHaveBeenCalledWith({ name: 'discover.detail', params: { ulid: card.id } })
  })

  describe('footer', () => {
    it('one page: no footer', async () => {
      const harness = await mountDiscover({ cards: [makeCard()], lastPage: 1 })
      cleanup = harness.cleanup

      expect(harness.wrapper.find('[data-test="discover-pagination"]').exists()).toBe(false)
    })

    it('first of several: "Showing 1 of 50", Previous off, Next on', async () => {
      const harness = await mountDiscover({ cards: [makeCard()], total: 50, lastPage: 3 })
      cleanup = harness.cleanup

      expect(text(harness.wrapper, 'discover-showing')).toBe('Showing 1 of 50')
      expect(
        harness.wrapper.get('[data-test="discover-page-previous"]').attributes('disabled'),
      ).toBeDefined()
      expect(
        harness.wrapper.get('[data-test="discover-page-next"]').attributes('disabled'),
      ).toBeUndefined()
    })

    it('Next asks for the next page; the count moves down the pool', async () => {
      const harness = await mountDiscover({ cards: [makeCard()], total: 50, lastPage: 3 })
      cleanup = harness.cleanup

      vi.mocked(discoveryApi.list).mockClear()
      await harness.wrapper.get('[data-test="discover-page-next"]').trigger('click')
      await flushPromises()

      expect(vi.mocked(discoveryApi.list).mock.calls.at(-1)?.[1]).toMatchObject({ page: 2 })
      expect(text(harness.wrapper, 'discover-showing')).toBe('Showing 25 of 50')
    })

    it('last page: Next off, Previous on', async () => {
      const harness = await mountDiscover({
        cards: [makeCard()],
        total: 50,
        lastPage: 3,
        url: '/discover?page=3',
      })
      cleanup = harness.cleanup

      expect(
        harness.wrapper.get('[data-test="discover-page-next"]').attributes('disabled'),
      ).toBeDefined()
      expect(
        harness.wrapper.get('[data-test="discover-page-previous"]').attributes('disabled'),
      ).toBeUndefined()
    })
  })

  it('omits what nothing backs: heart, video count, verified badge, toggle, extra filters', async () => {
    const harness = await mountDiscover({ cards: [makeCard({ relationship_status: 'roster' })] })
    cleanup = harness.cleanup
    const page = harness.wrapper.get('[data-test="discover-page"]')

    expect(page.find('.mdi-heart').exists()).toBe(false)
    expect(page.find('.mdi-check-decagram').exists()).toBe(false)
    expect(page.text()).not.toContain('videos')
    expect(page.text()).not.toContain('Saved searches')
    expect(page.text()).not.toContain('Add to talent pool')
  })

  it('the page source holds no font-size or colour literal', () => {
    expect(pageSource).not.toMatch(/font-size:\s*[\d.]+(px|rem|em|cqi)/)
    expect(pageSource).not.toMatch(/#[0-9a-fA-F]{3,8}\b/)
    expect(pageSource).not.toMatch(/\brgba?\(\s*\d/)
  })
})
