/**
 * Sprint 4 Chunk 5 — Vitest coverage for the agency creator roster page.
 *
 * Covers: mount + load via currentAgencyId, row rendering (status chip +
 * read-only rating + blacklist flag), the D-c5-4 no-navigation invariant,
 * each filter re-querying + combined filters, the two empty-state variants,
 * and the error path.
 *
 * Mounting a full-Vuetify tree (v-data-table-server + v-rating) under jsdom
 * is memory-heavy, so related assertions are grouped into a small number of
 * mounts (mirrors the BrandListPage.spec footprint). Each mount builds its
 * own Vuetify + i18n + pinia + router so wrappers GC cleanly on unmount.
 */

import type { RosterCreatorListItem } from '@catalyst/api-client'
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

import CreatorRosterPage from './CreatorRosterPage.vue'

vi.mock('../api/roster.api', () => ({
  rosterApi: {
    list: vi.fn(),
  },
}))

import { rosterApi } from '../api/roster.api'

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

function makeRow(
  overrides: Partial<RosterCreatorListItem['attributes']> = {},
): RosterCreatorListItem {
  return {
    id: '01HZA1B2C3D4E5F6G7H8J9K0M1',
    type: 'agency_creator_relations',
    attributes: {
      relationship_status: 'roster',
      application_status: 'pending',
      is_blacklisted: false,
      internal_rating: null,
      total_campaigns_completed: 0,
      total_paid_minor_units: 0,
      last_engaged_at: null,
      creator_id: '01CREATORULIDXXXXXXXXXXXXXX',
      display_name: 'Ada Lovelace',
      country_code: 'GB',
      primary_language: 'en',
      categories: ['tech'],
      ...overrides,
    },
  }
}

async function mountRoster(
  options: {
    rows?: RosterCreatorListItem[]
    agencyId?: string | null
    reject?: boolean
    realTable?: boolean
  } = {},
): Promise<{ wrapper: ReturnType<typeof mount>; cleanup: () => void }> {
  const pinia = createPinia()
  setActivePinia(pinia)

  if (options.reject === true) {
    vi.mocked(rosterApi.list).mockRejectedValue(new Error('500'))
  } else {
    const rows = options.rows ?? []
    vi.mocked(rosterApi.list).mockResolvedValue({
      data: rows,
      meta: { total: rows.length, page: 1, per_page: 25, last_page: 1 },
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
      { path: '/roster', name: 'roster.list', component: { template: '<div />' } },
    ],
  })
  await router.push('/roster')
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

  // Vuetify's heavy components leak across jsdom mounts: VSelect's
  // VOverlay/VMenu teleport to <body> and VDataTableServer retains a large
  // tree — running several full renders in one worker blows the heap. We
  // render the REAL data-table in exactly one test (row-DOM assertions) and
  // stub it elsewhere; the filter selects + the search field are always
  // stubbed (the specs drive the refs directly, not via input events, so no
  // coverage is lost — the real table + search + affordance DOM is covered by
  // the Playwright roster spec, Sprint 6 Chunk 1 D-6). Lean footprint mirrors
  // BrandListPage.spec.
  //
  // Note: VTooltip is intentionally NOT stubbed — the disabled-affordance
  // tests assert the span-wrapped activator + the disabled control render
  // (the Chunk-3 KYC idiom), which lives inside the tooltip's activator slot.
  const stubs: Record<string, boolean> = { VSelect: true, VTextField: true }
  if (options.realTable !== true) {
    stubs.VDataTableServer = true
  }

  const wrapper = mount(CreatorRosterPage, {
    global: {
      plugins: [pinia, router, i18n, vuetify],
      stubs,
    },
    attachTo: document.createElement('div'),
  })

  await flushPromises()

  return {
    wrapper,
    cleanup: () => {
      wrapper.unmount()
      Object.keys(localStorageStore).forEach((k) => delete localStorageStore[k])
    },
  }
}

describe('CreatorRosterPage (Sprint 4 Chunk 5)', () => {
  let cleanup: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    cleanup?.()
    cleanup = null
  })

  it('loads scoped to the current agency and renders a rich, non-navigating row', async () => {
    const row = makeRow({
      internal_rating: 4,
      is_blacklisted: true,
      relationship_status: 'prospect',
      application_status: 'approved',
    })
    const harness = await mountRoster({ rows: [row], agencyId: 'agency-xyz', realTable: true })
    cleanup = harness.cleanup

    // Scoped fetch on mount.
    expect(vi.mocked(rosterApi.list).mock.calls[0]?.[0]).toBe('agency-xyz')
    expect(harness.wrapper.find('[data-test="roster-table"]').exists()).toBe(true)

    // Row content: status chip, read-only rating, blacklist flag.
    expect(harness.wrapper.find(`[data-test="roster-status-${row.id}"]`).text()).toContain(
      'Prospect',
    )
    expect(harness.wrapper.find(`[data-test="roster-rating-${row.id}"]`).exists()).toBe(true)
    expect(harness.wrapper.find(`[data-test="roster-blacklist-${row.id}"]`).exists()).toBe(true)

    // Chunk 5b: the application-status chip is a SEPARATE axis from the
    // relationship chip — its own data-test + its own label ("Approved" vs
    // the relationship "Prospect"), so the two never read as the same thing.
    const appStatusChip = harness.wrapper.find(`[data-test="roster-app-status-${row.id}"]`)
    expect(appStatusChip.exists()).toBe(true)
    expect(appStatusChip.text()).toContain('Approved')
    const relationshipChip = harness.wrapper.find(`[data-test="roster-status-${row.id}"]`)
    expect(appStatusChip.element).not.toBe(relationshipChip.element)
    expect(appStatusChip.text()).not.toContain('Prospect')

    // D-c5-4: the name is a plain span, not a link/button — rows do NOT
    // navigate to a creator detail and no per-row view affordance exists.
    const nameEl = harness.wrapper.find(`[data-test="roster-name-${row.id}"]`)
    expect(nameEl.element.tagName).toBe('SPAN')
    expect(harness.wrapper.find(`[data-test="roster-view-${row.id}"]`).exists()).toBe(false)
  })

  it('does NOT call the API when there is no current agency', async () => {
    const harness = await mountRoster({ agencyId: null })
    cleanup = harness.cleanup

    expect(rosterApi.list).not.toHaveBeenCalled()
  })

  it('re-queries with each filter and ANDs them together', async () => {
    const harness = await mountRoster({ rows: [makeRow()] })
    cleanup = harness.cleanup
    const vm = harness.wrapper.vm as unknown as {
      statusFilter: string
      countryFilter: string | null
      languageFilter: string | null
      categoryFilter: string | null
    }

    vi.mocked(rosterApi.list).mockClear()
    vm.statusFilter = 'external'
    await flushPromises()
    expect(vi.mocked(rosterApi.list).mock.calls.at(-1)?.[1]).toMatchObject({ status: 'external' })

    vi.mocked(rosterApi.list).mockClear()
    vm.statusFilter = 'roster'
    vm.countryFilter = 'PT'
    vm.languageFilter = 'it'
    vm.categoryFilter = 'fitness'
    await flushPromises()
    expect(vi.mocked(rosterApi.list).mock.calls.at(-1)?.[1]).toMatchObject({
      status: 'roster',
      country: 'PT',
      language: 'it',
      category: 'fitness',
    })
  })

  it('shows the no-creators and no-match empty states', async () => {
    const harness = await mountRoster({ rows: [] })
    cleanup = harness.cleanup

    // No filters active → the no-creators state.
    expect(harness.wrapper.find('[data-test="roster-empty-state"]').exists()).toBe(true)
    expect(harness.wrapper.find('[data-test="roster-empty-filtered"]').exists()).toBe(false)

    // Activate a filter → the no-match state.
    ;(harness.wrapper.vm as unknown as { statusFilter: string }).statusFilter = 'prospect'
    await flushPromises()
    expect(harness.wrapper.find('[data-test="roster-empty-filtered"]').exists()).toBe(true)
    expect(harness.wrapper.find('[data-test="roster-empty-state"]').exists()).toBe(false)
  })

  it('surfaces a localized error when the API rejects', async () => {
    const harness = await mountRoster({ reject: true })
    cleanup = harness.cleanup

    expect(harness.wrapper.find('[data-test="roster-error"]').text()).toContain('Failed to load')
  })

  it('debounces the search box and threads the trimmed q to the API (Sprint 6 Chunk 1, D-1)', async () => {
    const harness = await mountRoster({ rows: [makeRow()] })
    cleanup = harness.cleanup
    const vm = harness.wrapper.vm as unknown as { searchQuery: string }

    // Switch to fake timers AFTER mount (the initial onMounted load already ran
    // under real timers) so we can drive the 300ms debounce deterministically.
    vi.useFakeTimers()
    try {
      vi.mocked(rosterApi.list).mockClear()
      vm.searchQuery = '  ada  '
      await harness.wrapper.vm.$nextTick()

      // Still inside the debounce window → no query yet.
      await vi.advanceTimersByTimeAsync(299)
      expect(rosterApi.list).not.toHaveBeenCalled()

      // Debounce elapses → exactly one query, with the TRIMMED q.
      await vi.advanceTimersByTimeAsync(1)
      expect(rosterApi.list).toHaveBeenCalledTimes(1)
      expect(vi.mocked(rosterApi.list).mock.calls.at(-1)?.[1]).toMatchObject({ q: 'ada' })
    } finally {
      vi.useRealTimers()
    }
  })

  it('renders availability + metrics as DISABLED affordances that issue no query (D-4)', async () => {
    const harness = await mountRoster({ rows: [makeRow()] })
    cleanup = harness.cleanup
    const wrapper = harness.wrapper

    // Each affordance control renders and is disabled (faded, inert).
    for (const id of ['roster-followers', 'roster-engagement', 'roster-availability']) {
      const control = wrapper.find(`[data-test="${id}-filter"]`)
      expect(control.exists(), `${id}-filter should render`).toBe(true)
      expect(control.attributes('disabled'), `${id}-filter should be disabled`).toBeDefined()
    }

    // The KYC span-wrap idiom: a disabled control emits no hover, so the
    // tooltip attaches to a wrapping <span> activator.
    expect(wrapper.find('[data-test="roster-followers-affordance"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="roster-engagement-affordance"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="roster-availability-affordance"]').exists()).toBe(true)

    // They have NO v-model and NO watcher → they CANNOT query. Only the
    // initial mount load happened (break-revert: wiring one to a query would
    // bump this past 1).
    expect(rosterApi.list).toHaveBeenCalledTimes(1)
  })
})
