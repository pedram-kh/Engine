/**
 * DraftReviewPanel (eyes-on fix batch, 2026-08-17) — the shared reviewable
 * content extracted out of `ReviewDraftDrawer`. Its round-preview/history/
 * action-endpoint coverage is already pinned by `ReviewDraftDrawer.spec.ts`
 * (unchanged after the extraction — same component, same behavior, just
 * hosted). What's pinned HERE is what's specific to being a component with
 * its OWN `canReview` gate and no `modelValue`/dialog of its own:
 *
 *   - the empty state for an assignment with no drafts at all (unreachable
 *     from `ReviewDraftDrawer`, reachable from the board card drawer's
 *     always-visible Drafts tab);
 *   - `canReview: false` hides the actions even while `draft_submitted`;
 *   - a fresh load (`loading` false→true) clears stale feedback/errors.
 */

import {
  ApiError,
  type AgencyAssignmentDetailResource,
  type AssignmentStatus,
  type CampaignDraftResource,
} from '@catalyst/api-client'
import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enApp from '@/core/i18n/locales/en/app.json'

vi.mock('../api/campaigns.api', () => ({
  campaignsApi: {
    approveDraft: vi.fn(),
    requestRevision: vi.fn(),
    rejectDraft: vi.fn(),
  },
}))

import { campaignsApi } from '../api/campaigns.api'
import DraftReviewPanel from './DraftReviewPanel.vue'

const ASSIGNMENT_ID = '01ASSIGNULIDXXXXXXXXXXXXXX'

const PortfolioGalleryStub = {
  name: 'PortfolioGallery',
  props: ['items'],
  template: '<div class="portfolio-stub" />',
}

function makeDraft(version = 1): CampaignDraftResource {
  return {
    id: `draft-${version}`,
    type: 'campaign_draft',
    attributes: {
      version,
      submitted_at: '2026-06-01T10:00:00.000000Z',
      caption: 'A caption',
      hashtags: null,
      mentions: null,
      media: [],
      links: null,
      review_status: 'pending',
      reviewed_at: null,
      review_feedback: null,
    },
  }
}

function makeDetail(status: AssignmentStatus = 'draft_submitted'): AgencyAssignmentDetailResource {
  return {
    id: ASSIGNMENT_ID,
    type: 'campaign_assignment',
    attributes: {
      status,
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
    relationships: { drafts: [makeDraft()], posted_content: [] },
  }
}

function mountPanel(props: Partial<InstanceType<typeof DraftReviewPanel>['$props']> = {}) {
  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    availableLocales: ['en'],
    messages: { en: enApp } as never,
  }) as unknown as ReturnType<typeof createI18n>
  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })

  return mount(DraftReviewPanel, {
    props: {
      agencyId: 'agency-ulid',
      campaignId: 'campaign-ulid',
      assignmentId: ASSIGNMENT_ID,
      detail: makeDetail(),
      loading: false,
      loadError: false,
      canReview: true,
      ...props,
    },
    global: {
      plugins: [i18n, vuetify],
      stubs: { PortfolioGallery: PortfolioGalleryStub },
    },
    attachTo: document.createElement('div'),
  })
}

describe('DraftReviewPanel', () => {
  beforeEach(() => vi.clearAllMocks())

  it('shows the empty state for an assignment with no drafts at all', () => {
    const detail = makeDetail('invited')
    detail.relationships.drafts = []
    const wrapper = mountPanel({ detail })
    expect(wrapper.find('[data-test="review-empty"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="review-draft-preview"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('shows the loading skeleton while `loading` is true, regardless of detail', () => {
    const wrapper = mountPanel({ loading: true, detail: null })
    expect(wrapper.find('[data-test="review-skeleton"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('shows the load error alert when `loadError` is true', () => {
    const wrapper = mountPanel({ loadError: true, detail: null })
    expect(wrapper.find('[data-test="review-load-error"]').exists()).toBe(true)
    wrapper.unmount()
  })

  // ── The `canReview` gate (board card drawer's Drafts tab has no upstream
  //    ability check the way ReviewDraftDrawer's own mount condition does) ──

  it('without canReview, a draft_submitted round still previews but hides the actions', () => {
    const wrapper = mountPanel({ canReview: false })
    expect(wrapper.find('[data-test="review-draft-preview"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="review-approve"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="review-reject"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="review-request-revision"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="review-feedback"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('refuses to call an action endpoint even if triggered without canReview (defense in depth)', async () => {
    const wrapper = mountPanel({ canReview: false })
    // Reach past the hidden UI and call the exposed handler directly — the
    // guard inside runAction is the thing under test, not the button's v-if.
    await (wrapper.vm as unknown as { runAction: (k: string) => Promise<void> }).runAction(
      'approve',
    )
    expect(campaignsApi.approveDraft).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('with canReview, a draft_submitted round shows the full action set', () => {
    const wrapper = mountPanel({ canReview: true })
    expect(wrapper.find('[data-test="review-approve"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="review-reject"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="review-request-revision"]').exists()).toBe(true)
    wrapper.unmount()
  })

  // ── Local state reset on a fresh load (no `modelValue` to watch here) ────

  it('clears a stale action error once a new load starts', async () => {
    vi.mocked(campaignsApi.requestRevision).mockRejectedValue(
      new ApiError({ status: 500, code: 'server.error', message: 'Server error.', details: [] }),
    )
    const wrapper = mountPanel()
    await wrapper.find('[data-test="review-request-revision"]').trigger('click')
    await vi.waitFor(() =>
      expect(wrapper.find('[data-test="review-action-error"]').exists()).toBe(true),
    )

    // A fresh load starting (loading false→true) is the reset signal.
    await wrapper.setProps({ loading: true })
    await wrapper.setProps({ loading: false, detail: makeDetail() })

    expect(wrapper.find('[data-test="review-action-error"]').exists()).toBe(false)
    wrapper.unmount()
  })
})
