/**
 * BoardApplicationsColumn (AH-059, D4) — the board's Applications pseudo-column.
 *
 * What this file pins, in the order the decision was argued:
 *
 *   1. PENDING ONLY — the request carries `status=pending`, so the column can
 *      never grow into the tab's history view by accident.
 *   2. The count is `meta.pending_total`, not `rows.length` — a page of 50 rows
 *      out of 60 pending must still say 60.
 *   3. Status and actions live ON THE CARD, which is the visible difference that
 *      makes the missing drag affordance read as deliberate.
 *   4. NO DRAG, by absence: no `<draggable>`, no `board-cards` group, no handle.
 *      (Its placement outside the drag machinery is pinned in BoardColumns.spec.)
 *   5. The DUAL REFETCH on an answer: the applications list AND `boardStore
 *      .refresh()`, because the accept's other half — the new `invited` card —
 *      lands in a different store, through the listener + automation that
 *      already shipped. This spec asserts the motion is FETCHED, not that this
 *      component creates a card: it must not, and the Boards module's zero-diff
 *      is the chunk's proof that it doesn't.
 */

import type { CampaignApplicationListItemResource } from '@catalyst/api-client'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enApp from '@/core/i18n/locales/en/app.json'

vi.mock('@/modules/campaigns/api/campaigns.api', () => ({
  campaignsApi: {
    listApplications: vi.fn(),
    acceptApplication: vi.fn(),
    rejectApplication: vi.fn(),
  },
}))
vi.mock('../api/board.api', () => ({
  boardApi: { show: vi.fn(), moveCard: vi.fn(), reorderColumns: vi.fn() },
}))

// AH-116 (item 7): the Message action reads which creators the agency can message.
vi.mock('@/modules/messaging/api/relationshipMessaging.api', () => ({
  relationshipMessagingApi: { messageableCreators: vi.fn() },
}))

import { MAX_PAGES } from '@/modules/agency/composables/useMessageableCreatorIds'
import { campaignsApi } from '@/modules/campaigns/api/campaigns.api'
import { relationshipMessagingApi } from '@/modules/messaging/api/relationshipMessaging.api'

import { boardApi } from '../api/board.api'
import { useBoardStore } from '../stores/useBoardStore'
import BoardApplicationsColumn from './BoardApplicationsColumn.vue'

function application(id: string, name: string): CampaignApplicationListItemResource {
  return {
    id,
    type: 'campaign_applications',
    attributes: {
      status: 'pending',
      note: `note for ${name}`,
      applied_at: '2026-06-01T10:00:00+00:00',
      answered_at: null,
      creator: { id: `cr-${id}`, display_name: name, avatar_url: null },
    },
  } as unknown as CampaignApplicationListItemResource
}

function listPayload(rows: CampaignApplicationListItemResource[], pendingTotal = rows.length) {
  return {
    data: rows,
    meta: {
      current_page: 1,
      last_page: 1,
      per_page: 50,
      total: rows.length,
      pending_total: pendingTotal,
    },
  }
}

function boardPayload() {
  return {
    data: {
      id: 'board-1',
      type: 'boards',
      attributes: { created_at: 'x', updated_at: 'x' },
      relationships: { campaign: { data: { id: 'campaign-ulid', type: 'campaigns' } } },
      columns: [],
      automations: [],
      cards: [],
    },
  }
}

/** One page of the messageable-creators list holding exactly these creator ULIDs. */
function messageablePage(ids: string[], page = 1, lastPage = 1) {
  return {
    data: ids.map((id) => ({
      id,
      type: 'messageable_creator' as const,
      attributes: { display_name: null, avatar_url: null },
    })),
    meta: { total: ids.length, page, per_page: 100, last_page: lastPage },
  }
}

let router: Router

async function mountColumn(canAct = true) {
  setActivePinia(createPinia())
  router = createRouter({
    history: createMemoryHistory(),
    routes: [
      {
        path: '/messages/:creatorUlid',
        name: 'messages.thread',
        component: { template: '<div />' },
      },
    ],
  })
  await router.push('/messages/start')
  await router.isReady()

  // The column only ever exists on a loaded board (BoardColumns renders under
  // the store's `v-else`), and `refresh()` is a no-op on an unloaded store — so
  // the spec has to stand the board up for the dual refetch to mean anything.
  await useBoardStore().load('agency-ulid', 'campaign-ulid')
  vi.mocked(boardApi.show).mockClear()

  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en: enApp } as never })
  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })
  const wrapper = mount(BoardApplicationsColumn, {
    props: {
      agencyId: 'agency-ulid',
      campaignId: 'campaign-ulid',
      canAct,
      campaignCurrency: 'EUR',
    },
    global: {
      plugins: [i18n, vuetify, router],
      // CreatorProfileDialog's own full/thin/§5.34 rendering is
      // CreatorProfileContent.spec.ts's job; here we only pin the click
      // wiring (D2c), so a real mount never fires an unmocked network call.
      stubs: { VDialog: true, CreatorProfileDialog: true },
    },
    attachTo: document.createElement('div'),
  })
  await flushPromises()
  return wrapper
}

describe('BoardApplicationsColumn — the pending working surface', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(campaignsApi.listApplications).mockResolvedValue(
      listPayload([application('app-1', 'Ada'), application('app-2', 'Grace')]) as never,
    )
    vi.mocked(boardApi.show).mockResolvedValue(boardPayload() as never)
    // Default: nobody is messageable, so every existing test keeps its current expectation.
    vi.mocked(relationshipMessagingApi.messageableCreators).mockResolvedValue(messageablePage([]))
  })

  afterEach(() => vi.restoreAllMocks())

  it('asks for PENDING rows only — the column is a working surface, not history', async () => {
    const wrapper = await mountColumn()
    expect(campaignsApi.listApplications).toHaveBeenCalledWith('agency-ulid', 'campaign-ulid', {
      page: 1,
      per_page: 50,
      status: 'pending',
    })
    expect(wrapper.find('[data-test="board-application-app-1"]').text()).toContain('Ada')
    wrapper.unmount()
  })

  it('counts `pending_total`, not the rendered rows', async () => {
    vi.mocked(campaignsApi.listApplications).mockResolvedValue(
      listPayload([application('app-1', 'Ada')], 7) as never,
    )
    const wrapper = await mountColumn()
    expect(wrapper.find('[data-test="board-applications-count"]').text()).toBe('7')
    wrapper.unmount()
  })

  it('reads as a column: its own header and title', async () => {
    const wrapper = await mountColumn()
    expect(wrapper.find('[data-test="board-applications-name"]').text()).toBe('Applications')
    wrapper.unmount()
  })

  it('puts the status ON the card — the affordance difference from a real card', async () => {
    const wrapper = await mountColumn()
    expect(wrapper.find('[data-test="board-application-status"]').text()).toBe('Pending')
    wrapper.unmount()
  })

  it('shows the empty state when nothing is waiting', async () => {
    vi.mocked(campaignsApi.listApplications).mockResolvedValue(listPayload([]) as never)
    const wrapper = await mountColumn()
    expect(wrapper.find('[data-test="board-applications-empty"]').text()).toBe(
      'No applications waiting',
    )
    expect(wrapper.find('[data-test="board-applications-count"]').text()).toBe('0')
    wrapper.unmount()
  })

  // ── §5.34 negatives: no drag in, no drag out — enforced by absence ─────────

  it('contains NO draggable and NO drag handle (§5.34 negative)', async () => {
    const wrapper = await mountColumn()
    const html = wrapper.html()

    expect(wrapper.findComponent({ name: 'draggable' }).exists()).toBe(false)
    expect(wrapper.find('[data-group]').exists()).toBe(false)
    expect(html).not.toContain('board-cards')
    expect(wrapper.find('.board-column__drag').exists()).toBe(false)
    wrapper.unmount()
  })

  it('renders application cards, never board cards — nothing here is an assignment', async () => {
    const wrapper = await mountColumn()
    expect(wrapper.findComponent({ name: 'BoardCard' }).exists()).toBe(false)
    // The card ids are APPLICATION ulids on an `application` hook, so nothing
    // downstream can mistake one for a `board_cards` row.
    expect(wrapper.find('[data-test="board-application-app-1"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="board-card-app-1"]').exists()).toBe(false)
    wrapper.unmount()
  })

  // ── The two answers, and the ability that gates them ──────────────────────

  it('hides both answers from someone without the invite ability', async () => {
    const wrapper = await mountColumn(false)
    expect(wrapper.find('[data-test="board-application-accept-app-1"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="board-application-reject-app-1"]').exists()).toBe(false)
    // …but the rows are still visible: seeing is not answering.
    expect(wrapper.find('[data-test="board-application-app-1"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('opens the SHARED accept dialog (the offer form), not a copy', async () => {
    const wrapper = await mountColumn()
    await wrapper.find('[data-test="board-application-accept-app-1"]').trigger('click')
    await flushPromises()

    const dialog = wrapper.findComponent({ name: 'AcceptApplicationDialog' })
    expect(dialog.exists()).toBe(true)
    expect(dialog.props('application')).toMatchObject({ id: 'app-1' })
    expect(dialog.props('campaignCurrency')).toBe('EUR')
    wrapper.unmount()
  })

  it('opens the SHARED reject dialog, not a copy', async () => {
    const wrapper = await mountColumn()
    await wrapper.find('[data-test="board-application-reject-app-2"]').trigger('click')
    await flushPromises()

    const dialog = wrapper.findComponent({ name: 'RejectApplicationDialog' })
    expect(dialog.exists()).toBe(true)
    expect(dialog.props('application')).toMatchObject({ id: 'app-2' })
    wrapper.unmount()
  })

  it('refetches BOTH surfaces after an accept — the list and the board (D4 motion)', async () => {
    const wrapper = await mountColumn()
    await wrapper.find('[data-test="board-application-accept-app-1"]').trigger('click')
    await flushPromises()

    const listCallsBefore = vi.mocked(campaignsApi.listApplications).mock.calls.length
    wrapper.findComponent({ name: 'AcceptApplicationDialog' }).vm.$emit('accepted', 'Offer sent')
    await flushPromises()

    expect(vi.mocked(campaignsApi.listApplications).mock.calls.length).toBe(listCallsBefore + 1)
    // The other half of the motion: the new `invited` card is fetched from the
    // board, produced by the listener + automation. This component creates none.
    expect(boardApi.show).toHaveBeenCalledWith('agency-ulid', 'campaign-ulid')
    expect(boardApi.moveCard).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('refetches both surfaces after a reject too', async () => {
    const wrapper = await mountColumn()
    await wrapper.find('[data-test="board-application-reject-app-1"]').trigger('click')
    await flushPromises()

    const listCallsBefore = vi.mocked(campaignsApi.listApplications).mock.calls.length
    wrapper.findComponent({ name: 'RejectApplicationDialog' }).vm.$emit('rejected', 'Rejected')
    await flushPromises()

    expect(vi.mocked(campaignsApi.listApplications).mock.calls.length).toBe(listCallsBefore + 1)
    expect(boardApi.show).toHaveBeenCalled()
    wrapper.unmount()
  })

  it('surfaces a dialog refusal in the column, where the operator is looking (§5.6)', async () => {
    const wrapper = await mountColumn()
    await wrapper.find('[data-test="board-application-reject-app-1"]').trigger('click')
    await flushPromises()

    wrapper
      .findComponent({ name: 'RejectApplicationDialog' })
      .vm.$emit('refused', 'Someone already answered this application.')
    await flushPromises()

    expect(wrapper.find('[data-test="board-applications-action-error"]').text()).toContain(
      'Someone already answered',
    )
    wrapper.unmount()
  })

  it('shows the load error when the first fetch fails', async () => {
    vi.mocked(campaignsApi.listApplications).mockRejectedValue(new Error('nope'))
    const wrapper = await mountColumn()
    expect(wrapper.find('[data-test="board-applications-error"]').exists()).toBe(true)
    wrapper.unmount()
  })

  // ── Profile access (AH-080, D2c) — the identity block, and only it ────────

  it("opens the profile dialog on the identity block, wired to that row's creator with assumeFull:true", async () => {
    const wrapper = await mountColumn()
    await wrapper.find('[data-test="board-application-profile-app-1"]').trigger('click')
    await flushPromises()

    const dialog = wrapper.findComponent({ name: 'CreatorProfileDialog' })
    expect(dialog.exists()).toBe(true)
    expect(dialog.props()).toMatchObject({
      agencyId: 'agency-ulid',
      creatorUlid: 'cr-app-1',
      assumeFull: true,
    })
    wrapper.unmount()
  })

  it('D5 — clicking Accept or Reject does NOT also open the profile dialog', async () => {
    const wrapper = await mountColumn()
    await wrapper.find('[data-test="board-application-accept-app-1"]').trigger('click')
    await flushPromises()
    expect(wrapper.findComponent({ name: 'CreatorProfileDialog' }).exists()).toBe(false)

    await wrapper.find('[data-test="board-application-reject-app-2"]').trigger('click')
    await flushPromises()
    expect(wrapper.findComponent({ name: 'CreatorProfileDialog' }).exists()).toBe(false)
    wrapper.unmount()
  })

  it('D5 — clicking the identity block does NOT open Accept/Reject (nothing wraps the card)', async () => {
    const wrapper = await mountColumn()
    await wrapper.find('[data-test="board-application-profile-app-1"]').trigger('click')
    await flushPromises()

    expect(wrapper.findComponent({ name: 'AcceptApplicationDialog' }).exists()).toBe(false)
    expect(wrapper.findComponent({ name: 'RejectApplicationDialog' }).exists()).toBe(false)
    wrapper.unmount()
  })

  // ── Message (AH-116, item 7) ───────────────────────────────────────────────────────────

  const messageBtn = (wrapper: Awaited<ReturnType<typeof mountColumn>>, id = 'app-1') =>
    wrapper.find(`[data-test="board-application-message-${id}"]`)

  it('AH-116: offers Message on a card whose creator the agency can message, and only that card', async () => {
    vi.mocked(relationshipMessagingApi.messageableCreators).mockResolvedValue(
      messageablePage(['cr-app-1']),
    )
    const wrapper = await mountColumn()

    const button = messageBtn(wrapper, 'app-1')
    expect(button.exists()).toBe(true)
    expect(button.text()).toBe('Message')
    expect(button.attributes('aria-label')).toBe('Message Ada')
    expect(button.attributes('href')).toBe('/messages/cr-app-1?name=Ada')
    expect(messageBtn(wrapper, 'app-2').exists()).toBe(false)
    // Beside the two answers, which stay on both cards.
    expect(wrapper.find('[data-test="board-application-accept-app-1"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="board-application-reject-app-2"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('AH-116: no Message for an erased applicant, even if the list names them', async () => {
    vi.mocked(relationshipMessagingApi.messageableCreators).mockResolvedValue(
      messageablePage(['cr-app-1']),
    )
    const erased = application('app-1', 'Ada')
    erased.attributes.creator = {
      ...erased.attributes.creator!,
      display_name: null,
      is_erased: true,
    }
    vi.mocked(campaignsApi.listApplications).mockResolvedValue(listPayload([erased]) as never)
    const wrapper = await mountColumn()

    expect(messageBtn(wrapper).exists()).toBe(false)
    wrapper.unmount()
  })

  it('AH-116: a viewer without the invite ability gets no Message, and no lookup is made', async () => {
    vi.mocked(relationshipMessagingApi.messageableCreators).mockResolvedValue(
      messageablePage(['cr-app-1']),
    )
    const wrapper = await mountColumn(false)

    expect(messageBtn(wrapper).exists()).toBe(false)
    expect(relationshipMessagingApi.messageableCreators).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('AH-116: an empty column makes no lookup', async () => {
    vi.mocked(campaignsApi.listApplications).mockResolvedValue(listPayload([]) as never)
    const wrapper = await mountColumn()

    expect(relationshipMessagingApi.messageableCreators).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('AH-116: a failing lookup hides Message (fails closed) and leaves Accept and Reject alone', async () => {
    vi.mocked(relationshipMessagingApi.messageableCreators).mockRejectedValue(new Error('boom'))
    const wrapper = await mountColumn()

    expect(messageBtn(wrapper).exists()).toBe(false)
    expect(wrapper.find('[data-test="board-application-accept-app-1"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="board-application-reject-app-1"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('AH-116: finds a creator on a later page, and stops at the cap (fails closed)', async () => {
    vi.mocked(relationshipMessagingApi.messageableCreators)
      .mockResolvedValueOnce(messageablePage(['cr-other'], 1, 2))
      .mockResolvedValueOnce(messageablePage(['cr-app-1'], 2, 2))
    const found = await mountColumn()
    expect(messageBtn(found).exists()).toBe(true)
    found.unmount()

    vi.mocked(relationshipMessagingApi.messageableCreators).mockReset()
    vi.mocked(relationshipMessagingApi.messageableCreators).mockImplementation(
      async (_agency, params) =>
        messageablePage(
          params?.page === MAX_PAGES + 1 ? ['cr-app-1'] : [`cr-filler-${params?.page}`],
          params?.page ?? 1,
          MAX_PAGES + 1,
        ),
    )
    const capped = await mountColumn()
    expect(relationshipMessagingApi.messageableCreators).toHaveBeenCalledTimes(MAX_PAGES)
    expect(messageBtn(capped).exists()).toBe(false)
    capped.unmount()
  })

  it('AH-116: clicking Message does NOT also open the profile dialog (D5)', async () => {
    vi.mocked(relationshipMessagingApi.messageableCreators).mockResolvedValue(
      messageablePage(['cr-app-1']),
    )
    const wrapper = await mountColumn()

    await messageBtn(wrapper).trigger('click')
    await flushPromises()

    expect(wrapper.findComponent({ name: 'CreatorProfileDialog' }).exists()).toBe(false)
    expect(wrapper.findComponent({ name: 'AcceptApplicationDialog' }).exists()).toBe(false)
    expect(wrapper.findComponent({ name: 'RejectApplicationDialog' }).exists()).toBe(false)
    wrapper.unmount()
  })
})
