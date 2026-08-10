/**
 * Vitest coverage for the campaign "Invite creators" picker.
 *
 * The dialog shipped with NO spec, which is how its search regression survived:
 * it fetched one page of the roster and filtered it in the browser, so on a
 * roster larger than that page the tail of the alphabet was absent from the
 * list entirely and could not be invited to any campaign. On the agency that
 * reported it, 76 of 176 creators were unreachable.
 *
 * These specs pin the server-side search that replaced it. The offer form child
 * is mounted for real (it is inert until submit); only the two API modules are
 * mocked.
 */

import type { RosterCreatorListItem, RosterListResponse } from '@catalyst/api-client'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enApp from '@/core/i18n/locales/en/app.json'

import InviteCreatorsDialog from './InviteCreatorsDialog.vue'

vi.mock('@/modules/roster/api/roster.api', () => ({
  rosterApi: { list: vi.fn() },
}))

vi.mock('../api/campaigns.api', () => ({
  campaignsApi: {
    invite: vi.fn(),
    createAttachmentUpload: vi.fn(),
  },
}))

import { rosterApi } from '@/modules/roster/api/roster.api'

const CAMPAIGN = '01CAMPAIGNULIDXXXXXXXXXXXX'

function rosterRow(
  overrides: Partial<RosterCreatorListItem['attributes']> & { id?: string } = {},
): RosterCreatorListItem {
  const { id, ...attrs } = overrides
  return {
    id: id ?? `rel-${attrs.creator_id ?? '01CREATORA'}`,
    type: 'agency_creator_relations',
    attributes: {
      relationship_status: 'roster',
      is_blacklisted: false,
      blacklist_type: null,
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
      avatar_url: null,
      ...attrs,
    },
  } as RosterCreatorListItem
}

function listResponse(rows: RosterCreatorListItem[]): RosterListResponse {
  return {
    data: rows,
    meta: { total: rows.length, page: 1, per_page: 100, last_page: 1 },
  } as RosterListResponse
}

function mountDialog(rows: RosterCreatorListItem[]) {
  vi.mocked(rosterApi.list).mockResolvedValue(listResponse(rows))

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

  return mount(InviteCreatorsDialog, {
    props: {
      modelValue: true,
      agencyId: 'agency-ulid',
      campaignId: CAMPAIGN,
      campaignCurrency: 'GBP',
    },
    global: { plugins: [i18n, vuetify] },
    attachTo: document.createElement('div'),
  })
}

type DialogVm = { search: string | null }

function setSearch(wrapper: ReturnType<typeof mount>, term: string | null): void {
  ;(wrapper.vm as unknown as DialogVm).search = term
}

/** Drive the 300ms debounce deterministically, then settle the response. */
async function runDebounce(wrapper: ReturnType<typeof mount>, ms = 300): Promise<void> {
  vi.useFakeTimers()
  try {
    await wrapper.vm.$nextTick()
    await vi.advanceTimersByTimeAsync(ms)
  } finally {
    vi.useRealTimers()
  }
  await flushPromises()
}

function lastListParams(): Record<string, unknown> {
  return (vi.mocked(rosterApi.list).mock.calls.at(-1)?.[1] ?? {}) as Record<string, unknown>
}

describe('InviteCreatorsDialog (campaign invite picker)', () => {
  let wrapper: ReturnType<typeof mount> | null = null

  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    wrapper?.unmount()
    wrapper = null
  })

  it('fetches the unfiltered roster on open', async () => {
    wrapper = mountDialog([rosterRow()])
    await flushPromises()

    expect(rosterApi.list).toHaveBeenCalledWith('agency-ulid', { per_page: 100 })
  })

  it('sends the search to the SERVER after a 300ms debounce, trimmed', async () => {
    wrapper = mountDialog([rosterRow()])
    await flushPromises()
    vi.mocked(rosterApi.list).mockClear()

    setSearch(wrapper, '  rita  ')

    vi.useFakeTimers()
    try {
      await wrapper.vm.$nextTick()

      // Inside the debounce window — nothing issued yet.
      await vi.advanceTimersByTimeAsync(299)
      expect(rosterApi.list).not.toHaveBeenCalled()

      await vi.advanceTimersByTimeAsync(1)
    } finally {
      vi.useRealTimers()
    }
    await flushPromises()

    expect(rosterApi.list).toHaveBeenCalledTimes(1)
    expect(lastListParams()).toEqual({ per_page: 100, q: 'rita' })
  })

  it('renders whatever the server returns WITHOUT re-filtering it locally', async () => {
    // The regression this dialog shipped with: a local `display_name.includes(q)`
    // filter. A row the server matched on bio (its name shares no substring with
    // the query) must still render — if it vanishes, the local filter is back.
    wrapper = mountDialog([rosterRow({ creator_id: '01A', display_name: 'Ada Lovelace' })])
    await flushPromises()

    vi.mocked(rosterApi.list).mockResolvedValue(
      listResponse([rosterRow({ creator_id: '01R', display_name: 'Margaret Hamilton' })]),
    )
    setSearch(wrapper, 'rita')
    await runDebounce(wrapper)

    expect(document.querySelector('[data-test="invite-creators-row-01R"]')).not.toBeNull()
  })

  it('reaches a creator beyond the first page — the bug that made 76 of 176 uninvitable', async () => {
    // Open on a page that does NOT contain Rita (she sorts past the 100-row
    // window), then search: the server returns her and she becomes selectable.
    wrapper = mountDialog([rosterRow({ creator_id: '01A', display_name: 'Ada Lovelace' })])
    await flushPromises()
    expect(document.querySelector('[data-test="invite-creators-row-01RITA"]')).toBeNull()

    vi.mocked(rosterApi.list).mockResolvedValue(
      listResponse([rosterRow({ creator_id: '01RITA', display_name: 'Rita Levi' })]),
    )
    setSearch(wrapper, 'rita')
    await runDebounce(wrapper)

    // Reaching her REQUIRES the term to leave the browser; a local filter over
    // the already-fetched page could never surface someone who is not in it.
    expect(lastListParams()).toMatchObject({ q: 'rita' })
    expect(document.querySelector('[data-test="invite-creators-row-01RITA"]')).not.toBeNull()
    expect(document.querySelector('[data-test="invite-creators-checkbox-01RITA"]')).not.toBeNull()
  })

  it('shows the no-MATCH state and KEEPS the search field when a search returns nothing', async () => {
    wrapper = mountDialog([rosterRow()])
    await flushPromises()

    vi.mocked(rosterApi.list).mockResolvedValue(listResponse([]))
    setSearch(wrapper, 'nobody')
    await runDebounce(wrapper)

    expect(document.querySelector('[data-test="invite-creators-no-match"]')).not.toBeNull()
    // An empty RESULT is not an empty ROSTER — conflating them would also
    // unmount the field below and trap the user with an unclearable term.
    expect(document.querySelector('[data-test="invite-creators-empty"]')).toBeNull()
    expect(document.querySelector('[data-test="invite-creators-search"]')).not.toBeNull()
  })

  it('shows the no-ROSTER state (and hides the search field) when the agency has no creators', async () => {
    wrapper = mountDialog([])
    await flushPromises()

    expect(document.querySelector('[data-test="invite-creators-empty"]')).not.toBeNull()
    expect(document.querySelector('[data-test="invite-creators-no-match"]')).toBeNull()
    expect(document.querySelector('[data-test="invite-creators-search"]')).toBeNull()
  })

  it('survives the clearable X (v-model null) and re-queries unfiltered', async () => {
    wrapper = mountDialog([rosterRow()])
    await flushPromises()

    setSearch(wrapper, 'rita')
    await runDebounce(wrapper)
    expect(lastListParams()).toMatchObject({ q: 'rita' })

    // Vuetify's `clearable` writes null, which the old `.trim()` read threw on.
    vi.mocked(rosterApi.list).mockClear()
    setSearch(wrapper, null)
    await runDebounce(wrapper)

    expect(rosterApi.list).toHaveBeenCalledTimes(1)
    expect(lastListParams()).toEqual({ per_page: 100 })
  })

  it('ignores a stale response that lands after a newer query has answered', async () => {
    wrapper = mountDialog([rosterRow({ creator_id: '01A', display_name: 'Ada Lovelace' })])
    await flushPromises()

    let releaseStale: () => void = () => {}
    const stale = new Promise<RosterListResponse>((resolve) => {
      releaseStale = () =>
        resolve(listResponse([rosterRow({ creator_id: '01STALE', display_name: 'Stale' })]))
    })

    vi.mocked(rosterApi.list)
      .mockReturnValueOnce(stale)
      .mockResolvedValueOnce(
        listResponse([rosterRow({ creator_id: '01FRESH', display_name: 'Fresh' })]),
      )

    setSearch(wrapper, 'r')
    await runDebounce(wrapper)
    setSearch(wrapper, 'ri')
    await runDebounce(wrapper)

    // The first query only now comes back — it must not overwrite the newer one.
    releaseStale()
    await flushPromises()

    expect(document.querySelector('[data-test="invite-creators-row-01FRESH"]')).not.toBeNull()
    expect(document.querySelector('[data-test="invite-creators-row-01STALE"]')).toBeNull()
  })
})
