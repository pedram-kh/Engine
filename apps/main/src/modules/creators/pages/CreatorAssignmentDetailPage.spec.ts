/**
 * Sprint 9 Chunk 1 (D-9) — Vitest coverage for the creator's per-assignment
 * detail + submission surface. Pins: state-dependent FAIL-CLOSED actions (only
 * the legal action per status renders); the draft-submit form's media upload +
 * per-field 422 binding; the resubmit path shows the version history.
 */

import type {
  CreatorAssignmentActionResponse,
  CreatorAssignmentDetailResource,
} from '@catalyst/api-client'
import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { mountAuthPage } from '../../../../tests/unit/helpers/mountAuthPage'

vi.mock('../assignments.api', () => ({
  creatorAssignmentsApi: {
    show: vi.fn(),
    accept: vi.fn(),
    decline: vi.fn(),
    submitDraft: vi.fn(),
    submitPostedContent: vi.fn(),
    updatePostedContent: vi.fn(),
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

/** The narrow `{data, meta:{code}}` envelope accept/decline actually return. */
function makeAction(status: 'accepted' | 'declined'): CreatorAssignmentActionResponse {
  return {
    data: { type: 'campaign_assignment', id: ULID, attributes: { status } },
    meta: { code: `assignment.${status}` },
  }
}

type DraftAttributes =
  CreatorAssignmentDetailResource['relationships']['drafts'][number]['attributes']

/**
 * One round. `review` closes it — a round with a `review_status` other than
 * `pending` is a round the agency has answered (AH-068, D1), so the fixture takes
 * the closing columns together rather than letting a spec build a
 * revision-requested round with no feedback and no timestamp.
 */
function makeDraft(
  version: number,
  review: Pick<DraftAttributes, 'review_status' | 'reviewed_at' | 'review_feedback'> = {
    review_status: 'pending',
    reviewed_at: null,
    review_feedback: null,
  },
): CreatorAssignmentDetailResource['relationships']['drafts'][number] {
  return {
    id: `01DRAFT${version}`,
    type: 'campaign_draft',
    attributes: {
      version,
      submitted_at: '2026-06-01T10:00:00+00:00',
      caption: `Caption for round ${version}`,
      hashtags: ['#ad'],
      mentions: null,
      media: [],
      ...review,
    },
  }
}

/**
 * Three rounds, newest first (the endpoint orders `version` desc): two closed
 * with their own distinct feedback, the third still open. The fixture review
 * priority 3 asks for.
 */
function threeRounds(): CreatorAssignmentDetailResource['relationships']['drafts'] {
  return [
    makeDraft(3),
    makeDraft(2, {
      review_status: 'revision_requested',
      reviewed_at: '2026-06-02T09:30:00+00:00',
      review_feedback: 'Brighten the lighting and re-shoot the closing frame.',
    }),
    makeDraft(1, {
      review_status: 'revision_requested',
      reviewed_at: '2026-06-01T15:00:00+00:00',
      review_feedback: 'Please add the campaign hashtag.',
    }),
  ]
}

function makePost(
  verification: 'pending' | 'verified' | 'not_found' | 'mismatch',
): CreatorAssignmentDetailResource['relationships']['posted_content'][number] {
  return {
    id: '01POST',
    type: 'campaign_posted_content',
    attributes: {
      platform: 'instagram',
      post_url: 'https://instagram.com/someoneelse/p/abc',
      platform_post_id: null,
      posted_at: '2026-06-03T10:00:00+00:00',
      verified_at: null,
      verification_status: verification,
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

  it('renders awaiting-verification for a posted assignment still pending', async () => {
    vi.mocked(creatorAssignmentsApi.show).mockResolvedValue({
      data: makeDetail('posted', [], [makePost('pending')]),
    })
    const { wrapper } = await mountDetail()

    expect(wrapper.find('[data-testid="assignment-awaiting-verification"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="assignment-posted-form"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="assignment-resubmit-in-place-form"]').exists()).toBe(false)
  })

  // Verified closure banner (AH-047) — the green "process is done" notice.
  it('renders the verified notice for a manually_verified assignment', async () => {
    vi.mocked(creatorAssignmentsApi.show).mockResolvedValue({
      data: makeDetail('manually_verified', [], [makePost('not_found')]),
    })
    const { wrapper } = await mountDetail()

    const notice = wrapper.find('[data-testid="assignment-verified-notice"]')
    expect(notice.exists()).toBe(true)
    expect(notice.text()).toContain('verified by the agency')
    // No lingering action surfaces.
    expect(wrapper.find('[data-testid="assignment-resubmit-in-place-form"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="assignment-awaiting-verification"]').exists()).toBe(false)
  })

  it('renders the verified notice for a live_verified assignment', async () => {
    vi.mocked(creatorAssignmentsApi.show).mockResolvedValue({
      data: makeDetail('live_verified', [], [makePost('verified')]),
    })
    const { wrapper } = await mountDetail()

    expect(wrapper.find('[data-testid="assignment-verified-notice"]').exists()).toBe(true)
  })

  // ── The hand-off closure banner (AH-069, D7) ──────────────────────────────
  //
  // A THIRD branch beside the verified notice, not a widening of it. These four
  // tests exist to keep it that way.

  it('renders the completion notice for a completed_on_approval assignment', async () => {
    vi.mocked(creatorAssignmentsApi.show).mockResolvedValue({
      data: makeDetail('completed_on_approval'),
      meta: { per_campaign_contract_enabled: false, creator_posts_content: false },
    })
    const { wrapper } = await mountDetail()

    const notice = wrapper.find('[data-testid="assignment-completed-on-approval-notice"]')
    expect(notice.exists()).toBe(true)
    expect(notice.text()).toContain('Your draft has been approved')
    expect(notice.text()).toContain('no further action is needed')
  })

  it('NEVER claims the post was verified on the completion notice (the copy assertion)', async () => {
    // The one thing this banner must not do. A creator on a hand-off campaign
    // posted nothing, so any word about verification — or about a post at all —
    // would be the page telling them something untrue about their own work.
    vi.mocked(creatorAssignmentsApi.show).mockResolvedValue({
      data: makeDetail('completed_on_approval'),
      meta: { per_campaign_contract_enabled: false, creator_posts_content: false },
    })
    const { wrapper } = await mountDetail()

    const text = wrapper.find('[data-testid="assignment-completed-on-approval-notice"]').text()
    expect(text).not.toContain('verified')
    expect(text).not.toContain('verify')
    expect(text).not.toContain('post')

    // And it is genuinely a separate element — the verified banner is absent,
    // so this cannot be the AH-047 notice wearing a new label.
    expect(wrapper.find('[data-testid="assignment-verified-notice"]').exists()).toBe(false)
  })

  it('shows no post or verify affordance once the assignment completed at approval', async () => {
    vi.mocked(creatorAssignmentsApi.show).mockResolvedValue({
      data: makeDetail('completed_on_approval'),
      meta: { per_campaign_contract_enabled: false, creator_posts_content: false },
    })
    const { wrapper } = await mountDetail()

    expect(wrapper.find('[data-testid="assignment-posted-form"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="assignment-awaiting-verification"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="assignment-resubmit-in-place-form"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="assignment-draft-form"]').exists()).toBe(false)
  })

  it('hides the post form on an approved assignment whose campaign hands off (Q2 mirror)', async () => {
    // The sliver the status alone cannot catch: the toggle was switched off
    // while this assignment already sat at `approved`. The server refuses the
    // post (422); the surface must not offer it in the first place.
    vi.mocked(creatorAssignmentsApi.show).mockResolvedValue({
      data: makeDetail('approved'),
      meta: { per_campaign_contract_enabled: false, creator_posts_content: false },
    })
    const { wrapper } = await mountDetail()

    expect(wrapper.find('[data-testid="assignment-posted-form"]').exists()).toBe(false)
  })

  it('still shows the post form on an approved assignment when the meta flag is absent', async () => {
    // Back-compat, and the safe direction: an older or partial payload must not
    // hide a step a posting campaign genuinely needs.
    vi.mocked(creatorAssignmentsApi.show).mockResolvedValue({
      data: makeDetail('approved'),
      meta: { per_campaign_contract_enabled: false },
    })
    const { wrapper } = await mountDetail()

    expect(wrapper.find('[data-testid="assignment-posted-form"]').exists()).toBe(true)
  })

  // Verification-resolution chunk (ACT3) — the in-place fix form on a failed post.
  it('renders the in-place resubmit form for a posted assignment whose verification FAILED', async () => {
    vi.mocked(creatorAssignmentsApi.show).mockResolvedValue({
      data: makeDetail('posted', [], [makePost('mismatch')]),
    })
    const { wrapper } = await mountDetail()

    expect(wrapper.find('[data-testid="assignment-resubmit-in-place-form"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="assignment-awaiting-verification"]').exists()).toBe(false)
    // Prefilled with the failed URL (edit, not retype).
    const input = wrapper.find('[data-testid="assignment-resubmit-in-place-url"] input')
      .element as HTMLInputElement
    expect(input.value).toContain('someoneelse')
  })

  it('submits the in-place fix → calls updatePostedContent + reloads', async () => {
    vi.mocked(creatorAssignmentsApi.show).mockResolvedValue({
      data: makeDetail('posted', [], [makePost('not_found')]),
    })
    vi.mocked(creatorAssignmentsApi.updatePostedContent).mockResolvedValue({
      data: makePost('pending'),
      meta: { code: 'assignment.posted_content_updated' },
    })
    const { wrapper } = await mountDetail()

    await wrapper
      .find('[data-testid="assignment-resubmit-in-place-url"] input')
      .setValue('https://instagram.com/creatorhandle/p/xyz')
    await wrapper.find('[data-testid="assignment-resubmit-in-place-submit"]').trigger('click')
    await flushPromises()

    expect(creatorAssignmentsApi.updatePostedContent).toHaveBeenCalledWith(ULID, {
      post_url: 'https://instagram.com/creatorhandle/p/xyz',
    })
  })

  it('shows the revision feedback + resubmit form + round history for revision_requested', async () => {
    vi.mocked(creatorAssignmentsApi.show).mockResolvedValue({
      data: makeDetail('revision_requested', [makeDraft(2), makeDraft(1)]),
    })
    const { wrapper } = await mountDetail()

    expect(wrapper.find('[data-testid="assignment-revision-feedback"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="assignment-draft-form"]').exists()).toBe(true)
    // Round history preserved — both rounds render.
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
      meta: { per_campaign_contract_enabled: true },
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
      meta: { per_campaign_contract_enabled: true },
    })
    const { wrapper } = await mountDetail()

    expect(wrapper.find('[data-testid="assignment-contract-form"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="assignment-draft-form"]').exists()).toBe(true)
  })

  it('shows awaiting-contract when accepted on a requires=true campaign without a sent contract', async () => {
    vi.mocked(creatorAssignmentsApi.show).mockResolvedValue({
      data: makeDetail('accepted'),
      meta: { per_campaign_contract_enabled: true, requires_per_campaign_contract: true },
    })
    const { wrapper } = await mountDetail()

    expect(wrapper.find('[data-testid="assignment-awaiting-contract"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="assignment-contract-form"]').exists()).toBe(false)
  })

  it('shows signing-disabled when accepted on a requires=true campaign and per_campaign_contract_enabled is OFF', async () => {
    vi.mocked(creatorAssignmentsApi.show).mockResolvedValue({
      data: makeDetail('accepted'),
      meta: { per_campaign_contract_enabled: false, requires_per_campaign_contract: true },
    })
    const { wrapper } = await mountDetail()

    expect(wrapper.find('[data-testid="assignment-contract-signing-disabled"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="assignment-awaiting-contract"]').exists()).toBe(false)
  })

  // §5.34 negative (toggle-off-flow D3) — an OFF (requires=false) campaign never
  // shows ANY contract copy, even in the belt-and-suspenders case where a row is
  // still `accepted` (the D2 auto-advance should have moved it past this).
  it('shows NO contract copy when accepted on an OFF (requires=false) campaign', async () => {
    vi.mocked(creatorAssignmentsApi.show).mockResolvedValue({
      data: makeDetail('accepted'),
      meta: { per_campaign_contract_enabled: true, requires_per_campaign_contract: false },
    })
    const { wrapper } = await mountDetail()

    expect(wrapper.find('[data-testid="assignment-awaiting-contract"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="assignment-contract-signing-disabled"]').exists()).toBe(
      false,
    )
    expect(wrapper.find('[data-testid="assignment-contract-form"]').exists()).toBe(false)
  })
})

describe('CreatorAssignmentDetailPage — answering the invitation in place', () => {
  it('renders the accept / decline pair for an invited assignment, and no other action', async () => {
    vi.mocked(creatorAssignmentsApi.show).mockResolvedValue({ data: makeDetail('invited') })
    const { wrapper } = await mountDetail()

    expect(wrapper.find('[data-testid="assignment-detail-accept"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="assignment-detail-decline"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="assignment-draft-form"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="assignment-posted-form"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="assignment-contract-form"]').exists()).toBe(false)
  })

  // The negative leg: the pair belongs to `invited` and to nothing else. Every
  // status here is eligible in every respect except the one that matters.
  it.each(['accepted', 'contracted', 'producing', 'declined', 'cancelled'] as const)(
    'renders no accept / decline pair for a %s assignment',
    async (status) => {
      vi.mocked(creatorAssignmentsApi.show).mockResolvedValue({ data: makeDetail(status) })
      const { wrapper } = await mountDetail()

      expect(wrapper.find('[data-testid="assignment-detail-actions"]').exists()).toBe(false)
    },
  )

  it('accepts → calls the accept endpoint and reloads into the new state', async () => {
    vi.mocked(creatorAssignmentsApi.show)
      .mockResolvedValueOnce({ data: makeDetail('invited') })
      .mockResolvedValueOnce({ data: makeDetail('accepted') })
    vi.mocked(creatorAssignmentsApi.accept).mockResolvedValue(makeAction('accepted'))
    const { wrapper } = await mountDetail()

    await wrapper.find('[data-testid="assignment-detail-accept"]').trigger('click')
    await flushPromises()

    expect(creatorAssignmentsApi.accept).toHaveBeenCalledWith(ULID)
    expect(creatorAssignmentsApi.decline).not.toHaveBeenCalled()
    expect(creatorAssignmentsApi.show).toHaveBeenCalledTimes(2)
    expect(wrapper.find('[data-testid="assignment-detail-actions"]').exists()).toBe(false)
  })

  it('declines → calls the decline endpoint and reloads into the new state', async () => {
    vi.mocked(creatorAssignmentsApi.show)
      .mockResolvedValueOnce({ data: makeDetail('invited') })
      .mockResolvedValueOnce({ data: makeDetail('declined') })
    vi.mocked(creatorAssignmentsApi.decline).mockResolvedValue(makeAction('declined'))
    const { wrapper } = await mountDetail()

    await wrapper.find('[data-testid="assignment-detail-decline"]').trigger('click')
    await flushPromises()

    expect(creatorAssignmentsApi.decline).toHaveBeenCalledWith(ULID)
    expect(creatorAssignmentsApi.accept).not.toHaveBeenCalled()
    expect(wrapper.find('[data-testid="assignment-detail-actions"]').exists()).toBe(false)
  })

  it('surfaces a failed answer as an error and leaves the pair in place', async () => {
    vi.mocked(creatorAssignmentsApi.show).mockResolvedValue({ data: makeDetail('invited') })
    vi.mocked(creatorAssignmentsApi.accept).mockRejectedValue(
      new ApiError({ status: 422, code: 'assignment.not_invited', message: 'Stale.' }),
    )
    const { wrapper } = await mountDetail()

    await wrapper.find('[data-testid="assignment-detail-accept"]').trigger('click')
    await flushPromises()

    // No reload on failure, and the creator can try again.
    expect(creatorAssignmentsApi.show).toHaveBeenCalledTimes(1)
    expect(wrapper.find('[data-testid="assignment-detail-accept"]').exists()).toBe(true)
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

    // The hashtags/mentions fields are gone (draft-composer facelift).
    expect(wrapper.find('[data-testid="assignment-draft-hashtags"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="assignment-draft-mentions"]').exists()).toBe(false)

    // Drive the hidden OS file picker → presigned upload chain (init → PUT → complete).
    const fileInput = wrapper.find('[data-testid="assignment-draft-media-input"]')
    const file = new File(['x'], 'clip.mp4', { type: 'video/mp4' })
    Object.defineProperty(fileInput.element, 'files', { value: [file], configurable: true })
    await fileInput.trigger('change')
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

  it('adds an external link via the dialog and sends it on the payload', async () => {
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
    vi.mocked(creatorAssignmentsApi.submitDraft).mockResolvedValue({
      data: makeDraft(1),
      meta: { code: 'assignment.draft_submitted' },
    })

    const { wrapper } = await mountDetail()

    // Ready one media file alongside the link (media + links together).
    const fileInput = wrapper.find('[data-testid="assignment-draft-media-input"]')
    const file = new File(['x'], 'clip.mp4', { type: 'video/mp4' })
    Object.defineProperty(fileInput.element, 'files', { value: [file], configurable: true })
    await fileInput.trigger('change')
    await flushPromises()

    // Open the link dialog (it teleports to <body>, so fields are queried there).
    await wrapper.find('[data-testid="assignment-draft-attach-link"]').trigger('click')
    await flushPromises()
    const urlInput = document.body.querySelector(
      '[data-testid="assignment-draft-link-url"] input',
    ) as HTMLInputElement
    const addBtn = document.body.querySelector(
      '[data-testid="assignment-draft-link-add"]',
    ) as HTMLElement

    // An invalid URL is refused in place.
    urlInput.value = 'not-a-url'
    urlInput.dispatchEvent(new Event('input'))
    await flushPromises()
    addBtn.click()
    await flushPromises()
    expect(wrapper.find('[data-testid="assignment-draft-links-list"]').exists()).toBe(false)

    // A valid http(s) URL + label lands in the pending list.
    urlInput.value = 'https://example.com/raw-cut'
    urlInput.dispatchEvent(new Event('input'))
    const nameInput = document.body.querySelector(
      '[data-testid="assignment-draft-link-name"] input',
    ) as HTMLInputElement
    nameInput.value = 'Raw cut'
    nameInput.dispatchEvent(new Event('input'))
    await flushPromises()
    addBtn.click()
    await flushPromises()
    expect(wrapper.find('[data-testid="assignment-draft-link-0"]').text()).toContain('Raw cut')

    await wrapper.find('[data-testid="assignment-draft-submit"]').trigger('click')
    await flushPromises()

    expect(creatorAssignmentsApi.submitDraft).toHaveBeenCalledWith(
      ULID,
      expect.objectContaining({
        links: [{ url: 'https://example.com/raw-cut', name: 'Raw cut' }],
      }),
    )
  })

  it('gates submit behind content and shows the empty hint until a link (or media) is added (AH-044)', async () => {
    vi.mocked(creatorAssignmentsApi.show).mockResolvedValue({ data: makeDetail('producing') })
    vi.mocked(creatorAssignmentsApi.submitDraft).mockResolvedValue({
      data: makeDraft(1),
      meta: { code: 'assignment.draft_submitted' },
    })

    const { wrapper } = await mountDetail()

    // Nothing added yet: hint visible, submit disabled.
    expect(wrapper.find('[data-testid="assignment-draft-empty-hint"]').exists()).toBe(true)
    expect(
      (wrapper.find('[data-testid="assignment-draft-submit"]').element as HTMLButtonElement)
        .disabled,
    ).toBe(true)

    // Add a link only — no media at all.
    await wrapper.find('[data-testid="assignment-draft-attach-link"]').trigger('click')
    await flushPromises()
    const urlInput = document.body.querySelector(
      '[data-testid="assignment-draft-link-url"] input',
    ) as HTMLInputElement
    urlInput.value = 'https://example.com/final-cut'
    urlInput.dispatchEvent(new Event('input'))
    await flushPromises()
    ;(
      document.body.querySelector('[data-testid="assignment-draft-link-add"]') as HTMLElement
    ).click()
    await flushPromises()

    // A link alone now satisfies the gate: hint gone, submit enabled.
    expect(wrapper.find('[data-testid="assignment-draft-empty-hint"]').exists()).toBe(false)
    expect(
      (wrapper.find('[data-testid="assignment-draft-submit"]').element as HTMLButtonElement)
        .disabled,
    ).toBe(false)

    await wrapper.find('[data-testid="assignment-draft-submit"]').trigger('click')
    await flushPromises()

    expect(creatorAssignmentsApi.submitDraft).toHaveBeenCalledWith(
      ULID,
      expect.objectContaining({
        media: [],
        links: [{ url: 'https://example.com/final-cut' }],
      }),
    )
  })
})

// ── Numbered visible rounds (AH-068, D2/D3) ──────────────────────────────────
describe('CreatorAssignmentDetailPage — draft rounds', () => {
  function historyText(wrapper: { find: (s: string) => { text: () => string } }): string {
    return wrapper.find('[data-testid="assignment-draft-history"]').text()
  }

  it('names every round "Draft {n} — <state>", the same vocabulary the agency reads', async () => {
    vi.mocked(creatorAssignmentsApi.show).mockResolvedValue({
      data: makeDetail('draft_submitted', threeRounds()),
    })
    const { wrapper } = await mountDetail()

    expect(wrapper.find('[data-testid="assignment-draft-version-3"]').text()).toContain(
      'Draft 3 — awaiting review',
    )
    expect(wrapper.find('[data-testid="assignment-draft-version-2"]').text()).toContain(
      'Draft 2 — changes requested',
    )
    expect(wrapper.find('[data-testid="assignment-draft-version-1"]').text()).toContain(
      'Draft 1 — changes requested',
    )
  })

  it('has retired the "Version {n}" vocabulary entirely', async () => {
    vi.mocked(creatorAssignmentsApi.show).mockResolvedValue({
      data: makeDetail('draft_submitted', threeRounds()),
    })
    const { wrapper } = await mountDetail()

    const text = historyText(wrapper)
    expect(text).not.toContain('Version 1')
    expect(text).not.toContain('Version 2')
    expect(text).not.toContain('Version 3')
    // …and the block title is the shared one, not a second phrasing.
    expect(text).toContain('Draft history')
  })

  // D3 — the creator can reconstruct the conversation without asking.
  it('renders each round with ITS OWN feedback and review timestamp', async () => {
    vi.mocked(creatorAssignmentsApi.show).mockResolvedValue({
      data: makeDetail('draft_submitted', threeRounds()),
    })
    const { wrapper } = await mountDetail()

    const round2 = wrapper.find('[data-testid="assignment-draft-feedback-2"]')
    const round1 = wrapper.find('[data-testid="assignment-draft-feedback-1"]')
    expect(round2.text()).toContain('Brighten the lighting and re-shoot the closing frame.')
    expect(round1.text()).toContain('Please add the campaign hashtag.')
    // Each round's note stays on its own round — no leak from the newer one.
    expect(round1.text()).not.toContain('Brighten the lighting')

    expect(wrapper.find('[data-testid="assignment-draft-reviewed-at-2"]').text()).toContain(
      'Reviewed',
    )
    expect(wrapper.find('[data-testid="assignment-draft-reviewed-at-1"]').text()).toMatch(/2026/)
  })

  // ── negative cases (§5.34) ─────────────────────────────────────────────────
  it('the open round shows no feedback and no review timestamp', async () => {
    vi.mocked(creatorAssignmentsApi.show).mockResolvedValue({
      data: makeDetail('draft_submitted', threeRounds()),
    })
    const { wrapper } = await mountDetail()

    expect(wrapper.find('[data-testid="assignment-draft-feedback-3"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="assignment-draft-reviewed-at-3"]').exists()).toBe(false)
  })

  it('an approved round reads approved, and a pending round on a moved-on assignment does not claim a review is under way', async () => {
    vi.mocked(creatorAssignmentsApi.show).mockResolvedValue({
      data: makeDetail('approved', [
        makeDraft(2, {
          review_status: 'approved',
          reviewed_at: '2026-06-04T11:00:00+00:00',
          review_feedback: null,
        }),
        makeDraft(1, {
          review_status: 'revision_requested',
          reviewed_at: '2026-06-01T15:00:00+00:00',
          review_feedback: 'Please add the campaign hashtag.',
        }),
      ]),
    })
    const { wrapper } = await mountDetail()

    expect(wrapper.find('[data-testid="assignment-draft-version-2"]').text()).toContain(
      'Draft 2 — approved',
    )
    // The approved round closed with no note: the timestamp renders, and no
    // sentence is invented on the agency's behalf (AH-043).
    expect(wrapper.find('[data-testid="assignment-draft-reviewed-at-2"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="assignment-draft-feedback-2"]').exists()).toBe(false)
  })

  it('a pending round on a cancelled assignment reads submitted, not awaiting review', async () => {
    vi.mocked(creatorAssignmentsApi.show).mockResolvedValue({
      data: makeDetail('cancelled', [makeDraft(1)]),
    })
    const { wrapper } = await mountDetail()

    const round = wrapper.find('[data-testid="assignment-draft-version-1"]').text()
    expect(round).toContain('Draft 1 — submitted')
    expect(round).not.toContain('awaiting review')
  })
})
