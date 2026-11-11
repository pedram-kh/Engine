/**
 * Vitest coverage for the campaign-detail Applications tab (AH-058, D1).
 *
 * The two claims worth pinning here are the ones a reader of the component
 * cannot verify by eye: the badge count comes from the list's own
 * `pending_total` (never a row tally, and never the creator-facing
 * `applicant_count`), and a refused reject is SURFACED rather than swallowed.
 */

import { ApiError } from '@catalyst/api-client'
import type { CampaignApplicationListItemResource } from '@catalyst/api-client'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enApp from '@/core/i18n/locales/en/app.json'

vi.mock('../api/campaigns.api', () => ({
  campaignsApi: {
    listApplications: vi.fn(),
    rejectApplication: vi.fn(),
    acceptApplication: vi.fn(),
    offerAttachmentInit: vi.fn(),
    offerAttachmentComplete: vi.fn(),
  },
}))

// AH-116 (item 7): the Message action reads which creators the agency can message.
vi.mock('@/modules/messaging/api/relationshipMessaging.api', () => ({
  relationshipMessagingApi: { messageableCreators: vi.fn() },
}))

import { relationshipMessagingApi } from '@/modules/messaging/api/relationshipMessaging.api'

import { campaignsApi } from '../api/campaigns.api'
import { MAX_PAGES } from '@/modules/agency/composables/useMessageableCreatorIds'
import ApplicationsTab from './ApplicationsTab.vue'

const CREATOR_ULID = '01CREATORULIDXXXXXXXXXXXXX'
const PENDING_ID = '01APPPENDINGXXXXXXXXXXXXXX'
const ANSWERED_ID = '01APPREJECTEDXXXXXXXXXXXXX'

function makeRow(
  id = PENDING_ID,
  status: CampaignApplicationListItemResource['attributes']['status'] = 'pending',
  note: string | null = 'I have shot three campaigns for this brand.',
): CampaignApplicationListItemResource {
  return {
    id,
    type: 'campaign_application_list_item',
    attributes: {
      status,
      note,
      applied_at: '2026-07-01T10:00:00.000000Z',
      responded_at: status === 'pending' ? null : '2026-07-02T10:00:00.000000Z',
      creator: {
        id: '01CREATORULIDXXXXXXXXXXXXX',
        display_name: 'Maria Lopez',
        avatar_url: null,
      },
    },
  }
}

function listResponse(rows: CampaignApplicationListItemResource[], pendingTotal = 1, lastPage = 1) {
  return {
    data: rows,
    meta: {
      total: rows.length,
      page: 1,
      per_page: 25,
      last_page: lastPage,
      pending_total: pendingTotal,
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

async function mountTab(canAct = true) {
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

  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    availableLocales: ['en'],
    messages: { en: enApp } as never,
  }) as unknown as ReturnType<typeof createI18n>

  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })

  const wrapper = mount(ApplicationsTab, {
    props: {
      agencyId: 'agency-ulid',
      campaignId: 'campaign-ulid',
      canAct,
      campaignCurrency: 'EUR',
    },
    global: {
      plugins: [i18n, vuetify, router],
      // CreatorProfileDialog's own rendering is CreatorProfileContent.spec.ts's
      // job; here we only pin the click wiring (D2c), so a real mount never
      // fires an unmocked roster/discover network call.
      stubs: { CreatorProfileDialog: true },
    },
    attachTo: document.createElement('div'),
  })
  await flushPromises()
  return wrapper
}

describe('ApplicationsTab', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(campaignsApi.listApplications).mockResolvedValue(listResponse([makeRow()], 3))
    vi.mocked(campaignsApi.rejectApplication).mockResolvedValue({
      data: {
        id: PENDING_ID,
        type: 'campaign_application',
        attributes: { status: 'rejected', responded_at: '2026-07-02T10:00:00.000000Z' },
      },
      meta: { code: 'application.rejected' },
    })
    // Default: nobody is messageable, so every existing test keeps its current expectation.
    vi.mocked(relationshipMessagingApi.messageableCreators).mockResolvedValue(messageablePage([]))
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('loads applications on mount and renders the row with its note', async () => {
    const wrapper = await mountTab()

    expect(campaignsApi.listApplications).toHaveBeenCalledWith('agency-ulid', 'campaign-ulid', {
      page: 1,
      per_page: 25,
    })
    expect(wrapper.find(`[data-test="applications-row-${PENDING_ID}"]`).exists()).toBe(true)
    expect(wrapper.find(`[data-test="applications-note-${PENDING_ID}"]`).text()).toContain(
      'three campaigns',
    )
    wrapper.unmount()
  })

  it('shows "Deleted creator" with no photo for an erased applicant', async () => {
    const row = makeRow()
    row.attributes.creator = {
      id: '01CREATORULIDXXXXXXXXXXXXX',
      display_name: 'Leaked Name',
      avatar_url: 'https://cdn/leak.jpg',
      is_erased: true,
    }
    vi.mocked(campaignsApi.listApplications).mockResolvedValue(listResponse([row], 1))
    const wrapper = await mountTab()

    const el = wrapper.find(`[data-test="applications-row-${PENDING_ID}"]`)
    expect(el.text()).toContain(enApp.app.creatorErased)
    expect(el.text()).not.toContain('Leaked Name')
    expect(el.findComponent({ name: 'VImg' }).exists()).toBe(false)
    wrapper.unmount()
  })

  it('hoists pending_total — the badge count, not a tally of the rendered rows', async () => {
    const wrapper = await mountTab()

    // The page renders ONE row while three applications are pending: the badge
    // is campaign-wide, so it can never be derived from the current page.
    expect(wrapper.emitted('pending-total')?.[0]?.[0]).toBe(3)
    wrapper.unmount()
  })

  it('re-fetches with the status filter and resets to page 1', async () => {
    const wrapper = await mountTab()
    vi.mocked(campaignsApi.listApplications).mockClear()

    await wrapper.findComponent({ name: 'VSelect' }).setValue('rejected')
    await flushPromises()

    expect(campaignsApi.listApplications).toHaveBeenCalledWith('agency-ulid', 'campaign-ulid', {
      page: 1,
      per_page: 25,
      status: 'rejected',
    })
    wrapper.unmount()
  })

  it('offers accept + reject on a PENDING row only', async () => {
    vi.mocked(campaignsApi.listApplications).mockResolvedValue(
      listResponse([makeRow(PENDING_ID), makeRow(ANSWERED_ID, 'rejected', null)], 1),
    )
    const wrapper = await mountTab()

    expect(wrapper.find(`[data-test="applications-accept-${PENDING_ID}"]`).exists()).toBe(true)
    expect(wrapper.find(`[data-test="applications-reject-${PENDING_ID}"]`).exists()).toBe(true)
    // An answered application is history: no second answer is offered.
    expect(wrapper.find(`[data-test="applications-accept-${ANSWERED_ID}"]`).exists()).toBe(false)
    expect(wrapper.find(`[data-test="applications-reject-${ANSWERED_ID}"]`).exists()).toBe(false)
    wrapper.unmount()
  })

  it('hides both actions for a viewer without the execute ability', async () => {
    const wrapper = await mountTab(false)

    expect(wrapper.find(`[data-test="applications-accept-${PENDING_ID}"]`).exists()).toBe(false)
    expect(wrapper.find(`[data-test="applications-reject-${PENDING_ID}"]`).exists()).toBe(false)
    wrapper.unmount()
  })

  it('rejects only after the confirmation dialog is confirmed', async () => {
    const wrapper = await mountTab()

    await wrapper.find(`[data-test="applications-reject-${PENDING_ID}"]`).trigger('click')
    await flushPromises()

    // The click alone must not answer an application — reject is terminal and
    // the creator cannot re-apply afterwards.
    expect(campaignsApi.rejectApplication).not.toHaveBeenCalled()
    expect(document.querySelector('[data-test="reject-application-dialog"]')).not.toBeNull()

    document.querySelector<HTMLElement>('[data-test="reject-application-confirm"]')?.click()
    await flushPromises()

    expect(campaignsApi.rejectApplication).toHaveBeenCalledWith(
      'agency-ulid',
      'campaign-ulid',
      PENDING_ID,
    )
    expect(wrapper.emitted('answered')).toBeTruthy()
    wrapper.unmount()
  })

  it('SURFACES a refused reject instead of swallowing it', async () => {
    vi.mocked(campaignsApi.rejectApplication).mockRejectedValue(
      new ApiError({
        status: 422,
        code: 'http.invalid_response_body',
        message: 'already answered',
        raw: { meta: { code: 'application.not_pending' } },
      }),
    )
    const wrapper = await mountTab()

    await wrapper.find(`[data-test="applications-reject-${PENDING_ID}"]`).trigger('click')
    await flushPromises()
    document.querySelector<HTMLElement>('[data-test="reject-application-confirm"]')?.click()
    await flushPromises()

    // Someone else answered it first (§5.6). The operator is told which, rather
    // than watching a button appear to do nothing.
    expect(wrapper.find('[data-test="applications-action-error"]').text()).toBe(
      enApp.app.campaigns.applications.refusal.application.not_pending,
    )
    expect(wrapper.emitted('answered')).toBeFalsy()
    wrapper.unmount()
  })

  it('opens the accept dialog mounted only on demand', async () => {
    const wrapper = await mountTab()

    expect(document.querySelector('[data-test="accept-application-dialog"]')).toBeNull()

    await wrapper.find(`[data-test="applications-accept-${PENDING_ID}"]`).trigger('click')
    await flushPromises()

    expect(document.querySelector('[data-test="accept-application-dialog"]')).not.toBeNull()
    wrapper.unmount()
  })

  it('renders the empty state when nobody has applied', async () => {
    vi.mocked(campaignsApi.listApplications).mockResolvedValue(listResponse([], 0))
    const wrapper = await mountTab()

    expect(wrapper.find('[data-test="applications-empty-state"]').exists()).toBe(true)
    expect(wrapper.emitted('pending-total')?.[0]?.[0]).toBe(0)
    wrapper.unmount()
  })

  it('shows the load-error alert when the list call fails', async () => {
    vi.mocked(campaignsApi.listApplications).mockRejectedValue(new Error('boom'))
    const wrapper = await mountTab()

    expect(wrapper.find('[data-test="applications-load-error"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('reloads when expose.reload is called', async () => {
    const wrapper = await mountTab()
    vi.mocked(campaignsApi.listApplications).mockClear()

    await (wrapper.vm as { reload: () => Promise<void> }).reload()
    await flushPromises()

    expect(campaignsApi.listApplications).toHaveBeenCalled()
    wrapper.unmount()
  })

  // ── Profile access (AH-080, D2c) — the identity block, and only it ────────

  it("opens the profile dialog from the avatar, wired to that row's creator with assumeFull:true", async () => {
    const wrapper = await mountTab()

    await wrapper.find(`[data-test="applications-profile-${PENDING_ID}"]`).trigger('click')
    await flushPromises()

    const dialog = wrapper.findComponent({ name: 'CreatorProfileDialog' })
    expect(dialog.exists()).toBe(true)
    expect(dialog.props()).toMatchObject({
      agencyId: 'agency-ulid',
      creatorUlid: '01CREATORULIDXXXXXXXXXXXXX',
      assumeFull: true,
    })
    wrapper.unmount()
  })

  it('opens the profile dialog from the name text too — both halves of the identity block', async () => {
    const wrapper = await mountTab()

    await wrapper
      .find(`[data-test="applications-row-${PENDING_ID}"] .applications-identity`)
      .trigger('click')
    await flushPromises()

    expect(wrapper.findComponent({ name: 'CreatorProfileDialog' }).exists()).toBe(true)
    wrapper.unmount()
  })

  it('D5 — clicking Accept or Reject does NOT also open the profile dialog', async () => {
    const wrapper = await mountTab()

    await wrapper.find(`[data-test="applications-reject-${PENDING_ID}"]`).trigger('click')
    await flushPromises()
    expect(wrapper.findComponent({ name: 'CreatorProfileDialog' }).exists()).toBe(false)
    wrapper.unmount()
  })

  // ── Message (AH-116, item 7) ───────────────────────────────────────────────────────────

  const messageBtn = (wrapper: Awaited<ReturnType<typeof mountTab>>, id = PENDING_ID) =>
    wrapper.find(`[data-test="applications-message-${id}"]`)

  it('AH-116: offers Message on a pending row whose creator the agency can message', async () => {
    vi.mocked(relationshipMessagingApi.messageableCreators).mockResolvedValue(
      messageablePage([CREATOR_ULID]),
    )
    const wrapper = await mountTab()

    const button = messageBtn(wrapper)
    expect(button.exists()).toBe(true)
    expect(button.text()).toBe('Message')
    expect(button.attributes('aria-label')).toBe('Message Maria Lopez')
    expect(button.attributes('href')).toBe(`/messages/${CREATOR_ULID}?name=Maria+Lopez`)
    // Beside the two answers, which stay.
    expect(wrapper.find(`[data-test="applications-accept-${PENDING_ID}"]`).exists()).toBe(true)
    expect(wrapper.find(`[data-test="applications-reject-${PENDING_ID}"]`).exists()).toBe(true)
    expect(relationshipMessagingApi.messageableCreators).toHaveBeenCalledWith('agency-ulid', {
      page: 1,
      perPage: 100,
    })
    wrapper.unmount()
  })

  it('AH-116: hides Message when the creator is not messageable (not on the roster, blacklisted, ...)', async () => {
    vi.mocked(relationshipMessagingApi.messageableCreators).mockResolvedValue(
      messageablePage(['01SOMEONEELSEXXXXXXXXXXXXX']),
    )
    const wrapper = await mountTab()

    expect(messageBtn(wrapper).exists()).toBe(false)
    expect(wrapper.find(`[data-test="applications-accept-${PENDING_ID}"]`).exists()).toBe(true)
    wrapper.unmount()
  })

  it('AH-116: no Message on an accepted or rejected row, only on a pending one', async () => {
    vi.mocked(relationshipMessagingApi.messageableCreators).mockResolvedValue(
      messageablePage([CREATOR_ULID]),
    )
    vi.mocked(campaignsApi.listApplications).mockResolvedValue(
      listResponse(
        [
          makeRow(PENDING_ID),
          makeRow(ANSWERED_ID, 'rejected', null),
          makeRow('01APPACCEPTEDXXXXXXXXXXXXXX', 'accepted', null),
        ],
        1,
      ),
    )
    const wrapper = await mountTab()

    expect(messageBtn(wrapper, PENDING_ID).exists()).toBe(true)
    expect(messageBtn(wrapper, ANSWERED_ID).exists()).toBe(false)
    expect(messageBtn(wrapper, '01APPACCEPTEDXXXXXXXXXXXXXX').exists()).toBe(false)
    wrapper.unmount()
  })

  it('AH-116: no Message for an erased applicant, even if the list names them', async () => {
    vi.mocked(relationshipMessagingApi.messageableCreators).mockResolvedValue(
      messageablePage([CREATOR_ULID]),
    )
    const row = makeRow()
    row.attributes.creator = { ...row.attributes.creator!, display_name: null, is_erased: true }
    vi.mocked(campaignsApi.listApplications).mockResolvedValue(listResponse([row], 1))
    const wrapper = await mountTab()

    expect(messageBtn(wrapper).exists()).toBe(false)
    wrapper.unmount()
  })

  it('AH-116: a viewer without the execute ability gets no Message, and no lookup is made', async () => {
    vi.mocked(relationshipMessagingApi.messageableCreators).mockResolvedValue(
      messageablePage([CREATOR_ULID]),
    )
    const wrapper = await mountTab(false)

    expect(messageBtn(wrapper).exists()).toBe(false)
    expect(relationshipMessagingApi.messageableCreators).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('AH-116: with no pending row there is nothing to put a button on, so no lookup', async () => {
    vi.mocked(campaignsApi.listApplications).mockResolvedValue(
      listResponse([makeRow(ANSWERED_ID, 'rejected', null)], 0),
    )
    const wrapper = await mountTab()

    expect(relationshipMessagingApi.messageableCreators).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('AH-116: a failing lookup hides Message (fails closed) and leaves Accept and Reject alone', async () => {
    vi.mocked(relationshipMessagingApi.messageableCreators).mockRejectedValue(new Error('boom'))
    const wrapper = await mountTab()

    expect(messageBtn(wrapper).exists()).toBe(false)
    expect(wrapper.find(`[data-test="applications-accept-${PENDING_ID}"]`).exists()).toBe(true)
    expect(wrapper.find(`[data-test="applications-reject-${PENDING_ID}"]`).exists()).toBe(true)
    expect(wrapper.find('[data-test="applications-action-error"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('AH-116: finds a creator on a later page of the messageable list', async () => {
    vi.mocked(relationshipMessagingApi.messageableCreators)
      .mockResolvedValueOnce(messageablePage(['01OTHERXXXXXXXXXXXXXXXXXXX'], 1, 2))
      .mockResolvedValueOnce(messageablePage([CREATOR_ULID], 2, 2))
    const wrapper = await mountTab()

    expect(messageBtn(wrapper).exists()).toBe(true)
    expect(relationshipMessagingApi.messageableCreators).toHaveBeenCalledTimes(2)
    expect(relationshipMessagingApi.messageableCreators).toHaveBeenLastCalledWith('agency-ulid', {
      page: 2,
      perPage: 100,
    })
    wrapper.unmount()
  })

  it('AH-116: a creator past the page cap is not offered Message (fails closed)', async () => {
    // 11 pages, the creator only on the last: the lookup stops at 10, so they are never seen.
    vi.mocked(relationshipMessagingApi.messageableCreators).mockImplementation(
      async (_agency, params) =>
        messageablePage(
          params?.page === MAX_PAGES + 1 ? [CREATOR_ULID] : [`01FILLER${params?.page}`],
          params?.page ?? 1,
          MAX_PAGES + 1,
        ),
    )
    const wrapper = await mountTab()

    expect(relationshipMessagingApi.messageableCreators).toHaveBeenCalledTimes(MAX_PAGES)
    expect(messageBtn(wrapper).exists()).toBe(false)
    wrapper.unmount()
  })

  it('AH-116: reads the messageable list once per mount, not once per reload', async () => {
    vi.mocked(relationshipMessagingApi.messageableCreators).mockResolvedValue(
      messageablePage([CREATOR_ULID]),
    )
    const wrapper = await mountTab()

    await (wrapper.vm as { reload: () => Promise<void> }).reload()
    await flushPromises()

    expect(relationshipMessagingApi.messageableCreators).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })

  it("AH-116: Message is a link to that creator's thread, and clicking it does not open the profile dialog (D5)", async () => {
    vi.mocked(relationshipMessagingApi.messageableCreators).mockResolvedValue(
      messageablePage([CREATOR_ULID]),
    )
    const wrapper = await mountTab()

    const thread = router.resolve({
      name: 'messages.thread',
      params: { creatorUlid: CREATOR_ULID },
      query: { name: 'Maria Lopez' },
    })
    expect(messageBtn(wrapper).attributes('href')).toBe(thread.href)

    await messageBtn(wrapper).trigger('click')
    await flushPromises()
    expect(wrapper.findComponent({ name: 'CreatorProfileDialog' }).exists()).toBe(false)
    wrapper.unmount()
  })
})

describe('ApplicationsTab — brand exclusivity conflict (AH-120)', () => {
  const CONFLICTED = '01APPCONFLICTEDXXXXXXXXXXX'
  const PLAIN = '01APPPLAINXXXXXXXXXXXXXXX'

  function withConflict(row: CampaignApplicationListItemResource) {
    row.attributes.has_exclusivity_conflict = true
    row.attributes.exclusivity_conflict = { id: 'camp-zava', name: 'Zava Summer' }
    return row
  }

  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(relationshipMessagingApi.messageableCreators).mockResolvedValue(
      messageablePage([]) as never,
    )
  })

  it('badges a pending row that has a conflict, naming the blocking campaign, and keeps Accept enabled', async () => {
    vi.mocked(campaignsApi.listApplications).mockResolvedValue(
      listResponse([withConflict(makeRow(CONFLICTED)), makeRow(PLAIN)], 2) as never,
    )
    const wrapper = await mountTab()

    const chip = wrapper.find(`[data-test="applications-conflict-${CONFLICTED}"]`)
    expect(chip.exists()).toBe(true)
    expect(chip.text()).toContain('Conflict')

    // The tooltip names the blocking campaign (rendered on hover, teleported to the body).
    await chip.trigger('mouseenter')
    await new Promise((r) => setTimeout(r, 400))
    await flushPromises()
    expect(document.body.textContent).toContain('Selected for Zava Summer')
    expect(wrapper.find(`[data-test="applications-conflict-${PLAIN}"]`).exists()).toBe(false)

    const accept = wrapper.find(`[data-test="applications-accept-${CONFLICTED}"]`)
    expect(accept.exists()).toBe(true)
    expect(accept.attributes('disabled')).toBeUndefined()
    wrapper.unmount()
  })

  it('never badges an answered row, even if the payload carries the flag', async () => {
    vi.mocked(campaignsApi.listApplications).mockResolvedValue(
      listResponse([withConflict(makeRow(ANSWERED_ID, 'rejected'))], 0) as never,
    )
    const wrapper = await mountTab()

    expect(wrapper.find(`[data-test="applications-conflict-${ANSWERED_ID}"]`).exists()).toBe(false)
    wrapper.unmount()
  })

  it('shows no badge when the payload has no conflict fields (flag off)', async () => {
    vi.mocked(campaignsApi.listApplications).mockResolvedValue(
      listResponse([makeRow(PLAIN)], 1) as never,
    )
    const wrapper = await mountTab()

    expect(wrapper.find('[data-test^="applications-conflict-"]').exists()).toBe(false)
    wrapper.unmount()
  })
})
