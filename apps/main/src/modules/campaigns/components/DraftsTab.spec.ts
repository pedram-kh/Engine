/**
 * Vitest coverage for the campaign-wide Drafts tab panel.
 */

import type {
  CampaignAssignmentResource,
  CampaignDraftListItemResource,
} from '@catalyst/api-client'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enApp from '@/core/i18n/locales/en/app.json'

vi.mock('../api/campaigns.api', () => ({
  campaignsApi: {
    listDrafts: vi.fn(),
  },
}))

import { campaignsApi } from '../api/campaigns.api'
import DraftsTab from './DraftsTab.vue'

const ASSIGNMENT_ID = '01ASSIGNULIDXXXXXXXXXXXXXX'
const DRAFT_ID = '01DRAFTULIDXXXXXXXXXXXXXXX'

function makeRow(
  reviewStatus: CampaignDraftListItemResource['attributes']['review_status'] = 'pending',
): CampaignDraftListItemResource {
  return {
    id: DRAFT_ID,
    type: 'campaign_draft_list_item',
    attributes: {
      version: 1,
      review_status: reviewStatus,
      submitted_at: '2026-06-01T10:00:00.000000Z',
      review_feedback: null,
      assignment: {
        id: ASSIGNMENT_ID,
        status: 'draft_submitted',
        creator: { id: 'creator-ulid', display_name: 'Alex Creator' },
      },
    },
  }
}

async function mountTab(canReview = true) {
  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    availableLocales: ['en'],
    messages: { en: enApp } as never,
  }) as unknown as ReturnType<typeof createI18n>

  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })

  const wrapper = mount(DraftsTab, {
    props: {
      agencyId: 'agency-ulid',
      campaignId: 'campaign-ulid',
      canReview,
    },
    global: { plugins: [i18n, vuetify] },
    attachTo: document.createElement('div'),
  })
  await flushPromises()
  return wrapper
}

describe('DraftsTab', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(campaignsApi.listDrafts).mockResolvedValue({
      data: [makeRow()],
      meta: { total: 1, page: 1, per_page: 25, last_page: 1 },
    })
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('loads drafts on mount', async () => {
    const wrapper = await mountTab()
    expect(campaignsApi.listDrafts).toHaveBeenCalledWith('agency-ulid', 'campaign-ulid', {
      page: 1,
      per_page: 25,
    })
    expect(wrapper.find('[data-test="drafts-row-01DRAFTULIDXXXXXXXXXXXXXXX"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('re-fetches when the review_status filter changes', async () => {
    const wrapper = await mountTab()
    vi.mocked(campaignsApi.listDrafts).mockClear()

    await wrapper.findComponent({ name: 'VSelect' }).setValue('approved')
    await flushPromises()

    expect(campaignsApi.listDrafts).toHaveBeenCalledWith('agency-ulid', 'campaign-ulid', {
      page: 1,
      per_page: 25,
      review_status: 'approved',
    })
    wrapper.unmount()
  })

  it('emits open-review with an assignment stub when Review is clicked', async () => {
    const wrapper = await mountTab()
    await wrapper.find('[data-test="drafts-review-01DRAFTULIDXXXXXXXXXXXXXXX"]').trigger('click')

    const emitted = wrapper.emitted('open-review')?.[0]?.[0] as CampaignAssignmentResource
    expect(emitted.id).toBe(ASSIGNMENT_ID)
    expect(emitted.attributes.status).toBe('draft_submitted')
    expect(emitted.attributes.creator?.display_name).toBe('Alex Creator')
    wrapper.unmount()
  })

  it('hides the review action when canReview is false', async () => {
    const wrapper = await mountTab(false)
    expect(wrapper.find('[data-test="drafts-review-01DRAFTULIDXXXXXXXXXXXXXXX"]').exists()).toBe(
      false,
    )
    wrapper.unmount()
  })

  it('reloads when expose.reload is called', async () => {
    const wrapper = await mountTab()
    vi.mocked(campaignsApi.listDrafts).mockClear()

    await (wrapper.vm as { reload: () => Promise<void> }).reload()
    await flushPromises()

    expect(campaignsApi.listDrafts).toHaveBeenCalled()
    wrapper.unmount()
  })
})
