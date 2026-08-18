/**
 * BoardCardDrawer (Sprint 12 Chunk 2, D-9). Pins: the on-open load of the
 * assignment detail + movements in parallel, the detail face, and the null-safe movement
 * history — a since-deleted column id renders "(removed)", and an empty feed
 * shows the empty note.
 */

import type { BoardCardMovementResource, BoardCardResource } from '@catalyst/api-client'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enApp from '@/core/i18n/locales/en/app.json'

vi.mock('../api/board.api', () => ({
  boardApi: { show: vi.fn(), movements: vi.fn() },
}))
vi.mock('@/modules/campaigns/api/campaigns.api', () => ({
  campaignsApi: {
    showAssignment: vi.fn(),
    approveDraft: vi.fn(),
    requestRevision: vi.fn(),
    rejectDraft: vi.fn(),
  },
}))
// The Messages tab (campaign-messages chunk) builds an agency chat transport;
// stub the factory so the ChatPanel stub receives a truthy transport without
// touching the network.
vi.mock('@/modules/messaging/api/messaging.api', () => ({
  agencyChatTransport: vi.fn(() => ({ __transport: true })),
}))

// Stub ChatPanel — its own thread-fetch/poll is out of scope for the drawer's
// wiring test; we only assert it mounts with a transport.
const ChatPanelStub = {
  name: 'ChatPanel',
  props: ['transport', 'title'],
  template: '<div data-test="chat-panel-stub" />',
}

// AH-080 (D2b) — CreatorProfileContent's own full/thin/§5.34 rendering is
// CreatorProfileContent.spec.ts's job. Here we only pin the drawer's OWN
// contract: lazy mount timing + prop wiring, so it never fires an unmocked
// network call in this suite.
const CreatorProfileContentStub = {
  name: 'CreatorProfileContent',
  props: ['agencyId', 'creatorUlid', 'assumeFull'],
  template: '<div data-test="creator-profile-content-stub" />',
}

import { campaignsApi } from '@/modules/campaigns/api/campaigns.api'
import { boardApi } from '../api/board.api'
import { useBoardStore } from '../stores/useBoardStore'
import BoardCardDrawer from './BoardCardDrawer.vue'

const mockBoard = vi.mocked(boardApi)
const mockCampaigns = vi.mocked(campaignsApi)

const VDialogStub = {
  name: 'VDialog',
  props: ['modelValue'],
  template: '<div class="vdialog-stub"><slot /></div>',
}

// The Drafts tab now mounts the shared DraftReviewPanel, which renders the
// real PortfolioGallery — stub it out (the ReviewDraftDrawer.spec.ts
// convention), since the gallery's own lightbox behavior is out of scope here.
const PortfolioGalleryStub = {
  name: 'PortfolioGallery',
  props: ['items'],
  template: '<div class="portfolio-stub" />',
}

function card(
  assignmentId: string | null,
  overrides: Partial<NonNullable<BoardCardResource['relationships']['assignment']['data']>> = {},
): BoardCardResource {
  return {
    id: 'k1',
    type: 'board_cards',
    attributes: { position: 0, created_at: 'x', updated_at: 'x' },
    relationships: {
      column: { data: { id: 'c1', type: 'board_columns' } },
      assignment: {
        data:
          assignmentId === null
            ? null
            : {
                id: assignmentId,
                type: 'campaign_assignments',
                status: 'posted',
                deliverables: null,
                posting_due_at: null,
                creator: { id: 'cr1', display_name: 'Jane Q' },
                ...overrides,
              },
      },
    },
  }
}

function movement(
  id: string,
  attrs: Partial<BoardCardMovementResource['attributes']> = {},
): BoardCardMovementResource {
  return {
    id,
    type: 'board_card_movements',
    attributes: {
      from_column_id: 'c1',
      to_column_id: 'c2',
      triggered_by: 'user',
      triggered_event_key: null,
      reason: null,
      created_at: '2026-06-01T00:00:00+00:00',
      ...attrs,
    },
  }
}

async function seedStore() {
  mockBoard.show.mockResolvedValue({
    data: {
      id: 'board-1',
      type: 'boards',
      attributes: { created_at: 'x', updated_at: 'x' },
      relationships: { campaign: { data: { id: 'campaign-ulid', type: 'campaigns' } } },
      columns: [
        {
          id: 'c1',
          type: 'board_columns',
          attributes: {
            name: 'Todo',
            position: 1,
            color_token: 'status-todefine',
            is_terminal_success: false,
            is_terminal_failure: false,
            card_count: null,
            created_at: 'x',
            updated_at: 'x',
          },
        },
        {
          id: 'c2',
          type: 'board_columns',
          attributes: {
            name: 'Done',
            position: 2,
            color_token: 'status-paid',
            is_terminal_success: true,
            is_terminal_failure: false,
            card_count: null,
            created_at: 'x',
            updated_at: 'x',
          },
        },
      ],
      automations: [],
      cards: [],
    },
  } as never)
  const store = useBoardStore()
  await store.load('agency-ulid', 'campaign-ulid')
}

/** A posted-content row for the detail's `posted_content` relationship. */
function postedRow(verificationStatus: string, id = 'p1') {
  return {
    id,
    type: 'campaign_posted_content',
    attributes: {
      platform: 'instagram',
      post_url: 'https://instagram.com/p/abc',
      platform_post_id: null,
      posted_at: '2026-06-05T00:00:00+00:00',
      verified_at: null,
      verification_status: verificationStatus,
    },
  }
}

async function mountDrawer(
  c: BoardCardResource,
  movements: BoardCardMovementResource[],
  opts: {
    canResolve?: boolean
    canReview?: boolean
    status?: string
    offerDescription?: string | null
    postedContent?: ReturnType<typeof postedRow>[]
    /** Newest-first, as the endpoint returns them. Drives the latest-draft row. */
    drafts?: Array<{
      version: number
      review_status: string
      caption: string | null
      review_feedback?: string | null
      submitted_at?: string | null
    }>
  } = {},
) {
  setActivePinia(createPinia())
  await seedStore()
  mockBoard.movements.mockResolvedValue({ data: movements })
  mockCampaigns.showAssignment.mockResolvedValue({
    data: {
      id: 'a1',
      type: 'campaign_assignment',
      attributes: {
        status: opts.status ?? 'posted',
        agreed_fee_minor_units: 20000,
        agreed_fee_currency: 'EUR',
        fee_per: 'script',
        offer_description: opts.offerDescription ?? 'Two hooks, one CTA.',
        offer_attachment: {
          name: 'brief.pdf',
          mime_type: 'application/pdf',
          size_bytes: 2048,
          url: 'https://cdn/brief.pdf',
        },
        invited_at: '2026-06-01T00:00:00+00:00',
        posting_due_at: null,
        submitted_draft_at: '2026-06-03T00:00:00+00:00',
        approved_at: null,
        posted_at: null,
        verified_live_at: null,
        creator: { id: 'cr1', display_name: 'Jane Q' },
        campaign: { id: 'cmp1', name: 'Summer Push', brand_name: 'Acme' },
      },
      relationships: {
        drafts: (opts.drafts ?? []).map((d) => ({
          id: `draft-${d.version}`,
          type: 'campaign_draft',
          attributes: {
            version: d.version,
            submitted_at: d.submitted_at ?? '2026-06-03T00:00:00+00:00',
            caption: d.caption,
            hashtags: null,
            mentions: null,
            media: [],
            links: null,
            review_status: d.review_status,
            reviewed_at: null,
            review_feedback: d.review_feedback ?? null,
          },
        })),
        posted_content: opts.postedContent ?? [],
      },
    },
  } as never)

  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en: enApp } as never })
  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })
  const wrapper = mount(BoardCardDrawer, {
    props: {
      modelValue: true,
      agencyId: 'agency-ulid',
      campaignId: 'campaign-ulid',
      card: c,
      canResolve: opts.canResolve ?? false,
      canReview: opts.canReview ?? false,
    },
    global: {
      plugins: [i18n, vuetify],
      stubs: {
        VDialog: VDialogStub,
        ChatPanel: ChatPanelStub,
        PortfolioGallery: PortfolioGalleryStub,
        CreatorProfileContent: CreatorProfileContentStub,
      },
    },
    attachTo: document.createElement('div'),
  })
  await flushPromises()
  return wrapper
}

describe('BoardCardDrawer', () => {
  beforeEach(() => vi.clearAllMocks())
  afterEach(() => vi.restoreAllMocks())

  it('fetches the assignment detail + movements on open', async () => {
    const wrapper = await mountDrawer(card('a1'), [movement('1')])
    expect(mockCampaigns.showAssignment).toHaveBeenCalledWith('agency-ulid', 'campaign-ulid', 'a1')
    expect(mockBoard.movements).toHaveBeenCalledWith('agency-ulid', 'campaign-ulid', 'k1')
    expect(wrapper.find('[data-test="board-card-drawer-detail"]').text()).toContain('Jane Q')
    wrapper.unmount()
  })

  it('renders movement rows with resolved column names', async () => {
    const wrapper = await mountDrawer(card('a1'), [
      movement('1', { from_column_id: 'c1', to_column_id: 'c2' }),
    ])
    const row = wrapper.find('[data-test="board-card-movement-1"]')
    expect(row.text()).toContain('Todo')
    expect(row.text()).toContain('Done')
    wrapper.unmount()
  })

  it('renders "(removed)" for a since-deleted column id (null-safe)', async () => {
    const wrapper = await mountDrawer(card('a1'), [
      movement('1', { from_column_id: 'gone', to_column_id: null }),
    ])
    const row = wrapper.find('[data-test="board-card-movement-1"]')
    expect(row.text()).toContain('(removed)')
    wrapper.unmount()
  })

  it('shows the empty note when there are no movements', async () => {
    const wrapper = await mountDrawer(card('a1'), [])
    expect(wrapper.find('[data-test="board-card-drawer-history-empty"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('does not call showAssignment for a removed (null assignment) card, still loads movements', async () => {
    const wrapper = await mountDrawer(card(null), [movement('1')])
    expect(mockCampaigns.showAssignment).not.toHaveBeenCalled()
    expect(mockBoard.movements).toHaveBeenCalled()
    wrapper.unmount()
  })

  it('shows the Declined history tag for a re-offered (previously_declined) assignment', async () => {
    const wrapper = await mountDrawer(
      card('a1', { status: 'invited', previously_declined: true }),
      [],
    )
    expect(wrapper.find('[data-test="board-card-drawer-declined-history"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('hides the Declined history tag for a plain assignment', async () => {
    const wrapper = await mountDrawer(card('a1', { status: 'invited' }), [])
    expect(wrapper.find('[data-test="board-card-drawer-declined-history"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('mounts the Messages tab (default) with the per-assignment chat transport', async () => {
    const wrapper = await mountDrawer(card('a1'), [])
    expect(wrapper.find('[data-test="board-card-drawer-tab-messages"]').exists()).toBe(true)
    // The ChatPanel is mounted with a truthy transport for a real assignment.
    const panel = wrapper.findComponent(ChatPanelStub)
    expect(panel.exists()).toBe(true)
    expect(panel.props('transport')).toBeTruthy()
    // No "no conversation" fallback for a card with an assignment.
    expect(wrapper.find('[data-test="board-card-drawer-messages-none"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('shows the "no conversation" note (no ChatPanel) for a removed (null) assignment', async () => {
    const wrapper = await mountDrawer(card(null), [])
    expect(wrapper.find('[data-test="board-card-drawer-messages-none"]').exists()).toBe(true)
    expect(wrapper.findComponent(ChatPanelStub).exists()).toBe(false)
    wrapper.unmount()
  })

  // ── Detail-tab facelift ────────────────────────────────────────────────────

  it('renders the identity header with campaign · brand and the offer terms', async () => {
    const wrapper = await mountDrawer(card('a1'), [])
    expect(wrapper.find('[data-test="board-card-drawer-campaign"]').text()).toBe(
      'Summer Push · Acme',
    )
    const fee = wrapper.find('[data-test="board-card-drawer-fee"]')
    expect(fee.text()).toContain('200')
    expect(fee.text()).toContain('script')
    expect(wrapper.find('[data-test="board-card-drawer-offer-description"]').text()).toBe(
      'Two hooks, one CTA.',
    )
    const attachment = wrapper.find('[data-test="board-card-drawer-attachment"]')
    expect(attachment.text()).toContain('brief.pdf')
    expect(attachment.attributes('href')).toBe('https://cdn/brief.pdf')
    wrapper.unmount()
  })

  it('renders the offer description through RichBrief — markdown becomes real HTML (AH-081)', async () => {
    const wrapper = await mountDrawer(card('a1'), [], {
      offerDescription: 'Please shoot **outdoors**, in *natural* light.',
    })
    const description = wrapper.find('[data-test="board-card-drawer-offer-description"]')
    expect(description.find('strong').text()).toBe('outdoors')
    expect(description.find('em').text()).toBe('natural')
    wrapper.unmount()
  })

  it('renders the progress timeline with reached steps checked and dates formatted', async () => {
    const wrapper = await mountDrawer(card('a1'), [])
    const timeline = wrapper.find('[data-test="board-card-drawer-timeline"]')
    expect(timeline.exists()).toBe(true)
    // Reached steps carry a formatted date; unreached fall back to the em dash.
    expect(wrapper.find('[data-test="board-card-drawer-step-invited"]').text()).toContain('2026')
    expect(wrapper.find('[data-test="board-card-drawer-step-draft_submitted"]').text()).toContain(
      '2026',
    )
    expect(wrapper.find('[data-test="board-card-drawer-step-approved"]').text()).toContain('—')
    expect(wrapper.find('[data-test="board-card-drawer-step-live_verified"]').text()).toContain('—')
    wrapper.unmount()
  })

  it('renders deliverable chips from the card-face data', async () => {
    const wrapper = await mountDrawer(card('a1', { deliverables: ['1 Reel', '3 Stories'] }), [])
    const chips = wrapper.find('[data-test="board-card-drawer-deliverables"]')
    expect(chips.exists()).toBe(true)
    expect(chips.text()).toContain('1 Reel')
    expect(chips.text()).toContain('3 Stories')
    wrapper.unmount()
  })

  // ── Live-verified row Resolve hand-off (AH-045) ────────────────────────────

  it('offers Resolve on the Live-verified row for a posted assignment whose LATEST verification failed, and emits the stub', async () => {
    const wrapper = await mountDrawer(card('a1'), [], {
      canResolve: true,
      // Newest-first (D-7 ordering): [0] failed, the older row verified.
      postedContent: [postedRow('not_found', 'p2'), postedRow('verified', 'p1')],
    })

    const btn = wrapper.find('[data-test="board-card-drawer-resolve"]')
    expect(btn.exists()).toBe(true)
    // It sits inside the Live-verified timeline row.
    expect(
      wrapper
        .find('[data-test="board-card-drawer-step-live_verified"]')
        .find('[data-test="board-card-drawer-resolve"]')
        .exists(),
    ).toBe(true)

    await btn.trigger('click')
    const emitted = wrapper.emitted('resolve')?.[0]?.[0] as {
      id: string
      attributes: { status: string; verification_status: string | null }
    }
    expect(emitted.id).toBe('a1')
    expect(emitted.attributes.status).toBe('posted')
    expect(emitted.attributes.verification_status).toBe('not_found')
    wrapper.unmount()
  })

  it('hides Resolve when the latest verification did not fail', async () => {
    const wrapper = await mountDrawer(card('a1'), [], {
      canResolve: true,
      postedContent: [postedRow('verified')],
    })
    expect(wrapper.find('[data-test="board-card-drawer-resolve"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('hides Resolve without the canResolve ability even on a failed post', async () => {
    const wrapper = await mountDrawer(card('a1'), [], {
      canResolve: false,
      postedContent: [postedRow('mismatch')],
    })
    expect(wrapper.find('[data-test="board-card-drawer-resolve"]').exists()).toBe(false)
    wrapper.unmount()
  })

  // ── Draft-submitted row Review hand-off ───────────────────────────────────

  it('offers Review on the Draft-submitted row, switching to the Drafts tab in THIS drawer (no more leaving for the campaign page)', async () => {
    const wrapper = await mountDrawer(card('a1'), [], {
      canReview: true,
      status: 'draft_submitted',
      drafts: [{ version: 1, review_status: 'pending', caption: null }],
    })

    const btn = wrapper.find('[data-test="board-card-drawer-review"]')
    expect(btn.exists()).toBe(true)
    // It sits inside the Draft-submitted timeline row, not the Live-verified one.
    expect(
      wrapper
        .find('[data-test="board-card-drawer-step-draft_submitted"]')
        .find('[data-test="board-card-drawer-review"]')
        .exists(),
    ).toBe(true)

    await btn.trigger('click')
    await flushPromises()

    // Switches this drawer's OWN tab — no navigation hand-off, no write.
    expect((wrapper.vm as unknown as { tab: string }).tab).toBe('drafts')
    expect(wrapper.emitted('resolve')).toBeUndefined()
    // The full review surface (Approve, in this case) is right there.
    expect(wrapper.find('[data-test="review-approve"]').exists()).toBe(true)
    wrapper.unmount()
  })

  // The negative leg: Review belongs to `draft_submitted` and to nothing else.
  // Each status here is eligible in every respect except the one that matters.
  it.each(['invited', 'accepted', 'producing', 'approved', 'posted', 'rejected'])(
    'hides Review for a %s assignment even with the ability',
    async (status) => {
      const wrapper = await mountDrawer(card('a1'), [], { canReview: true, status })
      expect(wrapper.find('[data-test="board-card-drawer-review"]').exists()).toBe(false)
      wrapper.unmount()
    },
  )

  // ── The latest-draft row's round chip (AH-068, D2/D5) ────────────────────
  it('names the latest draft as one round string, not a version chip joined to a status chip', async () => {
    const wrapper = await mountDrawer(card('a1'), [], {
      status: 'draft_submitted',
      drafts: [{ version: 3, review_status: 'pending', caption: 'The third cut' }],
    })

    const row = wrapper.find('[data-test="board-card-drawer-draft"]')
    expect(row.text()).toContain('Draft 3 — awaiting review')
    // The retired form and the "·" join that concatenated it are both gone.
    expect(row.text()).not.toContain('Draft v3')
    expect(row.text()).not.toContain('·')
    // The caption still rides alongside the chip.
    expect(row.text()).toContain('The third cut')
    wrapper.unmount()
  })

  it('the latest-draft round follows the assignment, not the review status alone', async () => {
    const wrapper = await mountDrawer(card('a1'), [], {
      status: 'posted',
      drafts: [{ version: 2, review_status: 'approved', caption: null }],
    })

    expect(wrapper.find('[data-test="board-card-drawer-draft"]').text()).toContain(
      'Draft 2 — approved',
    )
    wrapper.unmount()
  })

  it('hides Review without the canReview ability even on a submitted draft', async () => {
    const wrapper = await mountDrawer(card('a1'), [], {
      canReview: false,
      status: 'draft_submitted',
    })
    expect(wrapper.find('[data-test="board-card-drawer-review"]').exists()).toBe(false)
    wrapper.unmount()
  })

  // ── Drafts tab (full review surface, eyes-on fix batch, 2026-08-17) ──────
  //
  // The Drafts tab now mounts the SAME `DraftReviewPanel` `ReviewDraftDrawer`
  // hosts, so its history rendering (bold round titles, contrast-safe
  // feedback text, the empty state) is that component's own responsibility —
  // pinned once, in `DraftReviewPanel.spec.ts`. What's pinned HERE is what's
  // specific to mounting it inside the board drawer: it gets the ability +
  // the already-fetched detail, and a successful action reloads the drawer
  // without closing it.

  it('shows the Draft history round cards inside the board drawer (rendered by the shared panel)', async () => {
    const wrapper = await mountDrawer(card('a1'), [], {
      status: 'draft_submitted',
      drafts: [
        { version: 2, review_status: 'pending', caption: null },
        {
          version: 1,
          review_status: 'revision_requested',
          caption: null,
          review_feedback: 'Fix the hook.',
        },
      ],
    })

    expect(wrapper.find('[data-test="review-history-1"]').text()).toContain('Fix the hook.')
    wrapper.unmount()
  })

  it('shows the empty note on the Drafts tab when the assignment has no drafts yet', async () => {
    const wrapper = await mountDrawer(card('a1'), [], { drafts: [] })
    expect(wrapper.find('[data-test="review-empty"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('approving from the board drawer calls approveDraft and reloads without closing the drawer', async () => {
    mockCampaigns.approveDraft.mockResolvedValue({
      data: { id: 'draft-1', type: 'campaign_draft' },
      meta: { code: 'assignment.draft_approved' },
    } as never)
    const wrapper = await mountDrawer(card('a1'), [], {
      canReview: true,
      status: 'draft_submitted',
      drafts: [{ version: 1, review_status: 'pending', caption: null }],
    })
    ;(wrapper.vm as unknown as { tab: string }).tab = 'drafts'
    await flushPromises()

    await wrapper.find('[data-test="review-approve"]').trigger('click')
    await flushPromises()

    expect(mockCampaigns.approveDraft).toHaveBeenCalledWith('agency-ulid', 'campaign-ulid', 'a1')
    // Reloads the shared detail (showAssignment called again) instead of closing.
    expect(mockCampaigns.showAssignment).toHaveBeenCalledTimes(2)
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
    wrapper.unmount()
  })

  it('without the canReview ability, the Drafts tab shows history but no action buttons', async () => {
    const wrapper = await mountDrawer(card('a1'), [], {
      canReview: false,
      status: 'draft_submitted',
      drafts: [{ version: 1, review_status: 'pending', caption: null }],
    })
    ;(wrapper.vm as unknown as { tab: string }).tab = 'drafts'
    await flushPromises()

    expect(wrapper.find('[data-test="review-draft-preview"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="review-approve"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="review-feedback"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('formats the movement timestamp instead of dumping the ISO string', async () => {
    const wrapper = await mountDrawer(card('a1'), [
      movement('1', { created_at: '2026-06-01T12:30:00+00:00' }),
    ])
    const row = wrapper.find('[data-test="board-card-movement-1"]')
    expect(row.text()).not.toContain('2026-06-01T12:30:00')
    expect(row.text()).toContain('2026')
    wrapper.unmount()
  })

  // ── Profile tab (AH-080, D2b) — the lazy, deliberately-non-eager fifth tab ──

  it('does NOT mount CreatorProfileContent on open — the drawer opens on Messages, Profile is untouched', async () => {
    const wrapper = await mountDrawer(card('a1'), [])
    expect(wrapper.find('[data-test="board-card-drawer-tab-profile"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="creator-profile-content-stub"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('mounts CreatorProfileContent only once the Profile tab is first activated, wired to the card creator + assumeFull:false', async () => {
    const wrapper = await mountDrawer(card('a1'), [])
    expect(wrapper.find('[data-test="creator-profile-content-stub"]').exists()).toBe(false)
    ;(wrapper.vm as unknown as { tab: string }).tab = 'profile'
    await flushPromises()

    const content = wrapper.findComponent(CreatorProfileContentStub)
    expect(content.exists()).toBe(true)
    expect(content.props()).toMatchObject({
      agencyId: 'agency-ulid',
      creatorUlid: 'cr1',
      assumeFull: false,
    })
    wrapper.unmount()
  })

  it('stays mounted after switching away — a re-visit never re-triggers the lazy activation (no second fetch)', async () => {
    const wrapper = await mountDrawer(card('a1'), [])
    const vm = wrapper.vm as unknown as { tab: string }

    vm.tab = 'profile'
    await flushPromises()
    expect(wrapper.find('[data-test="creator-profile-content-stub"]').exists()).toBe(true)

    vm.tab = 'messages'
    await flushPromises()
    vm.tab = 'profile'
    await flushPromises()

    // Still exactly one CreatorProfileContent instance — never unmounted, so
    // useCreatorProfile inside it never re-runs its load().
    expect(wrapper.findAllComponents(CreatorProfileContentStub)).toHaveLength(1)
    wrapper.unmount()
  })

  it('does not mount CreatorProfileContent for a removed (null-assignment) card even if Profile is selected', async () => {
    const wrapper = await mountDrawer(card(null), [])
    ;(wrapper.vm as unknown as { tab: string }).tab = 'profile'
    await flushPromises()

    expect(wrapper.find('[data-test="creator-profile-content-stub"]').exists()).toBe(false)
    wrapper.unmount()
  })
})
