/**
 * Posted-content visibility chunk — Vitest coverage for the agency read-only
 * "view posted content" drawer. Pins: loads the agency detail on open, renders
 * the post URL / platform / verification status, flags the newest row as
 * "current", and surfaces a load error.
 */

import {
  type AgencyAssignmentDetailResource,
  type CampaignAssignmentResource,
  type CampaignPostedContentResource,
} from '@catalyst/api-client'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enApp from '@/core/i18n/locales/en/app.json'

vi.mock('../api/campaigns.api', () => ({
  campaignsApi: { showAssignment: vi.fn() },
}))

import { campaignsApi } from '../api/campaigns.api'
import ViewPostedContentDrawer from './ViewPostedContentDrawer.vue'

const ASSIGNMENT_ID = '01ASSIGNULIDXXXXXXXXXXXXXX'

function makeAssignment(): CampaignAssignmentResource {
  return {
    id: ASSIGNMENT_ID,
    type: 'campaign_assignments',
    attributes: {
      status: 'live_verified',
      agreed_fee_minor_units: 100000,
      agreed_fee_currency: 'EUR',
      countered_fee_minor_units: null,
      countered_fee_currency: null,
      invited_at: null,
      responded_at: null,
      posting_due_at: null,
      verification_status: 'verified',
      has_pending_contract: null,
      creator: { id: 'creator-ulid', display_name: 'Alex Creator' },
    },
  }
}

function makePost(
  id: string,
  overrides: Partial<CampaignPostedContentResource['attributes']> = {},
): CampaignPostedContentResource {
  return {
    id,
    type: 'campaign_posted_content',
    attributes: {
      platform: 'tiktok',
      post_url: `https://tiktok.com/@creator/video/${id}`,
      platform_post_id: null,
      posted_at: '2026-06-03T10:00:00.000000Z',
      verified_at: '2026-06-03T11:00:00.000000Z',
      verification_status: 'verified',
      ...overrides,
    },
  }
}

function makeDetail(posts: CampaignPostedContentResource[]): AgencyAssignmentDetailResource {
  return {
    id: ASSIGNMENT_ID,
    type: 'campaign_assignment',
    attributes: {
      status: 'live_verified',
      agreed_fee_minor_units: 100000,
      agreed_fee_currency: 'EUR',
      posting_due_at: null,
      submitted_draft_at: '2026-06-01T10:00:00.000000Z',
      approved_at: '2026-06-02T10:00:00.000000Z',
      posted_at: '2026-06-03T10:00:00.000000Z',
      verified_live_at: '2026-06-03T11:00:00.000000Z',
      creator: { id: 'creator-ulid', display_name: 'Alex Creator' },
      campaign: { id: 'campaign-ulid', name: 'Summer launch', brand_name: 'Acme' },
    },
    relationships: { drafts: [], posted_content: posts },
  }
}

const VDialogStub = {
  name: 'VDialog',
  props: ['modelValue'],
  template: '<div class="vdialog-stub"><slot /></div>',
}

async function mountOpen() {
  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    availableLocales: ['en'],
    messages: { en: enApp } as never,
  }) as unknown as ReturnType<typeof createI18n>

  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })

  const wrapper = mount(ViewPostedContentDrawer, {
    props: {
      modelValue: false,
      agencyId: 'agency-ulid',
      campaignId: 'campaign-ulid',
      assignment: makeAssignment(),
    },
    global: {
      plugins: [i18n, vuetify],
      stubs: { VDialog: VDialogStub },
    },
    attachTo: document.createElement('div'),
  })

  await wrapper.setProps({ modelValue: true })
  await flushPromises()
  return wrapper
}

describe('ViewPostedContentDrawer (posted-content visibility chunk)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(campaignsApi.showAssignment).mockResolvedValue({ data: makeDetail([makePost('1')]) })
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('loads the agency detail on open and renders the post URL + platform', async () => {
    const wrapper = await mountOpen()
    expect(campaignsApi.showAssignment).toHaveBeenCalledWith(
      'agency-ulid',
      'campaign-ulid',
      ASSIGNMENT_ID,
    )
    expect(wrapper.find('[data-test="view-post-url-0"]').text()).toContain('tiktok.com')
    expect(wrapper.find('[data-test="view-post-status-0"]').text()).toBe(
      enApp.app.campaigns.viewPost.verification.verified,
    )
    wrapper.unmount()
  })

  it('flags the newest row as current and lists prior posts as history', async () => {
    vi.mocked(campaignsApi.showAssignment).mockResolvedValue({
      data: makeDetail([
        makePost('2', { verification_status: 'verified' }),
        makePost('1', { verification_status: 'mismatch', verified_at: null }),
      ]),
    })
    const wrapper = await mountOpen()

    expect(wrapper.find('[data-test="view-post-current"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="view-post-row-0"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="view-post-row-1"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="view-post-status-1"]').text()).toBe(
      enApp.app.campaigns.viewPost.verification.mismatch,
    )
    wrapper.unmount()
  })

  it('shows a load error when the detail request fails', async () => {
    vi.mocked(campaignsApi.showAssignment).mockRejectedValue(new Error('boom'))
    const wrapper = await mountOpen()
    expect(wrapper.find('[data-test="view-post-load-error"]').exists()).toBe(true)
    wrapper.unmount()
  })
})
