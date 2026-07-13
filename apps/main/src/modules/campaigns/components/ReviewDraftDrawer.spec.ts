/**
 * Sprint 9 Chunk 2 (D-8) — Vitest coverage for the agency review drawer.
 * Pins: loads the agency-side detail on open, renders the draft preview, the
 * three actions call the right endpoints + emit `reviewed`, and a
 * feedback-required 422 binds onto the review_feedback textarea.
 */

import {
  ApiError,
  type AgencyAssignmentDetailResource,
  type CampaignAssignmentResource,
  type CampaignDraftResource,
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
    approveDraft: vi.fn(),
    requestRevision: vi.fn(),
    rejectDraft: vi.fn(),
  },
}))

import { campaignsApi } from '../api/campaigns.api'
import ReviewDraftDrawer from './ReviewDraftDrawer.vue'

const ASSIGNMENT_ID = '01ASSIGNULIDXXXXXXXXXXXXXX'

function makeAssignment(): CampaignAssignmentResource {
  return {
    id: ASSIGNMENT_ID,
    type: 'campaign_assignments',
    attributes: {
      status: 'draft_submitted',
      agreed_fee_minor_units: 100000,
      agreed_fee_currency: 'EUR',
      countered_fee_minor_units: null,
      countered_fee_currency: null,
      invited_at: null,
      responded_at: null,
      posting_due_at: null,
      verification_status: null,
      has_pending_contract: null,
      creator: { id: 'creator-ulid', display_name: 'Alex Creator' },
    },
  }
}

function makeDraft(): CampaignDraftResource {
  return {
    id: 'draft-1',
    type: 'campaign_draft',
    attributes: {
      version: 1,
      submitted_at: '2026-06-01T10:00:00.000000Z',
      caption: 'A shiny new caption',
      hashtags: ['#ad'],
      mentions: ['@brand'],
      media: [],
      links: [
        { url: 'https://example.com/raw-cut', name: 'Raw cut' },
        { url: 'https://example.com/moodboard' },
      ],
      review_status: 'pending',
      reviewed_at: null,
      review_feedback: null,
    },
  }
}

function makeDetail(): AgencyAssignmentDetailResource {
  return {
    id: ASSIGNMENT_ID,
    type: 'campaign_assignment',
    attributes: {
      status: 'draft_submitted',
      agreed_fee_minor_units: 100000,
      agreed_fee_currency: 'EUR',
      posting_due_at: null,
      submitted_draft_at: '2026-06-01T10:00:00.000000Z',
      approved_at: null,
      posted_at: null,
      verified_live_at: null,
      creator: { id: 'creator-ulid', display_name: 'Alex Creator' },
      campaign: { id: 'campaign-ulid', name: 'Summer launch', brand_name: 'Acme' },
    },
    relationships: {
      drafts: [makeDraft()],
      posted_content: [],
    },
  }
}

const VDialogStub = {
  name: 'VDialog',
  props: ['modelValue'],
  template: '<div class="vdialog-stub"><slot /></div>',
}

const PortfolioGalleryStub = {
  name: 'PortfolioGallery',
  props: ['items'],
  template: '<div class="portfolio-stub" />',
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

  const wrapper = mount(ReviewDraftDrawer, {
    props: {
      modelValue: false,
      agencyId: 'agency-ulid',
      campaignId: 'campaign-ulid',
      assignment: makeAssignment(),
    },
    global: {
      plugins: [i18n, vuetify],
      stubs: { VDialog: VDialogStub, PortfolioGallery: PortfolioGalleryStub },
    },
    attachTo: document.createElement('div'),
  })

  // The drawer loads on the false→true transition (the real open path).
  await wrapper.setProps({ modelValue: true })
  await flushPromises()
  return wrapper
}

describe('ReviewDraftDrawer (Sprint 9 Chunk 2)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(campaignsApi.showAssignment).mockResolvedValue({ data: makeDetail() })
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('loads the agency detail on open and renders the latest draft caption', async () => {
    const wrapper = await mountOpen()
    expect(campaignsApi.showAssignment).toHaveBeenCalledWith(
      'agency-ulid',
      'campaign-ulid',
      ASSIGNMENT_ID,
    )
    expect(wrapper.find('[data-test="review-caption"]').text()).toContain('A shiny new caption')

    // Hashtags/mentions chips are gone (draft-composer facelift); the draft's
    // external links render as anchors instead.
    expect(wrapper.text()).not.toContain('#ad')
    expect(wrapper.text()).not.toContain('@brand')
    const first = wrapper.find('[data-test="review-link-0"]')
    expect(first.text()).toContain('Raw cut')
    expect(first.attributes('href')).toBe('https://example.com/raw-cut')
    // A name-less link falls back to its URL.
    expect(wrapper.find('[data-test="review-link-1"]').text()).toContain(
      'https://example.com/moodboard',
    )
    wrapper.unmount()
  })

  it('approve calls approveDraft + emits reviewed + closes', async () => {
    vi.mocked(campaignsApi.approveDraft).mockResolvedValue({
      data: makeDraft(),
      meta: { code: 'assignment.draft_approved' },
    })
    const wrapper = await mountOpen()

    await wrapper.find('[data-test="review-approve"]').trigger('click')
    await flushPromises()

    expect(campaignsApi.approveDraft).toHaveBeenCalledWith(
      'agency-ulid',
      'campaign-ulid',
      ASSIGNMENT_ID,
    )
    expect(wrapper.emitted('reviewed')).toHaveLength(1)
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([false])
    wrapper.unmount()
  })

  it('reject asks for confirmation first, then calls rejectDraft with the feedback', async () => {
    vi.mocked(campaignsApi.rejectDraft).mockResolvedValue({
      data: makeDraft(),
      meta: { code: 'assignment.draft_rejected' },
    })
    const wrapper = await mountOpen()

    // The terminal-action guard: clicking Reject opens the confirm dialog —
    // no API call yet.
    expect(wrapper.find('[data-test="review-reject-confirm"]').exists()).toBe(false)
    await wrapper.find('[data-test="review-feedback"] textarea').setValue('Off brief.')
    await wrapper.find('[data-test="review-reject"]').trigger('click')
    await flushPromises()
    expect(campaignsApi.rejectDraft).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="review-reject-confirm"]').exists()).toBe(true)

    await wrapper.find('[data-test="review-reject-confirm-btn"]').trigger('click')
    await flushPromises()

    expect(campaignsApi.rejectDraft).toHaveBeenCalledWith(
      'agency-ulid',
      'campaign-ulid',
      ASSIGNMENT_ID,
      {
        review_feedback: 'Off brief.',
      },
    )
    wrapper.unmount()
  })

  it('"Keep reviewing" backs out of the reject confirmation without calling the API', async () => {
    const wrapper = await mountOpen()

    await wrapper.find('[data-test="review-reject"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="review-reject-keep"]').trigger('click')
    await flushPromises()

    expect(campaignsApi.rejectDraft).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="review-reject-confirm"]').exists()).toBe(false)
    // The drawer itself stays open for further review.
    expect(wrapper.emitted('update:modelValue')?.at(-1)).not.toEqual([false])
    wrapper.unmount()
  })

  it('never feeds a video file URL to the gallery thumbnail (broken-img bug)', async () => {
    const detail = makeDetail()
    detail.relationships.drafts[0]!.attributes.media = [
      {
        s3_path: 'v.mp4',
        mime_type: 'video/mp4',
        kind: 'video',
        thumbnail_path: null,
        duration_seconds: 12,
        view_url: 'https://signed/v.mp4',
        thumbnail_view_url: null,
      },
      {
        s3_path: 'i.jpg',
        mime_type: 'image/jpeg',
        kind: 'image',
        thumbnail_path: null,
        duration_seconds: null,
        view_url: 'https://signed/i.jpg',
        thumbnail_view_url: null,
      },
    ]
    vi.mocked(campaignsApi.showAssignment).mockResolvedValue({ data: detail })
    const wrapper = await mountOpen()

    const gallery = wrapper.findComponent({ name: 'PortfolioGallery' })
    const items = gallery.props('items') as Array<{
      kind: string
      thumbnailUrl: string | null
      viewUrl: string | null
    }>

    // Video with no poster → thumbnailUrl null (so the <img> is not rendered),
    // but the playable file is still on viewUrl for the lightbox.
    expect(items[0]?.kind).toBe('video')
    expect(items[0]?.thumbnailUrl).toBeNull()
    expect(items[0]?.viewUrl).toBe('https://signed/v.mp4')
    // Image without a dedicated thumbnail still falls back to its own view_url.
    expect(items[1]?.thumbnailUrl).toBe('https://signed/i.jpg')
    wrapper.unmount()
  })

  it('surfaces an unexpected 5xx as an inline error and keeps the drawer open', async () => {
    vi.mocked(campaignsApi.requestRevision).mockRejectedValue(
      new ApiError({
        status: 500,
        code: 'server.error',
        message: 'Server error.',
        details: [],
      }),
    )
    const wrapper = await mountOpen()

    await wrapper.find('[data-test="review-feedback"] textarea').setValue('Please redo the hook.')
    await wrapper.find('[data-test="review-request-revision"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="review-action-error"]').exists()).toBe(true)
    expect(wrapper.emitted('reviewed')).toBeUndefined()
    // The drawer must NOT silently close on an unexpected error.
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
    wrapper.unmount()
  })

  it('binds a feedback-required 422 onto the review_feedback textarea', async () => {
    vi.mocked(campaignsApi.requestRevision).mockRejectedValue(
      new ApiError({
        status: 422,
        code: 'validation.failed',
        message: 'Validation failed.',
        details: [
          {
            code: 'validation.required',
            detail: 'The review feedback field is required.',
            source: { pointer: '/data/attributes/review_feedback' },
          },
        ],
      }),
    )
    const wrapper = await mountOpen()

    await wrapper.find('[data-test="review-request-revision"]').trigger('click')
    await flushPromises()

    const textarea = wrapper.findComponent({ name: 'VTextarea' })
    expect(textarea.props('errorMessages')).toContain('The review feedback field is required.')
    expect(wrapper.emitted('reviewed')).toBeUndefined()
    wrapper.unmount()
  })
})
