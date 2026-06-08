/**
 * Sprint 8 Chunk 1 — Vitest coverage for the campaign detail page (the app's
 * first tabbed surface). Pins: the tab set renders, the Settings tab is
 * role-gated (admin/manager only), the Board/Drafts/Payments/Messages tabs are
 * empty-state "coming soon" (nothing half-built), and the Creators tab shows
 * its empty state when there are no assignments.
 */

import type {
  CampaignAssignmentResource,
  CampaignDraftListItemResource,
  CampaignResource,
} from '@catalyst/api-client'
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

import CampaignDetailPage from './CampaignDetailPage.vue'

vi.mock('../api/campaigns.api', () => ({
  campaignsApi: {
    show: vi.fn(),
    assignments: vi.fn(),
    listDrafts: vi.fn(),
    update: vi.fn(),
    reinvite: vi.fn(),
    showAssignment: vi.fn(),
    approveDraft: vi.fn(),
    requestRevision: vi.fn(),
    rejectDraft: vi.fn(),
    proceedWithoutContract: vi.fn(),
  },
}))

import { campaignsApi } from '../api/campaigns.api'

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

function makeCampaign(requiresContract = false): CampaignResource {
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
      is_marketplace_visible: false,
      published_at: null,
      completed_at: null,
      assignment_count: 0,
      created_at: '2026-06-01T10:00:00.000000Z',
      updated_at: '2026-06-01T10:00:00.000000Z',
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
  opts: { perCampaignContractEnabled?: boolean; requiresContract?: boolean } = {},
): Promise<{ wrapper: ReturnType<typeof mount>; cleanup: () => void }> {
  const pinia = createPinia()
  setActivePinia(pinia)

  vi.mocked(campaignsApi.show).mockResolvedValue({
    data: makeCampaign(opts.requiresContract ?? false),
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

  const agency = useAgencyStore()
  agency.initFromUser([{ agency_id: 'agency-ulid', agency_name: 'Test Agency', role }])

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/campaigns', name: 'campaigns.list', component: { template: '<div />' } },
      { path: '/campaigns/:ulid', name: 'campaigns.detail', component: { template: '<div />' } },
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
    global: { plugins: [pinia, router, i18n, vuetify] },
    attachTo: document.createElement('div'),
  })
  await flushPromises()

  return {
    wrapper,
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
    expect(harness.wrapper.find('[data-test="tab-payments"]').exists()).toBe(true)
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

  it('shows both fees + the re-invite action on a countered row', async () => {
    const wrapper = await openCreatorsTab('agency_staff', [makeAssignment('C', 'countered')])
    const fees = wrapper.find('[data-test="creators-fees-C"]')
    expect(fees.text()).toContain('Offered')
    expect(fees.text()).toContain('Countered')
    expect(fees.text()).toContain('1,000.00 EUR')
    expect(fees.text()).toContain('1,500.00 EUR')
    expect(wrapper.find('[data-test="creators-reinvite-C"]').exists()).toBe(true)
  })

  it('shows agreed fee only and NO re-invite action on a non-countered row', async () => {
    const wrapper = await openCreatorsTab('agency_staff', [makeAssignment('I', 'invited')])
    const fees = wrapper.find('[data-test="creators-fees-I"]')
    expect(fees.text()).toContain('1,000.00 EUR')
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
