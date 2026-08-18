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

import { campaignsApi } from '../api/campaigns.api'
import ApplicationsTab from './ApplicationsTab.vue'

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

async function mountTab(canAct = true) {
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
      plugins: [i18n, vuetify],
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
})
