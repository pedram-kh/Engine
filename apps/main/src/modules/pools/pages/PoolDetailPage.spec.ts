/**
 * Sprint 6 Chunk 2b — Vitest coverage for the talent-pool DETAIL page: the
 * members roster (the D-2b-7 list/detail boundary — counts on the list, the
 * roster here), role-gated inline remove, and the remove round-trip.
 */

import type { TalentPoolMemberResource, TalentPoolResource } from '@catalyst/api-client'
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

import PoolDetailPage from './PoolDetailPage.vue'

vi.mock('../api/talentPools.api', () => ({
  talentPoolsApi: {
    show: vi.fn(),
    members: vi.fn(),
    removeCreator: vi.fn(),
    addCreator: vi.fn(),
  },
}))

// The pool-side "Add creators" dialog (rendered for admin/manager) loads the
// roster on open; stub the roster API so mounting the page never hits transport.
vi.mock('@/modules/roster/api/roster.api', () => ({
  rosterApi: {
    list: vi.fn().mockResolvedValue({
      data: [],
      meta: { total: 0, page: 1, per_page: 100, last_page: 1 },
    }),
  },
}))

import { talentPoolsApi } from '../api/talentPools.api'

const POOL_ULID = '01POOLULIDXXXXXXXXXXXXXXXX'

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

function makePool(): TalentPoolResource {
  return {
    id: POOL_ULID,
    type: 'talent_pools',
    attributes: {
      name: 'Acme Q3',
      description: 'For the Acme work.',
      brand_id: null,
      brand_name: null,
      is_archived: false,
      creators_count: 1,
      created_at: '2026-06-01T10:00:00.000000Z',
      updated_at: '2026-06-01T10:00:00.000000Z',
    },
  }
}

function makeMember(id = '01MEMBERULIDXXXXXXXXXXXXXX'): TalentPoolMemberResource {
  return {
    id,
    type: 'talent_pool_members',
    attributes: {
      display_name: 'Ada Lovelace',
      country_code: 'GB',
      primary_language: 'en',
      categories: ['tech'],
      avatar_url: null,
      application_status: 'approved',
      added_at: '2026-06-02T10:00:00.000000Z',
    },
  }
}

async function mountDetail(
  options: {
    role?: 'agency_admin' | 'agency_manager' | 'agency_staff'
    members?: TalentPoolMemberResource[]
  } = {},
): Promise<{ wrapper: ReturnType<typeof mount>; cleanup: () => void }> {
  const pinia = createPinia()
  setActivePinia(pinia)

  vi.mocked(talentPoolsApi.show).mockResolvedValue({ data: makePool() })
  vi.mocked(talentPoolsApi.members).mockResolvedValue({
    data: options.members ?? [makeMember()],
    meta: {
      total: (options.members ?? [makeMember()]).length,
      current_page: 1,
      per_page: 25,
      last_page: 1,
    },
    links: { first: '', last: '', prev: null, next: null },
  } as unknown as Awaited<ReturnType<typeof talentPoolsApi.members>>)

  const agency = useAgencyStore()
  agency.initFromUser([
    { agency_id: 'agency-ulid', agency_name: 'Test Agency', role: options.role ?? 'agency_admin' },
  ])

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/talent-pools', name: 'pools.list', component: { template: '<div />' } },
      { path: '/talent-pools/:ulid', name: 'pools.detail', component: { template: '<div />' } },
      { path: '/talent-pools/:ulid/edit', name: 'pools.edit', component: { template: '<div />' } },
    ],
  })
  await router.push(`/talent-pools/${POOL_ULID}`)
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

  const wrapper = mount(PoolDetailPage, {
    global: { plugins: [pinia, router, i18n, vuetify] },
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

describe('PoolDetailPage (Sprint 6 Chunk 2b)', () => {
  let cleanup: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    cleanup?.()
    cleanup = null
  })

  it('loads the pool + members scoped to agency and route ulid', async () => {
    const harness = await mountDetail()
    cleanup = harness.cleanup

    expect(talentPoolsApi.show).toHaveBeenCalledWith('agency-ulid', POOL_ULID)
    expect(talentPoolsApi.members).toHaveBeenCalledWith('agency-ulid', POOL_ULID, {
      page: 1,
      per_page: 25,
    })
    expect(harness.wrapper.find('[data-test="pool-detail-name"]').text()).toBe('Acme Q3')
  })

  it('renders the member roster', async () => {
    const member = makeMember()
    const harness = await mountDetail({ members: [member] })
    cleanup = harness.cleanup
    expect(harness.wrapper.find(`[data-test="pool-member-${member.id}"]`).exists()).toBe(true)
  })

  it('shows the inline remove button for admin/manager', async () => {
    const member = makeMember()
    const harness = await mountDetail({ role: 'agency_manager', members: [member] })
    cleanup = harness.cleanup
    expect(harness.wrapper.find(`[data-test="pool-member-remove-${member.id}"]`).exists()).toBe(
      true,
    )
  })

  it('hides the inline remove button for staff', async () => {
    const member = makeMember()
    const harness = await mountDetail({ role: 'agency_staff', members: [member] })
    cleanup = harness.cleanup
    expect(harness.wrapper.find(`[data-test="pool-member-remove-${member.id}"]`).exists()).toBe(
      false,
    )
  })

  it('shows the "Add creators" button for admin/manager (mirrors the remove gate)', async () => {
    const harness = await mountDetail({ role: 'agency_manager' })
    cleanup = harness.cleanup
    expect(harness.wrapper.find('[data-test="pool-detail-add-creators"]').exists()).toBe(true)
  })

  it('hides the "Add creators" button for staff', async () => {
    const harness = await mountDetail({ role: 'agency_staff' })
    cleanup = harness.cleanup
    expect(harness.wrapper.find('[data-test="pool-detail-add-creators"]').exists()).toBe(false)
  })

  it('reloads the pool + member list when the add dialog reports creators added', async () => {
    const harness = await mountDetail({ role: 'agency_admin' })
    cleanup = harness.cleanup

    const showCallsBefore = vi.mocked(talentPoolsApi.show).mock.calls.length
    const membersCallsBefore = vi.mocked(talentPoolsApi.members).mock.calls.length

    const vm = harness.wrapper.vm as unknown as {
      onCreatorsAdded: (m: string) => Promise<void>
      snackbar: string | null
    }
    await vm.onCreatorsAdded('2 creators added to the pool.')
    await flushPromises()

    expect(vi.mocked(talentPoolsApi.show).mock.calls.length).toBe(showCallsBefore + 1)
    expect(vi.mocked(talentPoolsApi.members).mock.calls.length).toBe(membersCallsBefore + 1)
    expect(vm.snackbar).toBe('2 creators added to the pool.')
  })

  it('clicking remove calls removeCreator and refreshes the member list', async () => {
    const member = makeMember()
    const harness = await mountDetail({ role: 'agency_admin', members: [member] })
    cleanup = harness.cleanup

    vi.mocked(talentPoolsApi.removeCreator).mockResolvedValue({ data: makePool() })
    const membersCallsBefore = vi.mocked(talentPoolsApi.members).mock.calls.length

    await harness.wrapper.find(`[data-test="pool-member-remove-${member.id}"]`).trigger('click')
    await flushPromises()

    expect(talentPoolsApi.removeCreator).toHaveBeenCalledWith('agency-ulid', POOL_ULID, member.id)
    expect(vi.mocked(talentPoolsApi.members).mock.calls.length).toBe(membersCallsBefore + 1)
  })
})
