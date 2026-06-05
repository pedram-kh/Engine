/**
 * Verification-resolution chunk — Vitest coverage for the agency resolution
 * drawer. Pins: loads the agency-side detail on open + renders the failed post,
 * the three actions call the right endpoints + emit `resolved`, and a
 * reason-required 422 binds onto the note textarea.
 */

import {
  ApiError,
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
  campaignsApi: {
    showAssignment: vi.fn(),
    manuallyVerify: vi.fn(),
    requestResubmitFresh: vi.fn(),
    requestResubmitInPlace: vi.fn(),
  },
}))

import { campaignsApi } from '../api/campaigns.api'
import ResolveVerificationDrawer from './ResolveVerificationDrawer.vue'

const ASSIGNMENT_ID = '01ASSIGNULIDXXXXXXXXXXXXXX'

function makeAssignment(): CampaignAssignmentResource {
  return {
    id: ASSIGNMENT_ID,
    type: 'campaign_assignments',
    attributes: {
      status: 'posted',
      agreed_fee_minor_units: 100000,
      agreed_fee_currency: 'EUR',
      countered_fee_minor_units: null,
      countered_fee_currency: null,
      invited_at: null,
      responded_at: null,
      posting_due_at: null,
      verification_status: 'mismatch',
      has_pending_contract: null,
      creator: { id: 'creator-ulid', display_name: 'Alex Creator' },
    },
  }
}

function makePost(): CampaignPostedContentResource {
  return {
    id: 'post-1',
    type: 'campaign_posted_content',
    attributes: {
      platform: 'instagram',
      post_url: 'https://instagram.com/someoneelse/p/abc',
      platform_post_id: null,
      posted_at: '2026-06-01T10:00:00.000000Z',
      verified_at: null,
      verification_status: 'mismatch',
    },
  }
}

function makeDetail(): AgencyAssignmentDetailResource {
  return {
    id: ASSIGNMENT_ID,
    type: 'campaign_assignment',
    attributes: {
      status: 'posted',
      agreed_fee_minor_units: 100000,
      agreed_fee_currency: 'EUR',
      posting_due_at: null,
      submitted_draft_at: '2026-06-01T10:00:00.000000Z',
      approved_at: '2026-06-02T10:00:00.000000Z',
      posted_at: '2026-06-03T10:00:00.000000Z',
      verified_live_at: null,
      creator: { id: 'creator-ulid', display_name: 'Alex Creator' },
      campaign: { id: 'campaign-ulid', name: 'Summer launch', brand_name: 'Acme' },
    },
    relationships: {
      drafts: [],
      posted_content: [makePost()],
    },
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

  const wrapper = mount(ResolveVerificationDrawer, {
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

describe('ResolveVerificationDrawer (verification-resolution chunk)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(campaignsApi.showAssignment).mockResolvedValue({ data: makeDetail() })
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('loads the agency detail on open and renders the failed post URL', async () => {
    const wrapper = await mountOpen()
    expect(campaignsApi.showAssignment).toHaveBeenCalledWith(
      'agency-ulid',
      'campaign-ulid',
      ASSIGNMENT_ID,
    )
    expect(wrapper.find('[data-test="resolution-post-url"]').text()).toContain('someoneelse')
    wrapper.unmount()
  })

  it('manually verify calls manuallyVerify with the reason + emits resolved + closes', async () => {
    vi.mocked(campaignsApi.manuallyVerify).mockResolvedValue({ data: makeAssignment() })
    const wrapper = await mountOpen()

    await wrapper.find('[data-test="resolution-note"] textarea').setValue('Live and on-brief.')
    await wrapper.find('[data-test="resolution-manually-verify"]').trigger('click')
    await flushPromises()

    expect(campaignsApi.manuallyVerify).toHaveBeenCalledWith(
      'agency-ulid',
      'campaign-ulid',
      ASSIGNMENT_ID,
      { reason: 'Live and on-brief.' },
    )
    expect(wrapper.emitted('resolved')).toHaveLength(1)
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([false])
    wrapper.unmount()
  })

  it('fresh resubmit sends the feedback (null when blank)', async () => {
    vi.mocked(campaignsApi.requestResubmitFresh).mockResolvedValue({ data: makeAssignment() })
    const wrapper = await mountOpen()

    await wrapper.find('[data-test="resolution-request-fresh"]').trigger('click')
    await flushPromises()

    expect(campaignsApi.requestResubmitFresh).toHaveBeenCalledWith(
      'agency-ulid',
      'campaign-ulid',
      ASSIGNMENT_ID,
      { feedback: null },
    )
    wrapper.unmount()
  })

  it('in-place request (the nudge) calls requestResubmitInPlace', async () => {
    vi.mocked(campaignsApi.requestResubmitInPlace).mockResolvedValue({ data: makeAssignment() })
    const wrapper = await mountOpen()

    await wrapper.find('[data-test="resolution-note"] textarea').setValue('The link 404s.')
    await wrapper.find('[data-test="resolution-request-in-place"]').trigger('click')
    await flushPromises()

    expect(campaignsApi.requestResubmitInPlace).toHaveBeenCalledWith(
      'agency-ulid',
      'campaign-ulid',
      ASSIGNMENT_ID,
      { feedback: 'The link 404s.' },
    )
    wrapper.unmount()
  })

  it('binds a reason-required 422 onto the note textarea', async () => {
    vi.mocked(campaignsApi.manuallyVerify).mockRejectedValue(
      new ApiError({
        status: 422,
        code: 'validation.failed',
        message: 'Validation failed.',
        details: [
          {
            code: 'validation.required',
            detail: 'The reason field is required.',
            source: { pointer: '/data/attributes/reason' },
          },
        ],
      }),
    )
    const wrapper = await mountOpen()

    await wrapper.find('[data-test="resolution-manually-verify"]').trigger('click')
    await flushPromises()

    const textarea = wrapper.findComponent({ name: 'VTextarea' })
    expect(textarea.props('errorMessages')).toContain('The reason field is required.')
    expect(wrapper.emitted('resolved')).toBeUndefined()
    wrapper.unmount()
  })
})
