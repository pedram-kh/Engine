/**
 * Sprint 9 Chunk 1 (D-9) — Vitest coverage for the creator's per-assignment
 * detail + submission surface. Pins: state-dependent FAIL-CLOSED actions (only
 * the legal action per status renders); the draft-submit form's media upload +
 * per-field 422 binding; the resubmit path shows the version history.
 */

import type { CreatorAssignmentDetailResource } from '@catalyst/api-client'
import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { mountAuthPage } from '../../../../tests/unit/helpers/mountAuthPage'

vi.mock('../assignments.api', () => ({
  creatorAssignmentsApi: {
    show: vi.fn(),
    submitDraft: vi.fn(),
    submitPostedContent: vi.fn(),
    initDraftMedia: vi.fn(),
    completeDraftMedia: vi.fn(),
    acceptContract: vi.fn(),
  },
}))

// Keep ApiError + extractFieldErrors real; stub only the vendor PUT.
vi.mock('@catalyst/api-client', async (importActual) => {
  const actual = await importActual<typeof import('@catalyst/api-client')>()
  return { ...actual, uploadToPresignedUrl: vi.fn().mockResolvedValue(undefined) }
})

import { ApiError } from '@catalyst/api-client'

import { creatorAssignmentsApi } from '../assignments.api'
import CreatorAssignmentDetailPage from './CreatorAssignmentDetailPage.vue'

const ULID = '01ASSIGNMENT'

function makeDetail(
  status: CreatorAssignmentDetailResource['attributes']['status'],
  drafts: CreatorAssignmentDetailResource['relationships']['drafts'] = [],
  posted: CreatorAssignmentDetailResource['relationships']['posted_content'] = [],
  contract: CreatorAssignmentDetailResource['relationships']['contract'] = null,
): CreatorAssignmentDetailResource {
  return {
    id: ULID,
    type: 'campaign_assignment',
    attributes: {
      status,
      agreed_fee_minor_units: 500000,
      agreed_fee_currency: 'EUR',
      countered_fee_minor_units: null,
      countered_fee_currency: null,
      deliverables: null,
      posting_due_at: null,
      invited_at: null,
      submitted_draft_at: null,
      approved_at: null,
      posted_at: null,
      campaign: {
        id: '01CAMP',
        name: 'Summer Launch',
        posting_window_starts_at: null,
        posting_window_ends_at: null,
        brand_name: 'Acme',
      },
    },
    relationships: { drafts, posted_content: posted, contract },
  }
}

function makeDraft(
  version: number,
): CreatorAssignmentDetailResource['relationships']['drafts'][number] {
  return {
    id: `01DRAFT${version}`,
    type: 'campaign_draft',
    attributes: {
      version,
      submitted_at: '2026-06-01T10:00:00+00:00',
      caption: `Draft v${version}`,
      hashtags: ['#ad'],
      mentions: null,
      media: [],
      review_status: 'pending',
      reviewed_at: null,
      review_feedback: null,
    },
  }
}

let teardown: (() => void) | null = null

afterEach(() => {
  teardown?.()
  teardown = null
})

beforeEach(() => {
  vi.clearAllMocks()
})

async function mountDetail() {
  const harness = await mountAuthPage(CreatorAssignmentDetailPage, {
    initialRoute: `/creator/assignments/${ULID}`,
  })
  teardown = harness.unmount
  await flushPromises()
  return harness
}

describe('CreatorAssignmentDetailPage — fail-closed state-dependent actions', () => {
  it('renders the draft submit form for a producing assignment (only)', async () => {
    vi.mocked(creatorAssignmentsApi.show).mockResolvedValue({ data: makeDetail('producing') })
    const { wrapper } = await mountDetail()

    expect(wrapper.find('[data-testid="assignment-draft-form"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="assignment-awaiting-review"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="assignment-posted-form"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="assignment-awaiting-verification"]').exists()).toBe(false)
  })

  it('renders awaiting-review (read-only) for a draft_submitted assignment', async () => {
    vi.mocked(creatorAssignmentsApi.show).mockResolvedValue({
      data: makeDetail('draft_submitted', [makeDraft(1)]),
    })
    const { wrapper } = await mountDetail()

    expect(wrapper.find('[data-testid="assignment-awaiting-review"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="assignment-draft-form"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="assignment-posted-form"]').exists()).toBe(false)
  })

  it('renders the posted-content form for an approved assignment (only)', async () => {
    vi.mocked(creatorAssignmentsApi.show).mockResolvedValue({ data: makeDetail('approved') })
    const { wrapper } = await mountDetail()

    expect(wrapper.find('[data-testid="assignment-posted-form"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="assignment-draft-form"]').exists()).toBe(false)
  })

  it('renders awaiting-verification for a posted assignment (the arc stops here)', async () => {
    vi.mocked(creatorAssignmentsApi.show).mockResolvedValue({ data: makeDetail('posted') })
    const { wrapper } = await mountDetail()

    expect(wrapper.find('[data-testid="assignment-awaiting-verification"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="assignment-posted-form"]').exists()).toBe(false)
  })

  it('shows the revision feedback + resubmit form + version history for revision_requested', async () => {
    vi.mocked(creatorAssignmentsApi.show).mockResolvedValue({
      data: makeDetail('revision_requested', [makeDraft(2), makeDraft(1)]),
    })
    const { wrapper } = await mountDetail()

    expect(wrapper.find('[data-testid="assignment-revision-feedback"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="assignment-draft-form"]').exists()).toBe(true)
    // Version history preserved — both versions render.
    expect(wrapper.find('[data-testid="assignment-draft-version-1"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="assignment-draft-version-2"]').exists()).toBe(true)
  })

  it('renders contract accept for accepted + sent contract only', async () => {
    vi.mocked(creatorAssignmentsApi.show).mockResolvedValue({
      data: makeDetail('accepted', [], [], {
        id: '01CONTRACT',
        type: 'contract',
        attributes: {
          kind: 'per_campaign',
          title: 'Campaign addendum',
          body_markdown: 'Deliver one Reel by the due date.',
          status: 'sent',
          sent_at: '2026-06-01T10:00:00+00:00',
          signed_at: null,
          view_url: 'https://example.com/contract.pdf',
        },
      }),
    })
    const { wrapper } = await mountDetail()

    expect(wrapper.find('[data-testid="assignment-contract-form"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="assignment-draft-form"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="assignment-contract-view"]').attributes('href')).toBe(
      'https://example.com/contract.pdf',
    )
  })

  it('renders the draft form after contracted (not contract accept)', async () => {
    vi.mocked(creatorAssignmentsApi.show).mockResolvedValue({
      data: makeDetail('contracted', [], [], {
        id: '01CONTRACT',
        type: 'contract',
        attributes: {
          kind: 'per_campaign',
          title: 'Campaign addendum',
          body_markdown: null,
          status: 'signed',
          sent_at: '2026-06-01T10:00:00+00:00',
          signed_at: '2026-06-01T11:00:00+00:00',
          view_url: null,
        },
      }),
    })
    const { wrapper } = await mountDetail()

    expect(wrapper.find('[data-testid="assignment-contract-form"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="assignment-draft-form"]').exists()).toBe(true)
  })
})

describe('CreatorAssignmentDetailPage — load failures', () => {
  it('shows the not-found alert only on a true 404', async () => {
    vi.mocked(creatorAssignmentsApi.show).mockRejectedValue(
      new ApiError({ status: 404, code: 'assignment.not_found', message: 'No assignment found.' }),
    )
    const { wrapper } = await mountDetail()

    expect(wrapper.find('[data-testid="assignment-detail-not-found"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="assignment-detail-load-error"]').exists()).toBe(false)
  })

  it('shows a retry-able error (not "not found") on a 500, then recovers on retry', async () => {
    vi.mocked(creatorAssignmentsApi.show)
      .mockRejectedValueOnce(
        new ApiError({ status: 500, code: 'server.error', message: 'Server error.' }),
      )
      .mockResolvedValueOnce({ data: makeDetail('producing') })
    const { wrapper } = await mountDetail()

    expect(wrapper.find('[data-testid="assignment-detail-load-error"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="assignment-detail-not-found"]').exists()).toBe(false)

    await wrapper.find('[data-testid="assignment-detail-retry"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="assignment-detail-load-error"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="assignment-draft-form"]').exists()).toBe(true)
  })

  it('treats a network error (status 0) as retry-able, not "not found"', async () => {
    vi.mocked(creatorAssignmentsApi.show).mockRejectedValue(
      new ApiError({ status: 0, code: 'network.error', message: 'Network error.' }),
    )
    const { wrapper } = await mountDetail()

    expect(wrapper.find('[data-testid="assignment-detail-load-error"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="assignment-detail-not-found"]').exists()).toBe(false)
  })
})

describe('CreatorAssignmentDetailPage — draft submit form', () => {
  it('uploads media then binds a 422 onto the caption field', async () => {
    vi.mocked(creatorAssignmentsApi.show).mockResolvedValue({ data: makeDetail('producing') })
    vi.mocked(creatorAssignmentsApi.initDraftMedia).mockResolvedValue({
      data: {
        upload_url: 'https://s3.example/put',
        upload_id: 'creators/c/drafts/f.mp4',
        storage_path: 'creators/c/drafts/f.mp4',
        expires_at: '2026-06-01T10:15:00+00:00',
        max_bytes: 500000000,
      },
    })
    vi.mocked(creatorAssignmentsApi.completeDraftMedia).mockResolvedValue({
      data: { storage_path: 'creators/c/drafts/f.mp4' },
    })
    vi.mocked(creatorAssignmentsApi.submitDraft).mockRejectedValue(
      new ApiError({
        status: 422,
        code: 'validation.failed',
        message: 'Validation failed',
        details: [
          {
            status: '422',
            code: 'validation.field_required',
            detail: 'The caption is invalid.',
            source: { pointer: '/data/attributes/caption' },
          },
        ],
      }),
    )

    const { wrapper } = await mountDetail()

    // Drive the file input → presigned upload chain (init → PUT → complete).
    const fileInput = wrapper.findComponent({ name: 'VFileInput' })
    const file = new File(['x'], 'clip.mp4', { type: 'video/mp4' })
    fileInput.vm.$emit('update:modelValue', [file])
    await flushPromises()

    expect(creatorAssignmentsApi.initDraftMedia).toHaveBeenCalled()
    expect(creatorAssignmentsApi.completeDraftMedia).toHaveBeenCalled()

    // Submit → 422 → per-field binding.
    await wrapper.find('[data-testid="assignment-draft-submit"]').trigger('click')
    await flushPromises()

    expect(creatorAssignmentsApi.submitDraft).toHaveBeenCalledWith(
      ULID,
      expect.objectContaining({
        media: [expect.objectContaining({ s3_path: 'creators/c/drafts/f.mp4', kind: 'video' })],
      }),
    )
    expect(wrapper.text()).toContain('The caption is invalid.')
  })
})
