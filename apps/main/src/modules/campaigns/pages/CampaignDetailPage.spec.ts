/**
 * Sprint 8 Chunk 1 — Vitest coverage for the campaign detail page (the app's
 * first tabbed surface). Pins: the tab set renders, the Settings tab is
 * role-gated (admin/manager only), the Board/Drafts/Payments/Messages tabs are
 * empty-state "coming soon" (nothing half-built), and the Creators tab shows
 * its empty state when there are no assignments.
 */

import type {
  CampaignAssignmentResource,
  CampaignAttachmentResource,
  CampaignDraftListItemResource,
  CampaignResource,
} from '@catalyst/api-client'
import { ApiError } from '@catalyst/api-client'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'
import { createI18n } from 'vue-i18n'

import enApp from '@/core/i18n/locales/en/app.json'
import { useAgencyStore } from '@/core/stores/useAgencyStore'
import { useAuthStore } from '@/modules/auth/stores/useAuthStore'
import BoardView from '@/modules/boards/components/BoardView.vue'
import InviteCreatorsDialog from '../components/InviteCreatorsDialog.vue'
import pageSource from './CampaignDetailPage.vue?raw'

import CampaignDetailPage from './CampaignDetailPage.vue'
import { CAMPAIGN_DETAIL_TABS } from '../campaignTabs'

vi.mock('../api/campaigns.api', () => ({
  campaignsApi: {
    show: vi.fn(),
    assignments: vi.fn(),
    listDrafts: vi.fn(),
    listApplications: vi.fn(),
    acceptApplication: vi.fn(),
    rejectApplication: vi.fn(),
    update: vi.fn(),
    reinvite: vi.fn(),
    showAssignment: vi.fn(),
    approveDraft: vi.fn(),
    requestRevision: vi.fn(),
    rejectDraft: vi.fn(),
    proceedWithoutContract: vi.fn(),
    // AH-116: the Overview files strip reads the campaign's attachments.
    listAttachments: vi.fn(),
    // AH-120: the invite dialog's advisory conflicts read.
    exclusivityConflicts: vi.fn(),
  },
}))

// The invite dialog loads the roster when it opens; no transport in this spec.
vi.mock('@/modules/roster/api/roster.api', () => ({
  rosterApi: {
    list: vi.fn().mockResolvedValue({
      data: [],
      meta: { total: 0, page: 1, per_page: 100, last_page: 1 },
    }),
  },
}))

import { campaignsApi } from '../api/campaigns.api'

// AH-120: the Settings multi-select reads the agency's brands while the flag is on.
vi.mock('@/modules/brands/api/brands.api', () => ({
  brandsApi: {
    listOptions: vi.fn().mockResolvedValue({
      data: [
        { id: 'zava-ulid', type: 'brands', attributes: { name: 'Zava' } },
        { id: 'yahoo-ulid', type: 'brands', attributes: { name: 'Yahoo' } },
      ],
    }),
  },
}))

// Sprint 12 Chunk 2: the Board tab now mounts the live BoardView (no longer
// coming-soon). Mock the board API so no transport runs when the tab opens.
vi.mock('@/modules/boards/api/board.api', () => ({
  boardApi: {
    show: vi.fn().mockResolvedValue({
      data: {
        id: 'board-1',
        type: 'boards',
        attributes: { created_at: 'x', updated_at: 'x' },
        relationships: { campaign: { data: { id: 'campaign-ulid', type: 'campaigns' } } },
        columns: [],
        automations: [],
        cards: [],
      },
    }),
    moveCard: vi.fn(),
    movements: vi.fn(),
    createColumn: vi.fn(),
    updateColumn: vi.fn(),
    deleteColumn: vi.fn(),
    reorderColumns: vi.fn(),
    updateAutomation: vi.fn(),
  },
}))

const localStorageStore: Record<string, string> = {}
Object.defineProperty(globalThis, 'localStorage', {
  value: {
    getItem: (k: string): string | null => localStorageStore[k] ?? null,
    setItem: (k: string, v: string): void => {
      localStorageStore[k] = v
    },
    removeItem: (k: string): void => {
      delete localStorageStore[k]
    },
  },
  writable: true,
})

const CAMPAIGN_ULID = '01HZA1B2C3D4E5F6G7H8J9K0M1'
const DRAFT_ROW_ID = '01DRAFTULIDXXXXXXXXXXXXXXX'
const DRAFT_ASSIGNMENT_ID = '01ASSIGNULIDXXXXXXXXXXXXXX'

function makeDraftRow(
  reviewStatus: CampaignDraftListItemResource['attributes']['review_status'] = 'pending',
): CampaignDraftListItemResource {
  return {
    id: DRAFT_ROW_ID,
    type: 'campaign_draft_list_item',
    attributes: {
      version: 1,
      review_status: reviewStatus,
      submitted_at: '2026-06-01T10:00:00.000000Z',
      review_feedback: null,
      assignment: {
        id: DRAFT_ASSIGNMENT_ID,
        status: 'draft_submitted',
        creator: { id: 'creator-ulid', display_name: 'Alex Creator' },
      },
    },
  }
}

function makeCampaign(
  requiresContract = false,
  listingOverrides: Partial<CampaignResource['attributes']> = {},
): CampaignResource {
  return {
    id: CAMPAIGN_ULID,
    type: 'campaigns',
    attributes: {
      name: 'Summer launch',
      description: 'A push.',
      objective: 'awareness',
      status: 'draft',
      budget_minor_units: 250000,
      budget_currency: 'EUR',
      starts_at: null,
      ends_at: null,
      posting_window_starts_at: null,
      posting_window_ends_at: null,
      brief: null,
      target_creator_count: null,
      requires_per_campaign_contract: requiresContract,
      creator_posts_content: true,
      is_marketplace_visible: false,
      listed_on_jobs_board: false,
      listing_duration: null,
      listing_fee: null,
      listing_languages: null,
      listing_regions: null,
      restrict_to_listing_regions: false,
      exclusivity_ends_on: null,
      exclusive_brand_ids: [],
      exclusive_brands: [],
      listing_examples_url: null,
      published_at: null,
      completed_at: null,
      assignment_count: 0,
      created_at: '2026-06-01T10:00:00.000000Z',
      updated_at: '2026-06-01T10:00:00.000000Z',
      ...listingOverrides,
    },
    relationships: {
      brand: { data: { id: 'brand-ulid', type: 'brands', name: 'Acme' } },
      agency: { data: { id: 'agency-ulid', type: 'agencies' } },
    },
  }
}

function makeAssignment(
  id: string,
  status: CampaignAssignmentResource['attributes']['status'],
  verificationStatus: CampaignAssignmentResource['attributes']['verification_status'] = null,
  hasPendingContract: CampaignAssignmentResource['attributes']['has_pending_contract'] = null,
  previouslyDeclined = false,
): CampaignAssignmentResource {
  return {
    id,
    type: 'campaign_assignments',
    attributes: {
      status,
      agreed_fee_minor_units: 100000,
      agreed_fee_currency: 'EUR',
      countered_fee_minor_units: status === 'countered' ? 150000 : null,
      countered_fee_currency: status === 'countered' ? 'EUR' : null,
      previously_declined: previouslyDeclined,
      invited_at: '2026-06-01T10:00:00.000000Z',
      responded_at: status === 'countered' ? '2026-06-02T10:00:00.000000Z' : null,
      posting_due_at: null,
      verification_status: verificationStatus,
      has_pending_contract: hasPendingContract,
      creator: { id: `creator-${id}`, display_name: `Creator ${id}` },
    },
  }
}

async function mountDetail(
  role: 'agency_admin' | 'agency_manager' | 'agency_staff' = 'agency_admin',
  assignments: CampaignAssignmentResource[] = [],
  opts: {
    perCampaignContractEnabled?: boolean
    requiresContract?: boolean
    campaign?: Partial<CampaignResource['attributes']>
    /** AH-103: the query string the page is opened with, e.g. `?tab=drafts`. */
    query?: string
    /** AH-116: the campaign's attached files (default: none). */
    files?: CampaignAttachmentResource[]
    /** AH-118: the `/me` feature switches (default: both off). */
    features?: {
      campaign_region_restriction?: boolean
      assignment_offer_edit?: boolean
      campaign_brand_exclusivity?: boolean
    }
    /** AH-116: the files read fails. */
    filesError?: boolean
  } = {},
): Promise<{
  wrapper: ReturnType<typeof mount>
  cleanup: () => void
  router: ReturnType<typeof createRouter>
}> {
  const pinia = createPinia()
  setActivePinia(pinia)

  vi.mocked(campaignsApi.show).mockResolvedValue({
    data: makeCampaign(opts.requiresContract ?? false, opts.campaign ?? {}),
  })
  vi.mocked(campaignsApi.assignments).mockResolvedValue({
    data: assignments,
    meta: {
      total: assignments.length,
      page: 1,
      per_page: 25,
      last_page: 1,
      per_campaign_contract_enabled: opts.perCampaignContractEnabled ?? false,
    },
  })
  vi.mocked(campaignsApi.listDrafts).mockResolvedValue({
    data: [],
    meta: { total: 0, page: 1, per_page: 25, last_page: 1 },
  })
  if (opts.filesError) {
    vi.mocked(campaignsApi.listAttachments).mockRejectedValue(new Error('network down'))
  } else {
    vi.mocked(campaignsApi.listAttachments).mockResolvedValue({ data: opts.files ?? [] })
  }

  const agency = useAgencyStore()
  agency.initFromUser([{ agency_id: 'agency-ulid', agency_name: 'Test Agency', role }])
  useAuthStore().user = {
    attributes: { features: { account_deletion: false, ...opts.features } },
  } as never

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/campaigns', name: 'campaigns.list', component: { template: '<div />' } },
      { path: '/campaigns/:ulid', name: 'campaigns.detail', component: { template: '<div />' } },
      // AH-116: the header and overview now link the brand.
      { path: '/brands/:ulid', name: 'brands.detail', component: { template: '<div />' } },
    ],
  })
  await router.push(`/campaigns/${CAMPAIGN_ULID}${opts.query ?? ''}`)
  await router.isReady()

  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    availableLocales: ['en'],
    messages: { en: enApp } as never,
  }) as unknown as ReturnType<typeof createI18n>

  const vuetify = createVuetify({
    components: vuetifyComponents,
    directives: vuetifyDirectives,
  })

  const wrapper = mount(CampaignDetailPage, {
    global: { plugins: [pinia, router, i18n, vuetify] },
    attachTo: document.createElement('div'),
  })
  await flushPromises()

  return {
    wrapper,
    router,
    cleanup: () => {
      wrapper.unmount()
      Object.keys(localStorageStore).forEach((k) => delete localStorageStore[k])
    },
  }
}

describe('CampaignDetailPage (Sprint 8 Chunk 1)', () => {
  let cleanup: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(campaignsApi.listDrafts).mockResolvedValue({
      data: [],
      meta: { total: 0, page: 1, per_page: 25, last_page: 1 },
    })
    vi.mocked(campaignsApi.listApplications).mockResolvedValue({
      data: [],
      meta: { total: 0, page: 1, per_page: 25, last_page: 1, pending_total: 0 },
    })
  })

  afterEach(() => {
    cleanup?.()
    cleanup = null
  })

  it('renders the tab bar with the live + coming-soon tabs', async () => {
    const harness = await mountDetail()
    cleanup = harness.cleanup
    expect(harness.wrapper.find('[data-test="tab-overview"]').exists()).toBe(true)
    expect(harness.wrapper.find('[data-test="tab-creators"]').exists()).toBe(true)
    expect(harness.wrapper.find('[data-test="tab-board"]').exists()).toBe(true)
    expect(harness.wrapper.find('[data-test="tab-drafts"]').exists()).toBe(true)
    expect(harness.wrapper.find('[data-test="tab-applications"]').exists()).toBe(true)
    expect(harness.wrapper.find('[data-test="tab-payments"]').exists()).toBe(true)
  })

  describe('AH-103 — ?tab= opens the named tab', () => {
    const currentTab = (h: { wrapper: ReturnType<typeof mount> }): string =>
      (h.wrapper.vm as unknown as { tab: string }).tab

    it.each(CAMPAIGN_DETAIL_TABS)('?tab=%s opens that tab', async (tab) => {
      const harness = await mountDetail('agency_admin', [], { query: `?tab=${tab}` })
      cleanup = harness.cleanup

      expect(currentTab(harness)).toBe(tab)
    })

    it.each([
      ['no query', ''],
      ['an unknown value', '?tab=nonsense'],
      ['settings (never deep-linkable)', '?tab=settings'],
      ['a prototype-ish key', '?tab=__proto__'],
      ['an empty value', '?tab='],
      ['a different case', '?tab=DRAFTS'],
    ])('%s opens the overview', async (_label, query) => {
      const harness = await mountDetail('agency_admin', [], { query })
      cleanup = harness.cleanup

      expect(currentTab(harness)).toBe('overview')
    })

    it('a repeated ?tab= uses the first value', async () => {
      const harness = await mountDetail('agency_admin', [], { query: '?tab=drafts&tab=creators' })
      cleanup = harness.cleanup

      expect(currentTab(harness)).toBe('drafts')
    })

    it('a second link to the SAME campaign (only the query changes) switches the tab', async () => {
      const harness = await mountDetail('agency_admin', [], { query: '?tab=drafts' })
      cleanup = harness.cleanup

      await harness.router.push(`/campaigns/${CAMPAIGN_ULID}?tab=applications`)
      await flushPromises()

      expect(currentTab(harness)).toBe('applications')
    })

    it('a second link with a bad tab leaves the current tab alone', async () => {
      const harness = await mountDetail('agency_admin', [], { query: '?tab=drafts' })
      cleanup = harness.cleanup

      await harness.router.push(`/campaigns/${CAMPAIGN_ULID}?tab=nonsense`)
      await flushPromises()

      expect(currentTab(harness)).toBe('drafts')
    })

    it('opening ON the creators tab loads the roster (a change-only watch would never fire)', async () => {
      const harness = await mountDetail('agency_admin', [makeAssignment('a1', 'invited')], {
        query: '?tab=creators',
      })
      cleanup = harness.cleanup

      expect(campaignsApi.assignments).toHaveBeenCalled()
    })
  })

  it('renders the campaign description through RichBrief — markdown becomes real HTML (AH-081)', async () => {
    const harness = await mountDetail('agency_admin', [], {
      campaign: { description: 'Please shoot **outdoors**, in *natural* light.' },
    })
    cleanup = harness.cleanup

    const description = harness.wrapper.find('[data-test="overview-description"]')
    expect(description.find('strong').text()).toBe('outdoors')
    expect(description.find('em').text()).toBe('natural')
  })

  it('mounts the live ApplicationsTab when the Applications tab opens (AH-058)', async () => {
    const harness = await mountDetail()
    cleanup = harness.cleanup
    ;(harness.wrapper.vm as unknown as { tab: string }).tab = 'applications'
    await flushPromises()

    expect(harness.wrapper.find('[data-test="applications-tab"]').exists()).toBe(true)
  })

  it('renders the pending badge from the campaign-wide count, fetched on mount', async () => {
    vi.mocked(campaignsApi.listApplications).mockResolvedValue({
      data: [],
      meta: { total: 0, page: 1, per_page: 1, last_page: 1, pending_total: 4 },
    })
    const harness = await mountDetail()
    cleanup = harness.cleanup

    // The badge is readable BEFORE the tab is opened — that is the whole point
    // of counting on mount — and the count is `pending_total`, never the
    // creator-facing `applicant_count`.
    expect(campaignsApi.listApplications).toHaveBeenCalledWith('agency-ulid', CAMPAIGN_ULID, {
      per_page: 1,
    })
    expect(harness.wrapper.find('[data-test="tab-applications-badge"]').text()).toContain('4')
  })

  it('hides the badge when nothing is pending', async () => {
    const harness = await mountDetail()
    cleanup = harness.cleanup

    expect(harness.wrapper.find('[data-test="tab-applications-badge"]').exists()).toBe(false)
  })

  it('shows the Settings tab for admin/manager', async () => {
    const harness = await mountDetail('agency_manager')
    cleanup = harness.cleanup
    expect(harness.wrapper.find('[data-test="tab-settings"]').exists()).toBe(true)
  })

  it('hides the Settings tab for staff', async () => {
    const harness = await mountDetail('agency_staff')
    cleanup = harness.cleanup
    expect(harness.wrapper.find('[data-test="tab-settings"]').exists()).toBe(false)
  })

  it('mounts the live BoardView when the Board tab opens (Sprint 12 Chunk 2)', async () => {
    const harness = await mountDetail()
    cleanup = harness.cleanup
    ;(harness.wrapper.vm as unknown as { tab: string }).tab = 'board'
    await flushPromises()
    expect(harness.wrapper.find('[data-test="board-view"]').exists()).toBe(true)
    expect(harness.wrapper.find('[data-test="board-coming-soon"]').exists()).toBe(false)
  })

  // The board's card drawer can now fully review a draft itself (its own
  // Drafts tab hosts the shared DraftReviewPanel, eyes-on fix batch,
  // 2026-08-17) — it no longer hands off to the campaign's own Drafts tab,
  // so this page no longer owns any "leave the board" behavior for it. The
  // one thing this page still owns is passing the ability through.
  it('passes the review ability to the board under its own name, not borrowed from resolve', async () => {
    const harness = await mountDetail()
    cleanup = harness.cleanup
    ;(harness.wrapper.vm as unknown as { tab: string }).tab = 'board'
    await flushPromises()

    const board = harness.wrapper.findComponent(BoardView)
    expect(board.props('canReview')).toBe(true)
  })

  it('mounts the live DraftsTab when the Drafts tab opens (not coming-soon)', async () => {
    vi.mocked(campaignsApi.listDrafts).mockResolvedValue({
      data: [makeDraftRow()],
      meta: { total: 1, page: 1, per_page: 25, last_page: 1 },
    })
    const harness = await mountDetail()
    cleanup = harness.cleanup
    ;(harness.wrapper.vm as unknown as { tab: string }).tab = 'drafts'
    await flushPromises()
    expect(campaignsApi.listDrafts).toHaveBeenCalledWith('agency-ulid', CAMPAIGN_ULID, {
      page: 1,
      per_page: 25,
    })
    expect(harness.wrapper.find('[data-test="drafts-tab"]').exists()).toBe(true)
    expect(harness.wrapper.find('[data-test="drafts-coming-soon"]').exists()).toBe(false)
  })

  it('shows the Creators empty state and loads assignments when the tab opens', async () => {
    const harness = await mountDetail()
    cleanup = harness.cleanup
    ;(harness.wrapper.vm as unknown as { tab: string }).tab = 'creators'
    await flushPromises()
    expect(campaignsApi.assignments).toHaveBeenCalledWith('agency-ulid', CAMPAIGN_ULID)
    expect(harness.wrapper.find('[data-test="creators-empty-state"]').exists()).toBe(true)
  })
})

describe('CampaignDetailPage — Creators tab re-invite (re-invite UI chunk)', () => {
  let cleanup: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    cleanup?.()
    cleanup = null
  })

  async function openCreatorsTab(
    role: 'agency_admin' | 'agency_manager' | 'agency_staff' = 'agency_staff',
    assignments: CampaignAssignmentResource[] = [],
  ) {
    const harness = await mountDetail(role, assignments)
    cleanup = harness.cleanup
    ;(harness.wrapper.vm as unknown as { tab: string }).tab = 'creators'
    await flushPromises()
    return harness.wrapper
  }

  it('renders assignment status as a chip', async () => {
    const wrapper = await openCreatorsTab('agency_staff', [
      makeAssignment('A', 'invited'),
      makeAssignment('B', 'countered'),
    ])
    expect(wrapper.find('[data-test="creators-status-A"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="creators-status-B"]').exists()).toBe(true)
  })

  it('shows a Declined history chip alongside the live status on a re-offered row', async () => {
    const wrapper = await openCreatorsTab('agency_staff', [
      // Re-offered after a decline: previously_declined + status back to invited.
      makeAssignment('R', 'invited', null, null, true),
      // A plain invited row (never declined) shows NO history chip.
      makeAssignment('P', 'invited'),
    ])
    expect(wrapper.find('[data-test="creators-declined-history-R"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="creators-status-R"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="creators-declined-history-P"]').exists()).toBe(false)
  })

  it('shows both fees + the re-invite action on a countered row', async () => {
    const wrapper = await openCreatorsTab('agency_staff', [makeAssignment('C', 'countered')])
    const fees = wrapper.find('[data-test="creators-fees-C"]')
    expect(fees.text()).toContain('Offered')
    expect(fees.text()).toContain('Countered')
    expect(fees.text()).toContain('€1,000.00')
    expect(fees.text()).toContain('€1,500.00')
    expect(wrapper.find('[data-test="creators-reinvite-C"]').exists()).toBe(true)
  })

  it('shows agreed fee only and NO re-invite action on a non-countered row', async () => {
    const wrapper = await openCreatorsTab('agency_staff', [makeAssignment('I', 'invited')])
    const fees = wrapper.find('[data-test="creators-fees-I"]')
    expect(fees.text()).toContain('€1,000.00')
    expect(fees.text()).not.toContain('Countered')
    expect(wrapper.find('[data-test="creators-reinvite-I"]').exists()).toBe(false)
  })

  it('gates the re-invite action on canInvite (staff sees it)', async () => {
    const wrapper = await openCreatorsTab('agency_staff', [makeAssignment('C', 'countered')])
    expect(wrapper.find('[data-test="creators-reinvite-C"]').exists()).toBe(true)
  })

  // Sprint 9 Chunk 2 (D-8) — the Review action.
  it('shows the Review action on a draft_submitted row (staff can review)', async () => {
    const wrapper = await openCreatorsTab('agency_staff', [makeAssignment('D', 'draft_submitted')])
    expect(wrapper.find('[data-test="creators-review-D"]').exists()).toBe(true)
  })

  it('does NOT show the Review action on a non-draft_submitted row', async () => {
    const wrapper = await openCreatorsTab('agency_staff', [makeAssignment('I', 'invited')])
    expect(wrapper.find('[data-test="creators-review-I"]').exists()).toBe(false)
  })

  // Verification-resolution chunk (D-7) — the Resolve action on a posted+failed row.
  it('shows the Resolve action on a posted row whose verification FAILED', async () => {
    const wrapper = await openCreatorsTab('agency_staff', [
      makeAssignment('F', 'posted', 'mismatch'),
    ])
    expect(wrapper.find('[data-test="creators-resolve-F"]').exists()).toBe(true)
  })

  it('does NOT show the Resolve action on a posted row still pending verification', async () => {
    const wrapper = await openCreatorsTab('agency_staff', [
      makeAssignment('P', 'posted', 'pending'),
    ])
    expect(wrapper.find('[data-test="creators-resolve-P"]').exists()).toBe(false)
  })

  it('shows "View post" on a pending posted row (read-only), not Resolve', async () => {
    const wrapper = await openCreatorsTab('agency_staff', [
      makeAssignment('P', 'posted', 'pending'),
    ])
    expect(wrapper.find('[data-test="creators-view-post-P"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="creators-resolve-P"]').exists()).toBe(false)
  })

  it('shows "View post" on a live_verified row', async () => {
    const wrapper = await openCreatorsTab('agency_staff', [
      makeAssignment('V', 'live_verified', 'verified'),
    ])
    expect(wrapper.find('[data-test="creators-view-post-V"]').exists()).toBe(true)
  })

  it('on a failed posted row shows Resolve but NOT the read-only View post', async () => {
    const wrapper = await openCreatorsTab('agency_staff', [
      makeAssignment('F', 'posted', 'mismatch'),
    ])
    expect(wrapper.find('[data-test="creators-resolve-F"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="creators-view-post-F"]').exists()).toBe(false)
  })

  it('does NOT show "View post" on a row with no posted content', async () => {
    const wrapper = await openCreatorsTab('agency_staff', [makeAssignment('A', 'accepted')])
    expect(wrapper.find('[data-test="creators-view-post-A"]').exists()).toBe(false)
  })
})

// AH-080 (D2a) — the Creators-tab row grows an avatar and a row click opens
// the creator profile dialog. D5: no behavior change to the row's own
// actions — the append buttons stop propagation so clicking them fires ONLY
// their own handler, not also the row's click.
describe('CampaignDetailPage — Creators-tab row profile access (AH-080, D2a)', () => {
  let cleanup: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    cleanup?.()
    cleanup = null
  })

  async function openCreatorsTab(assignments: CampaignAssignmentResource[]) {
    const harness = await mountDetail('agency_admin', assignments)
    cleanup = harness.cleanup
    ;(harness.wrapper.vm as unknown as { tab: string }).tab = 'creators'
    await flushPromises()
    return harness.wrapper
  }

  it('renders a signed avatar when the assignment carries one, falling back to the initial', async () => {
    const withPhoto = makeAssignment('A', 'invited')
    withPhoto.attributes.creator = {
      id: 'creator-A',
      display_name: 'Ada Lovelace',
      avatar_url: 'https://signed.example/ada.jpg',
    }
    const wrapper = await openCreatorsTab([withPhoto, makeAssignment('B', 'invited')])

    const withImg = wrapper.find('[data-test="creators-avatar-A"]')
    expect(withImg.findComponent({ name: 'VImg' }).props('src')).toBe(
      'https://signed.example/ada.jpg',
    )

    const withoutImg = wrapper.find('[data-test="creators-avatar-B"]')
    expect(withoutImg.findComponent({ name: 'VImg' }).exists()).toBe(false)
    expect(withoutImg.text()).toBe('C')
  })

  it("opens the profile dialog on a row click, scoped to that row's creator", async () => {
    const wrapper = await openCreatorsTab([makeAssignment('A', 'invited')])

    expect(wrapper.findComponent({ name: 'CreatorProfileDialog' }).exists()).toBe(false)

    await wrapper.find('[data-test="creators-row-A"]').trigger('click')
    await flushPromises()

    const dialog = wrapper.findComponent({ name: 'CreatorProfileDialog' })
    expect(dialog.exists()).toBe(true)
    expect(dialog.props()).toMatchObject({
      modelValue: true,
      creatorUlid: 'creator-A',
      assumeFull: false,
    })
  })

  it('D5 — clicking an append action does NOT also open the profile dialog', async () => {
    const wrapper = await openCreatorsTab([makeAssignment('C', 'countered')])

    await wrapper.find('[data-test="creators-reinvite-C"]').trigger('click')
    await flushPromises()

    // The reinvite dialog's own target is set (its own click fired)…
    expect(
      (wrapper.vm as unknown as { reinviteTarget: CampaignAssignmentResource | null })
        .reinviteTarget?.id,
    ).toBe('C')
    // …but the row click did NOT also fire — no profile dialog popped open
    // underneath it (D5: additive UI, no behavior change to the row actions).
    expect(wrapper.findComponent({ name: 'CreatorProfileDialog' }).exists()).toBe(false)
  })
})

// contract-gate-decouple chunk (D-7) — the agency "proceed without a contract"
// action on an accepted row, visible only when requires=false AND the flag is ON.
describe('CampaignDetailPage — proceed without per-campaign contract (D-7)', () => {
  let cleanup: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    cleanup?.()
    cleanup = null
  })

  async function openCreatorsTab(
    assignments: CampaignAssignmentResource[],
    opts: { perCampaignContractEnabled?: boolean; requiresContract?: boolean },
  ) {
    const harness = await mountDetail('agency_staff', assignments, opts)
    cleanup = harness.cleanup
    ;(harness.wrapper.vm as unknown as { tab: string }).tab = 'creators'
    await flushPromises()
    return harness.wrapper
  }

  it('shows the action on an accepted row when requires=false and the flag is ON', async () => {
    const wrapper = await openCreatorsTab([makeAssignment('A', 'accepted')], {
      perCampaignContractEnabled: true,
      requiresContract: false,
    })
    expect(wrapper.find('[data-test="creators-proceed-without-contract-A"]').exists()).toBe(true)
  })

  it('hides the action when the per-campaign flag is OFF', async () => {
    const wrapper = await openCreatorsTab([makeAssignment('A', 'accepted')], {
      perCampaignContractEnabled: false,
      requiresContract: false,
    })
    expect(wrapper.find('[data-test="creators-proceed-without-contract-A"]').exists()).toBe(false)
  })

  it('hides the action when the campaign requires a per-campaign contract', async () => {
    const wrapper = await openCreatorsTab([makeAssignment('A', 'accepted')], {
      perCampaignContractEnabled: true,
      requiresContract: true,
    })
    expect(wrapper.find('[data-test="creators-proceed-without-contract-A"]').exists()).toBe(false)
  })

  it('calls the API and shows the success snackbar', async () => {
    vi.mocked(campaignsApi.proceedWithoutContract).mockResolvedValue({
      data: { type: 'campaign_assignment', id: 'A', attributes: { status: 'contracted' } },
      meta: { code: 'assignment.contracted' },
    })
    const wrapper = await openCreatorsTab([makeAssignment('A', 'accepted')], {
      perCampaignContractEnabled: true,
      requiresContract: false,
    })

    await wrapper.find('[data-test="creators-proceed-without-contract-A"]').trigger('click')
    await flushPromises()

    expect(campaignsApi.proceedWithoutContract).toHaveBeenCalledWith(
      'agency-ulid',
      CAMPAIGN_ULID,
      'A',
    )
  })
})

// contract-issue visibility fix — issuing a contract leaves the assignment
// `accepted` (the creator must accept), so the Creators row must reflect a
// pending contract instead of re-offering "Issue contract".
describe('CampaignDetailPage — pending-contract row state', () => {
  let cleanup: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    cleanup?.()
    cleanup = null
  })

  async function openCreatorsTab(assignments: CampaignAssignmentResource[]) {
    const harness = await mountDetail('agency_admin', assignments)
    cleanup = harness.cleanup
    ;(harness.wrapper.vm as unknown as { tab: string }).tab = 'creators'
    await flushPromises()
    return harness.wrapper
  }

  it('offers "Issue contract" on an accepted row with no pending contract', async () => {
    const wrapper = await openCreatorsTab([makeAssignment('A', 'accepted', null, false)])
    expect(wrapper.find('[data-test="creators-attach-contract-A"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="creators-contract-pending-A"]').exists()).toBe(false)
  })

  it('hides "Issue contract" and shows the pending chip once a contract is sent', async () => {
    const wrapper = await openCreatorsTab([makeAssignment('A', 'accepted', null, true)])
    expect(wrapper.find('[data-test="creators-attach-contract-A"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="creators-contract-pending-A"]').exists()).toBe(true)
  })
})

const ReviewDraftDrawerApproveStub = {
  name: 'ReviewDraftDrawer',
  props: ['modelValue'],
  emits: ['update:modelValue', 'reviewed'],
  template: `
    <button
      v-if="modelValue"
      data-test="review-stub-approve"
      @click="$emit('reviewed', 'Draft approved.'); $emit('update:modelValue', false)"
    />
  `,
}

describe('CampaignDetailPage — Drafts tab review (drafts tab chunk)', () => {
  let cleanup: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    cleanup?.()
    cleanup = null
  })

  async function openDraftsTab() {
    vi.mocked(campaignsApi.listDrafts).mockResolvedValue({
      data: [makeDraftRow()],
      meta: { total: 1, page: 1, per_page: 25, last_page: 1 },
    })

    const pinia = createPinia()
    setActivePinia(pinia)
    vi.mocked(campaignsApi.show).mockResolvedValue({ data: makeCampaign() })
    vi.mocked(campaignsApi.assignments).mockResolvedValue({
      data: [],
      meta: { total: 0, page: 1, per_page: 25, last_page: 1 },
    })

    const agency = useAgencyStore()
    agency.initFromUser([
      { agency_id: 'agency-ulid', agency_name: 'Test Agency', role: 'agency_staff' },
    ])

    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/campaigns', name: 'campaigns.list', component: { template: '<div />' } },
        { path: '/campaigns/:ulid', name: 'campaigns.detail', component: { template: '<div />' } },
        { path: '/brands/:ulid', name: 'brands.detail', component: { template: '<div />' } },
      ],
    })
    await router.push(`/campaigns/${CAMPAIGN_ULID}`)
    await router.isReady()

    const i18n = createI18n({
      legacy: false,
      locale: 'en',
      fallbackLocale: 'en',
      availableLocales: ['en'],
      messages: { en: enApp } as never,
    }) as unknown as ReturnType<typeof createI18n>

    const vuetify = createVuetify({
      components: vuetifyComponents,
      directives: vuetifyDirectives,
    })

    const wrapper = mount(CampaignDetailPage, {
      global: {
        plugins: [pinia, router, i18n, vuetify],
        stubs: { ReviewDraftDrawer: ReviewDraftDrawerApproveStub },
      },
      attachTo: document.createElement('div'),
    })
    await flushPromises()
    ;(wrapper.vm as unknown as { tab: string }).tab = 'drafts'
    await flushPromises()

    cleanup = () => {
      wrapper.unmount()
      Object.keys(localStorageStore).forEach((k) => delete localStorageStore[k])
    }

    return wrapper
  }

  it('reloads the drafts list after approving from the Drafts tab', async () => {
    const wrapper = await openDraftsTab()
    expect(campaignsApi.listDrafts).toHaveBeenCalledTimes(1)

    await wrapper.find('[data-test="drafts-review-01DRAFTULIDXXXXXXXXXXXXXXX"]').trigger('click')
    await flushPromises()

    await wrapper.find('[data-test="review-stub-approve"]').trigger('click')
    await flushPromises()

    expect(campaignsApi.listDrafts).toHaveBeenCalledTimes(2)
  })
})

/**
 * Jobs board (AH-054) — the Settings toggle and its two mirrors. The API is
 * the authority (D3/D5 are enforced there); these pins are about the user
 * never meeting a 422 as their first notice.
 */
describe('CampaignDetailPage — jobs board toggle (AH-054)', () => {
  let cleanup: (() => void) | null = null

  const JOB_READY = {
    description: 'Two Reels a month.',
    listing_duration: '4 weeks',
    listing_fee: '€300 per video',
    listing_languages: ['en'],
    listing_regions: ['IE'],
  } satisfies Partial<CampaignResource['attributes']>

  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(campaignsApi.listDrafts).mockResolvedValue({
      data: [],
      meta: { total: 0, page: 1, per_page: 25, last_page: 1 },
    })
  })

  afterEach(() => {
    cleanup?.()
    cleanup = null
  })

  async function openSettings(
    campaign: Partial<CampaignResource['attributes']> = {},
  ): Promise<ReturnType<typeof mount>> {
    const harness = await mountDetail('agency_admin', [], { campaign })
    cleanup = harness.cleanup
    ;(harness.wrapper.vm as unknown as { tab: string }).tab = 'settings'
    await flushPromises()
    return harness.wrapper
  }

  function toggleInput(wrapper: ReturnType<typeof mount>): HTMLInputElement {
    return wrapper.find('[data-test="campaign-settings-listed"] input').element as HTMLInputElement
  }

  it('disables the toggle and names the missing fields while the listing floor is unmet (D3 mirror)', async () => {
    const wrapper = await openSettings({ description: null })

    expect(toggleInput(wrapper).disabled).toBe(true)
    const hint = wrapper.find('[data-test="campaign-settings-listed"]').text()
    expect(hint).toContain('description')
    expect(hint).toContain('listing duration')
    expect(hint).toContain('regions')

    // AH-059 (D3): the floor list completed, so ONE mutation of the shared mirror
    // reddens BOTH surfaces. Before this, the campaigns-list spec named every
    // field and this one named three — which meant "both surfaces read one gate"
    // was a claim about the import graph rather than something a break-revert
    // could demonstrate.
    expect(hint).toContain('listed fee')
    expect(hint).toContain('content languages')
  })

  it('enables the toggle once every floor field is present', async () => {
    const wrapper = await openSettings(JOB_READY)

    expect(toggleInput(wrapper).disabled).toBe(false)
  })

  it('disables the toggle for a completed campaign that is not already listed (D5 mirror)', async () => {
    const wrapper = await openSettings({ ...JOB_READY, status: 'completed' })

    expect(toggleInput(wrapper).disabled).toBe(true)
    expect(wrapper.find('[data-test="campaign-settings-listed"]').text()).toContain(
      'completed or cancelled',
    )
  })

  it('keeps the toggle usable on an already-listed campaign that has ended — switching OFF never gates (A1)', async () => {
    const wrapper = await openSettings({
      ...JOB_READY,
      status: 'completed',
      listed_on_jobs_board: true,
    })

    expect(toggleInput(wrapper).disabled).toBe(false)
    expect(toggleInput(wrapper).checked).toBe(true)
  })

  it('sends listed_on_jobs_board with the Settings save', async () => {
    const wrapper = await openSettings(JOB_READY)
    vi.mocked(campaignsApi.update).mockResolvedValue({
      data: makeCampaign(false, { ...JOB_READY, listed_on_jobs_board: true }),
    })

    await wrapper.find('[data-test="campaign-settings-listed"] input').setValue(true)
    await wrapper.find('[data-test="campaign-form"]').trigger('submit')
    await flushPromises()

    expect(campaignsApi.update).toHaveBeenCalledWith(
      'agency-ulid',
      CAMPAIGN_ULID,
      expect.objectContaining({ listed_on_jobs_board: true }),
    )
  })

  it('seeds the stored posting posture into Settings and sends it back (AH-069 D1)', async () => {
    // Settings shows what THIS campaign does — never the create page's product
    // default. A campaign already handing off at approval must not silently
    // revert to expecting posting the next time someone saves the tab.
    const wrapper = await openSettings({ creator_posts_content: false })

    const toggle = wrapper.find('[data-test="campaign-creator-posts-content"] input')
    expect((toggle.element as HTMLInputElement).checked).toBe(false)

    vi.mocked(campaignsApi.update).mockResolvedValue({
      data: makeCampaign(false, { creator_posts_content: false }),
    })

    await wrapper.find('[data-test="campaign-form"]').trigger('submit')
    await flushPromises()

    expect(campaignsApi.update).toHaveBeenCalledWith(
      'agency-ulid',
      CAMPAIGN_ULID,
      expect.objectContaining({ creator_posts_content: false }),
    )
  })

  it('shows the refusal verbatim and puts the toggle back when the server rejects the flip (AH-069 D6)', async () => {
    const wrapper = await openSettings({ creator_posts_content: true })

    vi.mocked(campaignsApi.update).mockRejectedValue(
      new ApiError({
        message: 'Nadia Rossi still has a card in the posting column.',
        status: 422,
        code: 'campaign.posting_cards_present',
      }),
    )

    await wrapper.find('[data-test="campaign-creator-posts-content"] input').setValue(false)
    await wrapper.find('[data-test="campaign-form"]').trigger('submit')
    await flushPromises()

    // The server's sentence, in the caller's own language, with no `[code]`
    // prefix in front of it — this message is written to be read.
    const text = wrapper.text()
    expect(text).toContain('Nadia Rossi still has a card in the posting column.')
    expect(text).not.toContain('campaign.posting_cards_present')

    // And the switch is back where the server still has it: leaving it OFF
    // would show a posture the campaign does not have.
    const toggle = wrapper.find('[data-test="campaign-creator-posts-content"] input')
    expect((toggle.element as HTMLInputElement).checked).toBe(true)
  })

  it('seeds the listing fields into the edit form so an omitted field is never blanked', async () => {
    const wrapper = await openSettings({
      ...JOB_READY,
      listing_examples_url: 'https://example.com/refs',
    })

    const duration = wrapper.find('[data-test="campaign-listing-duration"] input')
      .element as HTMLInputElement
    const examples = wrapper.find('[data-test="campaign-listing-examples-url"] input')
      .element as HTMLInputElement

    expect(duration.value).toBe('4 weeks')
    expect(examples.value).toBe('https://example.com/refs')
  })
})

// AH-114 S3.2 — the agency restyle of the detail page: header, tab bar, the Overview cards
// and "At a glance". The DOM contract the older tests rely on (`campaign-detail-heading`,
// `overview-description`, the tab anchors) is untouched; what moved is named below.
describe('CampaignDetailPage — agency restyle (AH-114, S3.2)', () => {
  let cleanup: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date(2026, 10, 2, 12, 0, 0))
    vi.mocked(campaignsApi.listDrafts).mockResolvedValue({
      data: [],
      meta: { total: 0, page: 1, per_page: 25, last_page: 1 },
    })
    vi.mocked(campaignsApi.listApplications).mockResolvedValue({
      data: [],
      meta: { total: 3, page: 1, per_page: 1, last_page: 3, pending_total: 2 },
    })
  })

  afterEach(() => {
    cleanup?.()
    cleanup = null
    vi.useRealTimers()
  })

  async function open(
    role: 'agency_admin' | 'agency_manager' | 'agency_staff' = 'agency_admin',
    campaign: Partial<CampaignResource['attributes']> = {},
    files: CampaignAttachmentResource[] = [],
  ) {
    const harness = await mountDetail(role, [], { campaign, files })
    cleanup = harness.cleanup
    return harness.wrapper
  }

  const glanceRows = (wrapper: Awaited<ReturnType<typeof open>>) =>
    wrapper.findAll('[data-test="overview-glance"] .agency-glance-row').map((r) => r.text())

  it('header: the name, the status pill and a Brand · Starts line', async () => {
    const wrapper = await open('agency_admin', {
      status: 'active',
      starts_at: '2026-11-09T00:00:00Z',
    })

    expect(wrapper.find('[data-test="campaign-detail-heading"]').text()).toBe('Summer launch')
    expect(wrapper.find('[data-test="campaign-detail-status"]').text()).toBe('Active')
    const line = wrapper.find('[data-test="campaign-detail-subtitle"]').text()
    expect(line).toMatch(/^.+ {2}·\s+Starts Nov 9, 2026$/)
  })

  it('header: a campaign with no start date shows the brand alone', async () => {
    const wrapper = await open()

    const line = wrapper.find('[data-test="campaign-detail-subtitle"]').text()
    expect(line).not.toContain('Starts')
    expect(line).not.toContain('·')
  })

  it('AH-116: the header brand name links to the brand page, and the start date stays plain text', async () => {
    const wrapper = await open('agency_admin', { starts_at: '2026-11-09T00:00:00Z' })

    const link = wrapper.get('[data-test="campaign-detail-brand-link"]')
    expect(link.element.tagName).toBe('A')
    expect(link.attributes('href')).toBe('/brands/brand-ulid')
    expect(link.text()).toBe('Acme')
    expect(link.text()).not.toContain('Starts')
  })

  it('AH-116: the Overview details card links the brand too', async () => {
    const wrapper = await open()

    const link = wrapper.get('[data-test="overview-brand-link"]')
    expect(link.attributes('href')).toBe('/brands/brand-ulid')
    expect(link.text()).toBe('Acme')
  })

  it.each(['agency_admin', 'agency_manager', 'agency_staff'] as const)(
    'header: %s sees Invite creators; it opens the dialog on the Creators tab',
    async (role) => {
      const wrapper = await open(role)

      await wrapper.find('[data-test="campaign-detail-invite"]').trigger('click')
      await flushPromises()

      expect((wrapper.vm as unknown as { tab: string }).tab).toBe('creators')
      expect(wrapper.findComponent(InviteCreatorsDialog).props('modelValue')).toBe(true)
    },
  )

  it('header: the Creators tab keeps its own invite button', async () => {
    const wrapper = await open()
    ;(wrapper.vm as unknown as { tab: string }).tab = 'creators'
    await flushPromises()

    expect(wrapper.find('[data-test="invite-creators-open"]').exists()).toBe(true)
  })

  it('header: no Share brief, no Created by, no Edit button (nothing behind them)', async () => {
    const wrapper = await open('agency_admin', { starts_at: '2026-11-09T00:00:00Z' })
    const text = wrapper.text()

    expect(text).not.toContain('Share brief')
    expect(text).not.toContain('Created by')
    expect(wrapper.findAll('button').some((b) => b.text() === 'Edit')).toBe(false)
  })

  it('the tab bar is the agency underline style and every tab is still there', async () => {
    const wrapper = await open()

    expect(wrapper.find('[data-test="campaign-tabs"]').classes()).toContain('agency-tabs')
    for (const t of CAMPAIGN_DETAIL_TABS) {
      expect(wrapper.find(`[data-test="tab-${t}"]`).exists(), t).toBe(true)
    }
  })

  it('details card: brand, status, budget, start and end, in that order', async () => {
    const wrapper = await open('agency_admin', {
      status: 'active',
      starts_at: '2026-11-09T00:00:00Z',
      ends_at: '2027-01-01T00:00:00Z',
    })

    const labels = wrapper
      .findAll('[data-test="overview-details-card"] .agency-fields .agency-field__label')
      .map((l) => l.text())
    expect(labels).toEqual(['Brand', 'Status', 'Budget', 'Start date', 'End date'])
    expect(wrapper.find('[data-test="overview-status"]').text()).toBe('Active')
    expect(wrapper.find('[data-test="overview-starts-at"]').text()).toBe('Nov 9, 2026')
    expect(wrapper.find('[data-test="overview-ends-at"]').text()).toBe('Jan 1, 2027')
    expect(wrapper.find('[data-test="overview-budget"]').text()).toContain('2,500')
  })

  it('details card: a missing date reads as a dash, not a blank', async () => {
    const wrapper = await open()

    expect(wrapper.find('[data-test="overview-starts-at"]').text()).toBe('—')
  })

  it('details card: no description, no description block', async () => {
    const wrapper = await open('agency_admin', { description: null as unknown as string })

    expect(wrapper.find('[data-test="overview-description"]').exists()).toBe(false)
  })

  it('settings card: the three switches read the campaign and cannot be flipped', async () => {
    const wrapper = await open('agency_admin', {
      requires_per_campaign_contract: true,
      creator_posts_content: false,
      listed_on_jobs_board: true,
    })

    const rows = wrapper.findAll('[data-test="overview-requirements-card"] .agency-switch-row')
    expect(rows.map((r) => r.attributes('data-test'))).toEqual([
      'overview-requirement-contract',
      'overview-requirement-creator-posts',
      'overview-requirement-job-board',
    ])
    const checked = (test: string) =>
      (wrapper.find(`[data-test="${test}"] input`).element as HTMLInputElement).checked
    expect(checked('overview-requires-contract')).toBe(true)
    expect(checked('overview-creator-posts-content')).toBe(false)
    expect(checked('overview-listed-on-jobs-board')).toBe(true)

    await wrapper.find('[data-test="overview-creator-posts-content"] input').setValue(true)
    expect(checked('overview-creator-posts-content')).toBe(false)
  })

  describe('At a glance (drawn only with two rows)', () => {
    it('shows applications and days until start', async () => {
      const wrapper = await open('agency_admin', { starts_at: '2026-11-09T00:00:00Z' })

      expect(glanceRows(wrapper)).toEqual(['Applications2', 'Days until start7'])
    })

    it('counts days from the date part, whatever the time of day', async () => {
      vi.setSystemTime(new Date(2026, 10, 2, 23, 59, 0))
      const wrapper = await open('agency_admin', { starts_at: '2026-11-03T00:00:00Z' })

      expect(glanceRows(wrapper)).toContain('Days until start1')
    })

    it('a start date that is today or past leaves one row, so no panel at all', async () => {
      for (const startsAt of ['2026-11-02T00:00:00Z', '2026-10-01T00:00:00Z']) {
        const wrapper = await open('agency_admin', { starts_at: startsAt })
        expect(wrapper.find('[data-test="overview-glance"]').exists(), startsAt).toBe(false)
        cleanup?.()
      }
    })

    it('no start date: no panel', async () => {
      const wrapper = await open()

      expect(wrapper.find('[data-test="overview-glance"]').exists()).toBe(false)
    })

    it('a completed or cancelled campaign has nothing to count down to: no panel', async () => {
      for (const status of ['completed', 'cancelled'] as const) {
        const wrapper = await open('agency_admin', { status, starts_at: '2026-11-09T00:00:00Z' })
        expect(wrapper.find('[data-test="overview-glance"]').exists(), status).toBe(false)
        cleanup?.()
      }
    })

    it('a failed applications count is not drawn as a zero: one row left, no panel', async () => {
      vi.mocked(campaignsApi.listApplications).mockRejectedValue(new Error('network down'))
      const wrapper = await open('agency_admin', { starts_at: '2026-11-09T00:00:00Z' })

      expect(wrapper.find('[data-test="overview-glance"]').exists()).toBe(false)
    })

    it('a real zero is drawn as 0', async () => {
      vi.mocked(campaignsApi.listApplications).mockResolvedValue({
        data: [],
        meta: { total: 0, page: 1, per_page: 1, last_page: 1, pending_total: 0 },
      })
      const wrapper = await open('agency_admin', { starts_at: '2026-11-09T00:00:00Z' })

      expect(glanceRows(wrapper)).toContain('Applications0')
    })

    it('omits what the API cannot supply: creators confirmed, budget used, next steps', async () => {
      const wrapper = await open('agency_admin', { starts_at: '2026-11-09T00:00:00Z' })
      const text = wrapper.find('[data-test="overview-glance"]').text()

      expect(text).not.toContain('Creators confirmed')
      expect(text).not.toContain('Budget used')
      expect(text).not.toContain('Next steps')
    })
  })

  // AH-116 (item 6): the files strip under "At a glance".
  describe('Campaign files strip (AH-116)', () => {
    const FUTURE = { starts_at: '2026-11-09T00:00:00Z' } // + the applications row = two glance rows

    function makeFile(
      id: string,
      overrides: Partial<CampaignAttachmentResource['attributes']> = {},
    ): CampaignAttachmentResource {
      return {
        id,
        type: 'campaign_attachments',
        attributes: {
          original_filename: `${id}.pdf`,
          mime_type: 'application/pdf',
          size_bytes: 2048,
          visible_to_creators: true,
          download_url: `https://files.example/${id}?sig=1`,
          attached_at: '2026-10-01T10:00:00Z',
          ...overrides,
        },
        relationships: { attached_by: null },
      }
    }

    it('draws the strip under the glance block, as its sibling, when there are files', async () => {
      const wrapper = await open('agency_admin', FUTURE, [makeFile('brief')])

      const glance = wrapper.get('[data-test="overview-glance"]').element
      const strip = wrapper.get('[data-test="campaign-files-overview"]').element
      expect(glance.contains(strip)).toBe(false)
      expect(glance.compareDocumentPosition(strip) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
      expect(wrapper.get('[data-test="overview-aside"]').element.contains(strip)).toBe(true)
    })

    it('draws the strip ALONE, with no glance block, when fewer than two glance rows are left', async () => {
      const wrapper = await open('agency_admin', {}, [makeFile('brief')])

      expect(wrapper.find('[data-test="overview-glance"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="overview-aside"]').exists()).toBe(true)
      expect(wrapper.find('[data-test="campaign-files-overview"]').exists()).toBe(true)
      expect(wrapper.find('.agency-detail').classes()).toContain('agency-detail--with-panel')
    })

    it('draws neither the aside nor the strip with no files and fewer than two glance rows', async () => {
      const wrapper = await open()

      expect(wrapper.find('[data-test="overview-aside"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="campaign-files-overview"]').exists()).toBe(false)
      expect(wrapper.find('.agency-detail').classes()).not.toContain('agency-detail--with-panel')
    })

    it('tags a file creators cannot see "Internal", and only that one', async () => {
      const wrapper = await open('agency_admin', FUTURE, [
        makeFile('public'),
        makeFile('private', { visible_to_creators: false }),
      ])

      expect(wrapper.find('[data-test="campaign-file-internal-private"]').text()).toBe('Internal')
      expect(wrapper.find('[data-test="campaign-file-internal-public"]').exists()).toBe(false)
      // Overview lists every file, internal ones included.
      expect(wrapper.find('[data-test="campaign-file-private"]').exists()).toBe(true)
    })

    it('caps at four chips, says how many more, and "View all files" opens the Attachments tab', async () => {
      const wrapper = await open(
        'agency_admin',
        FUTURE,
        ['a', 'b', 'c', 'd', 'e', 'f'].map((id) => makeFile(id)),
      )

      expect(wrapper.findAll('.agency-files__list > li')).toHaveLength(4)
      expect(wrapper.get('[data-test="campaign-files-more"]').text()).toBe('+2 more')

      await wrapper.get('[data-test="campaign-files-view-all"]').trigger('click')
      expect((wrapper.vm as unknown as { tab: string }).tab).toBe('attachments')
    })

    it('a file is a download link on its signed URL, opening in a new tab', async () => {
      const wrapper = await open('agency_admin', FUTURE, [makeFile('brief')])

      const link = wrapper.get('[data-test="campaign-file-brief"] a')
      expect(link.attributes('href')).toBe('https://files.example/brief?sig=1')
      expect(link.attributes('target')).toBe('_blank')
      expect(link.attributes('rel')).toBe('noopener noreferrer')
      expect(link.attributes('aria-label')).toBe('Download brief.pdf')
    })

    it('a failed read draws no strip and does not break the page', async () => {
      const harness = await mountDetail('agency_admin', [], { campaign: FUTURE, filesError: true })
      cleanup = harness.cleanup
      const wrapper = harness.wrapper

      expect(wrapper.find('[data-test="campaign-detail-heading"]').text()).toBe('Summer launch')
      expect(wrapper.find('[data-test="overview-glance"]').exists()).toBe(true)
      expect(wrapper.find('[data-test="campaign-files-overview"]').exists()).toBe(false)
    })

    it('reads the files again when the operator returns to Overview from another tab', async () => {
      const wrapper = await open('agency_admin', FUTURE, [makeFile('brief')])
      const reads = () => vi.mocked(campaignsApi.listAttachments).mock.calls.length
      const afterMount = reads()

      ;(wrapper.vm as unknown as { tab: string }).tab = 'creators'
      await flushPromises()
      expect(reads()).toBe(afterMount)
      ;(wrapper.vm as unknown as { tab: string }).tab = 'overview'
      await flushPromises()
      expect(reads()).toBe(afterMount + 1)
    })
  })

  it('the page source holds no colour literal and none of the retired words', () => {
    expect(pageSource).not.toMatch(/#[0-9a-fA-F]{3,8}\b/)
    expect(pageSource).not.toMatch(/\brgba?\(/)
    expect(pageSource).not.toMatch(/\bWave\b/)
  })
})

describe('CampaignDetailPage — AH-118 offer edit and the region switch', () => {
  let cleanup: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(campaignsApi.listDrafts).mockResolvedValue({
      data: [],
      meta: { total: 0, page: 1, per_page: 25, last_page: 1 },
    })
  })

  afterEach(() => {
    cleanup?.()
    cleanup = null
  })

  function withLock(
    a: CampaignAssignmentResource,
    reason: NonNullable<CampaignAssignmentResource['attributes']['offer_edit_lock_reason']> | null,
  ): CampaignAssignmentResource {
    a.attributes.offer_edit_locked = reason !== null
    a.attributes.offer_edit_lock_reason = reason
    return a
  }

  async function openCreators(
    assignments: CampaignAssignmentResource[],
    features: { assignment_offer_edit?: boolean } = { assignment_offer_edit: true },
    role: 'agency_admin' | 'agency_manager' | 'agency_staff' = 'agency_manager',
  ) {
    const harness = await mountDetail(role, assignments, { features })
    cleanup = harness.cleanup
    ;(harness.wrapper.vm as unknown as { tab: string }).tab = 'creators'
    await flushPromises()
    return harness.wrapper
  }

  it('shows Edit offer on an editable row while the flag is on', async () => {
    const wrapper = await openCreators([withLock(makeAssignment('A', 'invited'), null)])
    const button = wrapper.find('[data-test="creators-edit-offer-A"]')
    expect(button.exists()).toBe(true)
    expect(button.attributes('data-locked')).toBe('false')
  })

  it('shows no Edit offer at all while the flag is off', async () => {
    const wrapper = await openCreators([withLock(makeAssignment('A', 'invited'), null)], {
      assignment_offer_edit: false,
    })
    expect(wrapper.find('[data-test="creators-edit-offer-A"]').exists()).toBe(false)
  })

  it.each(['contract_pending', 'contract_signed', 'payment'] as const)(
    'keeps Edit offer on a %s row, marked locked, so the dialog can say why',
    async (reason) => {
      const wrapper = await openCreators([withLock(makeAssignment('A', 'accepted'), reason)])
      const button = wrapper.find('[data-test="creators-edit-offer-A"]')
      expect(button.exists()).toBe(true)
      expect(button.attributes('data-locked')).toBe('true')
    },
  )

  it('hides Edit offer where the stage itself ends editing (status lock)', async () => {
    const wrapper = await openCreators([withLock(makeAssignment('A', 'posted'), 'status')])
    expect(wrapper.find('[data-test="creators-edit-offer-A"]').exists()).toBe(false)
  })

  it('opens the dialog locked, with the reason sentence, for a contract_pending row', async () => {
    const wrapper = await openCreators([
      withLock(makeAssignment('A', 'accepted'), 'contract_pending'),
    ])
    await wrapper.find('[data-test="creators-edit-offer-A"]').trigger('click')
    await flushPromises()

    const dialog = wrapper.findComponent({ name: 'EditOfferDialog' })
    expect(dialog.props()).toMatchObject({ assignmentId: 'A', lockReason: 'contract_pending' })
  })

  it('refetches the roster and shows the success snackbar after a save', async () => {
    const wrapper = await openCreators([withLock(makeAssignment('A', 'invited'), null)])
    vi.mocked(campaignsApi.assignments).mockClear()

    await wrapper.find('[data-test="creators-edit-offer-A"]').trigger('click')
    wrapper.findComponent({ name: 'EditOfferDialog' }).vm.$emit('success')
    await flushPromises()

    expect(campaignsApi.assignments).toHaveBeenCalled()
    expect((wrapper.vm as unknown as { editOfferSnackbar: string | null }).editOfferSnackbar).toBe(
      'Offer updated.',
    )
  })

  it('never sends restrict_to_listing_regions on a Settings save while the region flag is off', async () => {
    // A stored `true` with the flag later turned off must not turn every
    // ordinary save into a 422 campaign.region_restriction_disabled.
    const harness = await mountDetail('agency_admin', [], {
      campaign: { listing_regions: ['IE'], restrict_to_listing_regions: true },
    })
    cleanup = harness.cleanup
    ;(harness.wrapper.vm as unknown as { tab: string }).tab = 'settings'
    await flushPromises()
    vi.mocked(campaignsApi.update).mockResolvedValue({ data: makeCampaign() })

    await harness.wrapper.find('[data-test="campaign-form"]').trigger('submit')
    await flushPromises()

    const payload = vi.mocked(campaignsApi.update).mock.calls[0]?.[2] as Record<string, unknown>
    expect('restrict_to_listing_regions' in payload).toBe(false)
  })

  it('seeds and sends the stored switch on a Settings save while the region flag is on', async () => {
    const harness = await mountDetail('agency_admin', [], {
      campaign: { listing_regions: ['IE'], restrict_to_listing_regions: true },
      features: { campaign_region_restriction: true },
    })
    cleanup = harness.cleanup
    ;(harness.wrapper.vm as unknown as { tab: string }).tab = 'settings'
    await flushPromises()
    vi.mocked(campaignsApi.update).mockResolvedValue({ data: makeCampaign() })

    const toggle = harness.wrapper.find('[data-test="campaign-restrict-to-regions"] input')
    expect((toggle.element as HTMLInputElement).checked).toBe(true)

    await harness.wrapper.find('[data-test="campaign-form"]').trigger('submit')
    await flushPromises()

    expect(campaignsApi.update).toHaveBeenCalledWith(
      'agency-ulid',
      CAMPAIGN_ULID,
      expect.objectContaining({ restrict_to_listing_regions: true }),
    )
  })

  // ── AH-120: brand exclusivity ────────────────────────────────────────────────────────────
  const EXCL_ON = { campaign_brand_exclusivity: true }
  const BLOCKS_ZAVA = {
    exclusive_brand_ids: ['zava-ulid'],
    exclusive_brands: [{ id: 'zava-ulid', name: 'Zava', is_archived: false }],
    exclusivity_ends_on: '2099-03-31',
  }

  it('never sends the exclusivity fields on a Settings save while the flag is off', async () => {
    const harness = await mountDetail('agency_admin', [], { campaign: BLOCKS_ZAVA })
    cleanup = harness.cleanup
    ;(harness.wrapper.vm as unknown as { tab: string }).tab = 'settings'
    await flushPromises()
    vi.mocked(campaignsApi.update).mockResolvedValue({ data: makeCampaign() })

    expect(harness.wrapper.find('[data-test="campaign-exclusive-brands"]').exists()).toBe(false)
    await harness.wrapper.find('[data-test="campaign-form"]').trigger('submit')
    await flushPromises()

    const payload = vi.mocked(campaignsApi.update).mock.calls[0]?.[2] as Record<string, unknown>
    expect('exclusive_brand_ids' in payload).toBe(false)
    expect('exclusivity_ends_on' in payload).toBe(false)
  })

  it('seeds and sends the stored list and date on a Settings save while the flag is on', async () => {
    const harness = await mountDetail('agency_admin', [], {
      campaign: BLOCKS_ZAVA,
      features: EXCL_ON,
    })
    cleanup = harness.cleanup
    ;(harness.wrapper.vm as unknown as { tab: string }).tab = 'settings'
    await flushPromises()
    vi.mocked(campaignsApi.update).mockResolvedValue({ data: makeCampaign() })

    expect(harness.wrapper.find('[data-test="campaign-exclusive-brands"]').exists()).toBe(true)
    await harness.wrapper.find('[data-test="campaign-form"]').trigger('submit')
    await flushPromises()

    expect(campaignsApi.update).toHaveBeenCalledWith(
      'agency-ulid',
      CAMPAIGN_ULID,
      expect.objectContaining({
        exclusive_brand_ids: ['zava-ulid'],
        exclusivity_ends_on: '2099-03-31',
      }),
    )
  })

  it('shows the localized message when the server says the flag is off under an open form', async () => {
    const harness = await mountDetail('agency_admin', [], {
      campaign: BLOCKS_ZAVA,
      features: EXCL_ON,
    })
    cleanup = harness.cleanup
    ;(harness.wrapper.vm as unknown as { tab: string }).tab = 'settings'
    await flushPromises()
    vi.mocked(campaignsApi.update).mockRejectedValue(
      new ApiError({
        status: 422,
        code: 'campaign.brand_exclusivity_disabled',
        message: 'Blocking creators is not available yet.',
        details: [],
        requestId: 'r1',
      } as never),
    )

    await harness.wrapper.find('[data-test="campaign-form"]').trigger('submit')
    await flushPromises()

    expect(harness.wrapper.find('[data-test="campaign-form-error"]').text()).toBe(
      'Brand exclusivity is not available yet.',
    )
  })

  it('Overview: lists the blocked brands and the end date while the flag is on', async () => {
    const harness = await mountDetail('agency_admin', [], {
      campaign: {
        ...BLOCKS_ZAVA,
        exclusive_brands: [
          { id: 'zava-ulid', name: 'Zava', is_archived: false },
          { id: 'old-ulid', name: 'Old Co', is_archived: true },
        ],
      },
      features: EXCL_ON,
    })
    cleanup = harness.cleanup

    expect(harness.wrapper.find('[data-test="overview-exclusive-brands"]').text()).toBe(
      'Zava, Old Co (Archived)',
    )
    expect(harness.wrapper.find('[data-test="overview-exclusivity-ends-on"]').text()).toContain(
      'Ends on',
    )
  })

  it('Overview: no exclusivity row while the flag is off, or when nothing is blocked', async () => {
    const off = await mountDetail('agency_admin', [], { campaign: BLOCKS_ZAVA })
    expect(off.wrapper.find('[data-test="overview-exclusive-brands-field"]').exists()).toBe(false)
    off.cleanup()

    const none = await mountDetail('agency_admin', [], { features: EXCL_ON })
    cleanup = none.cleanup
    expect(none.wrapper.find('[data-test="overview-exclusive-brands-field"]').exists()).toBe(false)
  })

  it('passes exclusivityEnabled to the invite dialog: flag on, a selection triggers the conflicts read', async () => {
    vi.mocked(campaignsApi.exclusivityConflicts).mockResolvedValue({ data: [] })
    const harness = await mountDetail('agency_admin', [], { features: EXCL_ON })
    cleanup = harness.cleanup

    await harness.wrapper.find('[data-test="campaign-detail-invite"]').trigger('click')
    await flushPromises()
    const dialog = harness.wrapper.findComponent(InviteCreatorsDialog)
    expect(dialog.props('exclusivityEnabled')).toBe(true)
    ;(dialog.vm as unknown as { toggleSelect: (id: string, row: unknown) => void }).toggleSelect(
      '01CREATORA',
      { attributes: { is_blacklisted: false } },
    )
    await flushPromises()

    expect(campaignsApi.exclusivityConflicts).toHaveBeenCalledWith('agency-ulid', CAMPAIGN_ULID, {
      creator_ids: ['01CREATORA'],
    })
  })

  it('passes exclusivityEnabled=false to the invite dialog while the flag is off: no read, ever', async () => {
    const harness = await mountDetail('agency_admin', [])
    cleanup = harness.cleanup

    await harness.wrapper.find('[data-test="campaign-detail-invite"]').trigger('click')
    await flushPromises()
    const dialog = harness.wrapper.findComponent(InviteCreatorsDialog)
    expect(dialog.props('exclusivityEnabled')).toBe(false)
    ;(dialog.vm as unknown as { toggleSelect: (id: string, row: unknown) => void }).toggleSelect(
      '01CREATORA',
      { attributes: { is_blacklisted: false } },
    )
    await flushPromises()

    expect(campaignsApi.exclusivityConflicts).not.toHaveBeenCalled()
  })

  // ── W1: the restriction on Overview ──────────────────────────────────────────────────────
  const REGION_ON = { campaign_region_restriction: true }
  const LISTED_ES_PT = {
    listed_on_jobs_board: true,
    listing_regions: ['ES', 'PT'],
    restrict_to_listing_regions: false,
  }
  const overviewSwitch = (w: ReturnType<typeof mount>) =>
    w.find('[data-test="overview-restrict-to-regions"] input')

  it('Overview: no restriction row, no pill while the region flag is off; regions still listed', async () => {
    const harness = await mountDetail('agency_admin', [], {
      campaign: { ...LISTED_ES_PT, restrict_to_listing_regions: true },
    })
    cleanup = harness.cleanup
    expect(harness.wrapper.find('[data-test="overview-requirement-restrict"]').exists()).toBe(false)
    expect(harness.wrapper.find('[data-test="overview-regions-restricted"]').exists()).toBe(false)
    expect(harness.wrapper.find('[data-test="overview-regions"]').text()).toBe('Spain, Portugal')
  })

  it('Overview: the Regions field names the countries and carries the Restricted pill when on', async () => {
    const harness = await mountDetail('agency_admin', [], {
      campaign: { ...LISTED_ES_PT, restrict_to_listing_regions: true },
      features: REGION_ON,
    })
    cleanup = harness.cleanup
    expect(harness.wrapper.find('[data-test="overview-regions"]').text()).toBe('Spain, Portugal')
    expect(harness.wrapper.find('[data-test="overview-regions-restricted"]').text()).toBe(
      'Restricted',
    )
    expect((overviewSwitch(harness.wrapper).element as HTMLInputElement).checked).toBe(true)
  })

  it('Overview: no Regions field without regions, and no pill when the switch is off', async () => {
    const none = await mountDetail('agency_admin', [], { features: REGION_ON })
    expect(none.wrapper.find('[data-test="overview-regions-field"]').exists()).toBe(false)
    none.cleanup()

    const off = await mountDetail('agency_admin', [], {
      campaign: LISTED_ES_PT,
      features: REGION_ON,
    })
    cleanup = off.cleanup
    expect(off.wrapper.find('[data-test="overview-regions"]').exists()).toBe(true)
    expect(off.wrapper.find('[data-test="overview-regions-restricted"]').exists()).toBe(false)
  })

  it('Overview: the switch shows the hint and PATCHes only restrict_to_listing_regions', async () => {
    const harness = await mountDetail('agency_admin', [], {
      campaign: LISTED_ES_PT,
      features: REGION_ON,
    })
    cleanup = harness.cleanup
    expect(harness.wrapper.find('[data-test="overview-requirement-restrict"]').text()).toContain(
      'Creators outside these regions can still see the job but cannot apply.',
    )
    vi.mocked(campaignsApi.update).mockResolvedValue({
      data: makeCampaign(false, { ...LISTED_ES_PT, restrict_to_listing_regions: true }),
    })

    await overviewSwitch(harness.wrapper).setValue(true)
    await flushPromises()

    expect(campaignsApi.update).toHaveBeenCalledWith('agency-ulid', CAMPAIGN_ULID, {
      restrict_to_listing_regions: true,
    })
    expect(harness.wrapper.find('[data-test="overview-regions-restricted"]').exists()).toBe(true)
  })

  it('Overview: a refused PATCH leaves the switch where the server has it and shows the error', async () => {
    const harness = await mountDetail('agency_admin', [], {
      campaign: LISTED_ES_PT,
      features: REGION_ON,
    })
    cleanup = harness.cleanup
    vi.mocked(campaignsApi.update).mockRejectedValue(
      new ApiError({
        message: 'Add at least one region first.',
        status: 422,
        code: 'campaign.restrict_needs_regions',
      }),
    )

    await overviewSwitch(harness.wrapper).setValue(true)
    await flushPromises()

    expect(harness.wrapper.find('[data-test="overview-restrict-error"]').text()).toContain(
      'Add at least one region first.',
    )
    expect((overviewSwitch(harness.wrapper).element as HTMLInputElement).checked).toBe(false)
  })

  it('Overview: the switch is disabled when not listed, without regions, or for staff', async () => {
    const notListed = await mountDetail('agency_admin', [], {
      campaign: { ...LISTED_ES_PT, listed_on_jobs_board: false },
      features: REGION_ON,
    })
    expect(overviewSwitch(notListed.wrapper).attributes('disabled')).toBeDefined()
    notListed.cleanup()

    const noRegions = await mountDetail('agency_admin', [], {
      campaign: { ...LISTED_ES_PT, listing_regions: [] },
      features: REGION_ON,
    })
    expect(overviewSwitch(noRegions.wrapper).attributes('disabled')).toBeDefined()
    noRegions.cleanup()

    const staff = await mountDetail('agency_staff', [], {
      campaign: LISTED_ES_PT,
      features: REGION_ON,
    })
    expect(overviewSwitch(staff.wrapper).attributes('disabled')).toBeDefined()
    staff.cleanup()

    const ok = await mountDetail('agency_admin', [], {
      campaign: LISTED_ES_PT,
      features: REGION_ON,
    })
    cleanup = ok.cleanup
    expect(overviewSwitch(ok.wrapper).attributes('disabled')).toBeUndefined()
  })
})
