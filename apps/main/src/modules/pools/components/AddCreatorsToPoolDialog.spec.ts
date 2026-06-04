/**
 * Vitest coverage for the pool-side "Add creators" picker (frontend-only,
 * reusing the existing idempotent `store`).
 *
 * The picker is sourced from the ROSTER (D-2) and excludes current members
 * client-side (D-3); selecting creators + Add loops the single-add `store`
 * (D-4); client-side search filters the fetched roster (D-5). Adding a creator
 * the partial exclusion still showed is a harmless idempotent no-op.
 */

import type { RosterCreatorListItem, TalentPoolMemberResource } from '@catalyst/api-client'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'
import { createI18n } from 'vue-i18n'

import enApp from '@/core/i18n/locales/en/app.json'

import AddCreatorsToPoolDialog from './AddCreatorsToPoolDialog.vue'

vi.mock('@/modules/roster/api/roster.api', () => ({
  rosterApi: {
    list: vi.fn(),
  },
}))

vi.mock('../api/talentPools.api', () => ({
  talentPoolsApi: {
    members: vi.fn(),
    addCreator: vi.fn(),
  },
}))

import { rosterApi } from '@/modules/roster/api/roster.api'
import { talentPoolsApi } from '../api/talentPools.api'

const POOL = '01POOLULIDXXXXXXXXXXXXXXXX'

function rosterRow(
  overrides: Partial<RosterCreatorListItem['attributes']> & { id?: string } = {},
): RosterCreatorListItem {
  const { id, ...attrs } = overrides
  return {
    id: id ?? `rel-${attrs.creator_id ?? '01CREATORA'}`,
    type: 'agency_creator_relations',
    attributes: {
      relationship_status: 'active',
      is_blacklisted: false,
      internal_rating: null,
      total_campaigns_completed: 0,
      total_paid_minor_units: 0,
      last_engaged_at: null,
      creator_id: '01CREATORA',
      display_name: 'Ada Lovelace',
      application_status: 'approved',
      country_code: 'GB',
      primary_language: 'en',
      categories: ['tech'],
      ...attrs,
    },
  } as RosterCreatorListItem
}

function member(id: string, name = 'Member'): TalentPoolMemberResource {
  return {
    id,
    type: 'talent_pool_members',
    attributes: {
      display_name: name,
      country_code: 'GB',
      primary_language: 'en',
      categories: [],
      avatar_url: null,
      application_status: 'approved',
      is_blacklisted: false,
      blacklist_type: null,
      added_at: '2026-06-02T10:00:00.000000Z',
    },
  } as TalentPoolMemberResource
}

function mountDialog(roster: RosterCreatorListItem[], members: TalentPoolMemberResource[]) {
  vi.mocked(rosterApi.list).mockResolvedValue({
    data: roster,
    meta: { total: roster.length, page: 1, per_page: 100, last_page: 1 },
  })
  vi.mocked(talentPoolsApi.members).mockResolvedValue({
    data: members,
    meta: { total: members.length, current_page: 1, per_page: 25, last_page: 1 },
    links: { first: '', last: '', prev: null, next: null },
  } as unknown as Awaited<ReturnType<typeof talentPoolsApi.members>>)

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

  return mount(AddCreatorsToPoolDialog, {
    props: { modelValue: true, agencyId: 'agency-ulid', poolId: POOL },
    global: { plugins: [i18n, vuetify] },
    attachTo: document.createElement('div'),
  })
}

type DialogVm = {
  toggleSelect: (id: string) => void
  addSelected: () => Promise<void>
  search: string
}

describe('AddCreatorsToPoolDialog (pool-side add)', () => {
  let wrapper: ReturnType<typeof mount> | null = null

  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    wrapper?.unmount()
    wrapper = null
  })

  it('fetches the roster + the pool members when opened (D-2/D-3)', async () => {
    wrapper = mountDialog([rosterRow()], [])
    await flushPromises()
    expect(rosterApi.list).toHaveBeenCalledWith('agency-ulid', { per_page: 100 })
    expect(talentPoolsApi.members).toHaveBeenCalledWith('agency-ulid', POOL, { per_page: 25 })
  })

  it('excludes current members client-side (D-3)', async () => {
    const inPool = rosterRow({ id: 'rel-in', creator_id: '01IN', display_name: 'In Pool' })
    const outPool = rosterRow({ id: 'rel-out', creator_id: '01OUT', display_name: 'Out Pool' })
    wrapper = mountDialog([inPool, outPool], [member('01IN', 'In Pool')])
    await flushPromises()

    expect(document.querySelector('[data-test="add-creators-row-01OUT"]')).not.toBeNull()
    expect(document.querySelector('[data-test="add-creators-row-01IN"]')).toBeNull()
  })

  it('renders the all-already-in-pool empty state when every roster creator is a member', async () => {
    const inPool = rosterRow({ creator_id: '01IN', display_name: 'In Pool' })
    wrapper = mountDialog([inPool], [member('01IN', 'In Pool')])
    await flushPromises()
    expect(document.querySelector('[data-test="add-creators-empty-all-in-pool"]')).not.toBeNull()
  })

  it('renders the no-roster empty state when the agency has no roster', async () => {
    wrapper = mountDialog([], [])
    await flushPromises()
    expect(document.querySelector('[data-test="add-creators-empty-no-roster"]')).not.toBeNull()
  })

  it('selecting creators + Add loops the single-add store per creator and emits added', async () => {
    const a = rosterRow({ id: 'rel-a', creator_id: '01A', display_name: 'Alice' })
    const b = rosterRow({ id: 'rel-b', creator_id: '01B', display_name: 'Bob' })
    wrapper = mountDialog([a, b], [])
    await flushPromises()

    vi.mocked(talentPoolsApi.addCreator).mockResolvedValue({
      data: {} as unknown as Awaited<ReturnType<typeof talentPoolsApi.addCreator>>['data'],
    })

    const vm = wrapper.vm as unknown as DialogVm
    vm.toggleSelect('01A')
    vm.toggleSelect('01B')
    await vm.addSelected()
    await flushPromises()

    expect(talentPoolsApi.addCreator).toHaveBeenCalledTimes(2)
    expect(talentPoolsApi.addCreator).toHaveBeenCalledWith('agency-ulid', POOL, '01A')
    expect(talentPoolsApi.addCreator).toHaveBeenCalledWith('agency-ulid', POOL, '01B')
    expect(wrapper.emitted('added')).toBeTruthy()
    expect(wrapper.emitted('update:modelValue')?.at(-1)?.[0]).toBe(false)
  })

  it('client-side search filters the fetched roster list (D-5)', async () => {
    const a = rosterRow({ id: 'rel-a', creator_id: '01A', display_name: 'Alice' })
    const b = rosterRow({ id: 'rel-b', creator_id: '01B', display_name: 'Bob' })
    wrapper = mountDialog([a, b], [])
    await flushPromises()

    // The dialog (and its search field) is teleported to body, so drive the
    // search via the component state rather than a wrapper-scoped query.
    ;(wrapper.vm as unknown as DialogVm).search = 'ali'
    await flushPromises()

    expect(document.querySelector('[data-test="add-creators-row-01A"]')).not.toBeNull()
    expect(document.querySelector('[data-test="add-creators-row-01B"]')).toBeNull()
  })

  it('shows a per-row blacklist flag for hard + soft, none for a clean creator (D-6)', async () => {
    const hard = rosterRow({
      id: 'rel-hard',
      creator_id: '01HARD',
      display_name: 'Hard',
      is_blacklisted: true,
      blacklist_type: 'hard',
    })
    const soft = rosterRow({
      id: 'rel-soft',
      creator_id: '01SOFT',
      display_name: 'Soft',
      is_blacklisted: true,
      blacklist_type: 'soft',
    })
    const clean = rosterRow({ id: 'rel-clean', creator_id: '01CLEAN', display_name: 'Clean' })
    wrapper = mountDialog([hard, soft, clean], [])
    await flushPromises()

    expect(document.querySelector('[data-test="add-creators-blacklist-01HARD"]')?.textContent).toBe(
      'Blacklisted',
    )
    expect(document.querySelector('[data-test="add-creators-blacklist-01SOFT"]')?.textContent).toBe(
      'Blacklist warning',
    )
    expect(document.querySelector('[data-test="add-creators-blacklist-01CLEAN"]')).toBeNull()
  })

  it('a HARD-blacklisted creator triggers a confirm on add — proceed adds, cancel aborts (D-6/D-7)', async () => {
    const hard = rosterRow({
      id: 'rel-hard',
      creator_id: '01HARD',
      display_name: 'Hard',
      is_blacklisted: true,
      blacklist_type: 'hard',
    })
    wrapper = mountDialog([hard], [])
    await flushPromises()

    vi.mocked(talentPoolsApi.addCreator).mockResolvedValue({
      data: {} as unknown as Awaited<ReturnType<typeof talentPoolsApi.addCreator>>['data'],
    })

    const vm = wrapper.vm as unknown as DialogVm
    vm.toggleSelect('01HARD')

    // Cancel → aborts, no add.
    const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false)
    await vm.addSelected()
    await flushPromises()
    expect(confirmSpy).toHaveBeenCalledTimes(1)
    expect(talentPoolsApi.addCreator).not.toHaveBeenCalled()

    // Proceed → adds.
    confirmSpy.mockReturnValue(true)
    await vm.addSelected()
    await flushPromises()
    expect(confirmSpy).toHaveBeenCalledTimes(2)
    expect(talentPoolsApi.addCreator).toHaveBeenCalledWith('agency-ulid', POOL, '01HARD')

    confirmSpy.mockRestore()
  })

  it('a SOFT-blacklisted creator shows the flag but does NOT trigger the confirm (D-7)', async () => {
    const soft = rosterRow({
      id: 'rel-soft',
      creator_id: '01SOFT',
      display_name: 'Soft',
      is_blacklisted: true,
      blacklist_type: 'soft',
    })
    wrapper = mountDialog([soft], [])
    await flushPromises()

    vi.mocked(talentPoolsApi.addCreator).mockResolvedValue({
      data: {} as unknown as Awaited<ReturnType<typeof talentPoolsApi.addCreator>>['data'],
    })

    const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(true)
    const vm = wrapper.vm as unknown as DialogVm
    vm.toggleSelect('01SOFT')
    await vm.addSelected()
    await flushPromises()

    expect(confirmSpy).not.toHaveBeenCalled()
    expect(talentPoolsApi.addCreator).toHaveBeenCalledWith('agency-ulid', POOL, '01SOFT')
    confirmSpy.mockRestore()
  })

  it('a clean creator adds with no confirm (D-7)', async () => {
    const clean = rosterRow({ id: 'rel-clean', creator_id: '01CLEAN', display_name: 'Clean' })
    wrapper = mountDialog([clean], [])
    await flushPromises()

    vi.mocked(talentPoolsApi.addCreator).mockResolvedValue({
      data: {} as unknown as Awaited<ReturnType<typeof talentPoolsApi.addCreator>>['data'],
    })

    const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(true)
    const vm = wrapper.vm as unknown as DialogVm
    vm.toggleSelect('01CLEAN')
    await vm.addSelected()
    await flushPromises()

    expect(confirmSpy).not.toHaveBeenCalled()
    expect(talentPoolsApi.addCreator).toHaveBeenCalledWith('agency-ulid', POOL, '01CLEAN')
    confirmSpy.mockRestore()
  })

  it('adding a creator the partial exclusion still showed is a harmless no-op (D-3 idempotency)', async () => {
    // Simulate the large-pool case: the member fetch is page-local, so an
    // already-member creator is still offered. Re-adding hits the idempotent
    // store, which the FE treats as a normal success (no error, no duplicate).
    const stillShown = rosterRow({ creator_id: '01DUP', display_name: 'Dup' })
    wrapper = mountDialog([stillShown], []) // members page didn't include 01DUP
    await flushPromises()

    vi.mocked(talentPoolsApi.addCreator).mockResolvedValue({
      data: {} as unknown as Awaited<ReturnType<typeof talentPoolsApi.addCreator>>['data'],
    })

    const vm = wrapper.vm as unknown as DialogVm
    vm.toggleSelect('01DUP')
    await vm.addSelected()
    await flushPromises()

    expect(talentPoolsApi.addCreator).toHaveBeenCalledTimes(1)
    expect(talentPoolsApi.addCreator).toHaveBeenCalledWith('agency-ulid', POOL, '01DUP')
    expect(wrapper.find('[data-test="add-creators-error"]').exists()).toBe(false)
    expect(wrapper.emitted('added')).toBeTruthy()
  })
})
