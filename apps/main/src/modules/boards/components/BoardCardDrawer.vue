<script setup lang="ts">
/**
 * Card drawer (Sprint 12 Chunk 2, D-9). A WIDE v-dialog (the ReviewDraftDrawer
 * pattern — no v-navigation-drawer in this app) opened by clicking a card.
 * Three tabs:
 *
 *   - Messages (DEFAULT, first): the per-assignment chat with this creator,
 *     mounted from the shared `ChatPanel` on the agency `agencyChatTransport`
 *     (the same thread the campaign Messages tab uses) so the agency can chat
 *     straight from the board. Mounted only while the drawer is OPEN (`v-if`),
 *     so the thread poll stops on close (the ChatDialog pattern). A card whose
 *     assignment failed to load has no thread → a "no conversation" note.
 *   - Detail (board-drawer detail facelift): an identity header (avatar + name
 *     + status + campaign · brand), the invite-offer terms (fee / per /
 *     description / attachment), deliverable chips, a five-step progress
 *     timeline off the milestone timestamps, and the latest draft + posted
 *     link. Fetched via `campaignsApi.showAssignment` (the same agency-side
 *     detail the review drawer consumes); identity/offer basics fall back to
 *     the card-face data. Null-safe.
 *   - Movement history: `boardApi.movements` (newest-first). Column ids resolve
 *     to names via the store; a since-deleted column renders "(removed)" rather
 *     than a dangling id (§14.3, null-safe).
 *
 * This is a READ surface for Detail + History — no manual-move reason control
 * here (Q2 tech-debt note); Messages is the one interactive tab.
 */

import { ApiError, formatCurrency, formatDate, formatDateTime } from '@catalyst/api-client'
import type {
  AgencyAssignmentDetailResource,
  BoardCardMovementResource,
  BoardCardResource,
  CampaignAssignmentResource,
} from '@catalyst/api-client'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { campaignsApi } from '@/modules/campaigns/api/campaigns.api'
import { agencyChatTransport, type ChatTransport } from '@/modules/messaging/api/messaging.api'
import ChatPanel from '@/modules/messaging/components/ChatPanel.vue'

import { boardApi } from '../api/board.api'
import { useBoardStore } from '../stores/useBoardStore'

const props = defineProps<{
  modelValue: boolean
  agencyId: string
  campaignId: string
  card: BoardCardResource | null
  /** May open the verification-failure resolution drawer (the `review` ability). */
  canResolve?: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  /** The one WRITE hand-off from this drawer: open the page-level resolve drawer. */
  resolve: [assignment: CampaignAssignmentResource]
}>()

const { t, locale } = useI18n()
const store = useBoardStore()

const tab = ref<'messages' | 'detail' | 'history'>('messages')
const detail = ref<AgencyAssignmentDetailResource | null>(null)
const movements = ref<BoardCardMovementResource[]>([])
const loading = ref(false)
const loadError = ref(false)

const assignmentData = computed(() => props.card?.relationships.assignment.data ?? null)

// Surface the re-offer-after-decline history once the row has moved on from
// `declined` (while it's declined, the status line already says so).
const showDeclinedHistory = computed(
  () =>
    assignmentData.value?.previously_declined === true &&
    assignmentData.value.status !== 'declined',
)

// The per-assignment agency chat transport for the Messages tab. Null for a
// card whose assignment failed to load (no thread to open) — the tab then
// shows a "no conversation" note instead of an empty ChatPanel.
const chatTransport = computed<ChatTransport | null>(() => {
  const assignmentId = assignmentData.value?.id ?? null
  if (assignmentId === null) return null
  return agencyChatTransport(props.agencyId, props.campaignId, assignmentId)
})

// The chat header label — the creator's name (the counterparty).
const chatTitle = computed(
  () => assignmentData.value?.creator?.display_name ?? t('app.campaigns.board.card.unnamed'),
)
const latestDraft = computed(() => detail.value?.relationships.drafts[0] ?? null)
const postedContent = computed(() => detail.value?.relationships.posted_content[0] ?? null)

// The verification-failure resolution hand-off (same gate as the Creators
// tab): offered when the assignment is `posted` and its LATEST post's
// verification FAILED. `posted_content` arrives newest-first (D-7), so [0]
// is the row that matters.
const showResolveAction = computed(() => {
  if (props.canResolve !== true) return false
  const verification = postedContent.value?.attributes.verification_status
  return (
    detail.value?.attributes.status === 'posted' &&
    (verification === 'not_found' || verification === 'mismatch')
  )
})

// Build the CampaignAssignmentResource stub the page-level resolve drawer
// expects (the DraftsTab stub pattern) — it only reads `id`, the creator
// display name, and the status fields.
function onResolveClick(): void {
  const d = detail.value
  if (d === null) return
  emit('resolve', {
    id: d.id,
    type: 'campaign_assignments',
    attributes: {
      status: d.attributes.status,
      agreed_fee_minor_units: d.attributes.agreed_fee_minor_units,
      agreed_fee_currency: d.attributes.agreed_fee_currency,
      countered_fee_minor_units: null,
      countered_fee_currency: null,
      invited_at: d.attributes.invited_at ?? null,
      responded_at: null,
      posting_due_at: d.attributes.posting_due_at,
      verification_status: postedContent.value?.attributes.verification_status ?? null,
      has_pending_contract: null,
      creator: d.attributes.creator,
    },
  })
}

// ── Detail-tab facelift derivations ─────────────────────────────────────────

// Identity header basics come from the CARD FACE (already loaded — avatar,
// name, fee/per); the detail fetch layers campaign · brand + offer text on top.
const avatarUrl = computed(() => assignmentData.value?.creator?.avatar_url ?? null)

const avatarInitial = computed(() => {
  const name = assignmentData.value?.creator?.display_name?.trim()
  return name ? name.charAt(0).toUpperCase() : '?'
})

const campaignLine = computed(() => {
  const campaign = detail.value?.attributes.campaign
  if (!campaign) return null
  return campaign.brand_name ? `${campaign.name} · ${campaign.brand_name}` : campaign.name
})

// The agreed fee + its free-text unit ("€200 / script"). Coalesced PER FIELD:
// card-face data first (present even while the detail fetch is in flight),
// the detail attribute as the fallback for each field independently.
const feeLabel = computed(() => {
  const face = assignmentData.value
  const fetched = detail.value?.attributes
  const minor = face?.agreed_fee_minor_units ?? fetched?.agreed_fee_minor_units
  if (minor === null || minor === undefined) return null
  const currency = face?.agreed_fee_currency ?? fetched?.agreed_fee_currency ?? null
  const money = formatCurrency(minor, currency, locale.value)
  const per = face?.fee_per ?? fetched?.fee_per
  return per ? `${money} / ${per}` : money
})

const offerDescription = computed(() => detail.value?.attributes.offer_description ?? null)
const offerAttachment = computed(() => detail.value?.attributes.offer_attachment ?? null)
const deliverables = computed(() => assignmentData.value?.deliverables ?? [])

const postingDueLabel = computed(() => {
  const due = assignmentData.value?.posting_due_at ?? detail.value?.attributes.posting_due_at
  return due ? formatDate(due, locale.value) : null
})

interface TimelineStep {
  key: string
  label: string
  at: string | null
}

// Five milestones off the detail timestamps (all previously fetched but never
// shown). Labels reuse the assignmentStatus keys — no new i18n surface.
const timelineSteps = computed<TimelineStep[]>(() => {
  const a = detail.value?.attributes
  if (!a) return []
  return [
    {
      key: 'invited',
      label: t('app.campaigns.assignmentStatus.invited'),
      at: a.invited_at ?? null,
    },
    {
      key: 'draft_submitted',
      label: t('app.campaigns.assignmentStatus.draft_submitted'),
      at: a.submitted_draft_at,
    },
    { key: 'approved', label: t('app.campaigns.assignmentStatus.approved'), at: a.approved_at },
    { key: 'posted', label: t('app.campaigns.assignmentStatus.posted'), at: a.posted_at },
    {
      key: 'live_verified',
      label: t('app.campaigns.assignmentStatus.live_verified'),
      at: a.verified_live_at,
    },
  ]
})

function stepDate(at: string | null): string {
  return at ? formatDate(at, locale.value) : t('app.campaigns.board.drawer.detail.none')
}

function movementDate(at: string): string {
  return formatDateTime(at, locale.value)
}

function columnName(id: string | null): string {
  if (id === null) return t('app.campaigns.board.drawer.history.removed')
  return (
    store.columns.find((c) => c.id === id)?.attributes.name ??
    t('app.campaigns.board.drawer.history.removed')
  )
}

function triggerLabel(movement: BoardCardMovementResource): string {
  if (movement.attributes.triggered_by === 'user') {
    return t('app.campaigns.board.drawer.history.manual')
  }
  const event = movement.attributes.triggered_event_key
  const auto = t('app.campaigns.board.drawer.history.auto')
  return event !== null ? `${auto} · ${event}` : auto
}

async function loadDrawer(): Promise<void> {
  const card = props.card
  if (card === null) return
  loading.value = true
  loadError.value = false
  detail.value = null
  movements.value = []
  tab.value = 'messages'

  const assignmentId = card.relationships.assignment.data?.id ?? null
  try {
    const [detailRes, movesRes] = await Promise.all([
      assignmentId !== null
        ? campaignsApi.showAssignment(props.agencyId, props.campaignId, assignmentId)
        : Promise.resolve(null),
      boardApi.movements(props.agencyId, props.campaignId, card.id),
    ])
    if (detailRes !== null) {
      detail.value = detailRes.data
    }
    movements.value = movesRes.data
  } catch (err) {
    if (!(err instanceof ApiError) || err.status !== 404) {
      loadError.value = true
    }
  } finally {
    loading.value = false
  }
}

watch(
  () => props.modelValue,
  (open) => {
    if (open) void loadDrawer()
  },
  { immediate: true },
)

function close(): void {
  emit('update:modelValue', false)
}
</script>

<template>
  <v-dialog
    :model-value="modelValue"
    max-width="720"
    scrollable
    data-test="board-card-drawer"
    @update:model-value="(v) => emit('update:modelValue', v)"
  >
    <v-card>
      <v-card-title class="d-flex align-center">
        {{ t('app.campaigns.board.drawer.title') }}
        <v-spacer />
        <v-btn
          icon="mdi-close"
          variant="text"
          size="small"
          data-test="board-card-drawer-close"
          @click="close"
        />
      </v-card-title>

      <v-tabs v-model="tab" density="compact">
        <v-tab value="messages" data-test="board-card-drawer-tab-messages">
          {{ t('app.campaigns.board.drawer.tabs.messages') }}
        </v-tab>
        <v-tab value="detail" data-test="board-card-drawer-tab-detail">
          {{ t('app.campaigns.board.drawer.tabs.detail') }}
        </v-tab>
        <v-tab value="history" data-test="board-card-drawer-tab-history">
          {{ t('app.campaigns.board.drawer.tabs.history') }}
        </v-tab>
      </v-tabs>
      <v-divider />

      <v-card-text style="min-height: 280px">
        <v-window v-model="tab">
          <!-- Messages (default): the per-assignment agency chat, mounted only
               while the drawer is OPEN so the thread poll stops on close (the
               ChatDialog pattern). Independent of the detail/movements fetch —
               a detail load error never blocks messaging. -->
          <v-window-item value="messages">
            <ChatPanel
              v-if="modelValue && chatTransport"
              :transport="chatTransport"
              :title="chatTitle"
            />
            <p
              v-else
              class="text-medium-emphasis text-body-2"
              data-test="board-card-drawer-messages-none"
            >
              {{ t('app.campaigns.board.drawer.messages.none') }}
            </p>
          </v-window-item>

          <v-window-item value="detail" eager>
            <v-skeleton-loader v-if="loading" type="paragraph" />
            <v-alert
              v-else-if="loadError"
              type="error"
              variant="tonal"
              density="compact"
              data-test="board-card-drawer-error"
            >
              {{ t('app.campaigns.board.drawer.loadError') }}
            </v-alert>
            <div v-else data-test="board-card-drawer-detail">
              <!-- Identity header (concept top block): avatar + name + status,
                   campaign · brand underneath. -->
              <div class="d-flex align-center ga-3">
                <v-avatar size="48" class="drawer-detail__avatar">
                  <v-img
                    v-if="avatarUrl"
                    :src="avatarUrl"
                    :alt="assignmentData?.creator?.display_name ?? ''"
                    data-test="board-card-drawer-avatar"
                  />
                  <span v-else class="text-subtitle-1">{{ avatarInitial }}</span>
                </v-avatar>
                <div class="min-width-0">
                  <div class="d-flex align-center ga-2">
                    <span class="text-subtitle-1 font-weight-bold text-truncate">
                      {{
                        assignmentData?.creator?.display_name ??
                        t('app.campaigns.board.card.unnamed')
                      }}
                    </span>
                    <v-chip v-if="assignmentData" size="x-small" variant="tonal">
                      {{ t(`app.campaigns.assignmentStatus.${assignmentData.status}`) }}
                    </v-chip>
                    <!-- "Declined, then re-invited" history tag (re-offer-
                         after-decline chunk); reuses the declined label. -->
                    <v-chip
                      v-if="showDeclinedHistory"
                      size="x-small"
                      variant="tonal"
                      color="medium-emphasis"
                      data-test="board-card-drawer-declined-history"
                    >
                      {{ t('app.campaigns.assignmentStatus.declined') }}
                    </v-chip>
                  </div>
                  <div
                    v-if="campaignLine"
                    class="text-caption text-medium-emphasis text-truncate"
                    data-test="board-card-drawer-campaign"
                  >
                    {{ campaignLine }}
                  </div>
                </div>
              </div>

              <!-- Offer terms: the invitation's fee / per / description /
                   attachment (invite-offer-details fields, now readable from
                   the board). -->
              <template v-if="feeLabel || offerDescription || offerAttachment">
                <div class="text-overline text-medium-emphasis mt-4">
                  {{ t('app.campaigns.board.drawer.detail.offer') }}
                </div>
                <div v-if="feeLabel" class="text-body-2" data-test="board-card-drawer-fee">
                  {{ feeLabel }}
                </div>
                <p
                  v-if="offerDescription"
                  class="text-body-2 text-medium-emphasis mt-1 mb-0 drawer-detail__description"
                  data-test="board-card-drawer-offer-description"
                >
                  {{ offerDescription }}
                </p>
                <div v-if="offerAttachment" class="mt-1">
                  <a
                    v-if="offerAttachment.url"
                    :href="offerAttachment.url"
                    target="_blank"
                    rel="noopener"
                    class="text-body-2"
                    data-test="board-card-drawer-attachment"
                  >
                    <v-icon icon="mdi-paperclip" size="14" /> {{ offerAttachment.name }}
                  </a>
                  <span v-else class="text-body-2 text-medium-emphasis">
                    <v-icon icon="mdi-paperclip" size="14" /> {{ offerAttachment.name }}
                  </span>
                </div>
              </template>

              <!-- Deliverables (card-face data, previously shown nowhere). -->
              <template v-if="deliverables && deliverables.length > 0">
                <div class="text-overline text-medium-emphasis mt-4">
                  {{ t('app.campaigns.board.drawer.detail.deliverables') }}
                </div>
                <div class="d-flex flex-wrap ga-1" data-test="board-card-drawer-deliverables">
                  <v-chip v-for="d in deliverables" :key="d" size="x-small" variant="outlined">
                    {{ d }}
                  </v-chip>
                </div>
              </template>

              <!-- Progress timeline: the five milestone timestamps the detail
                   endpoint always carried but the drawer never showed. -->
              <template v-if="timelineSteps.length > 0">
                <div class="text-overline text-medium-emphasis mt-4">
                  {{ t('app.campaigns.board.drawer.detail.timeline') }}
                </div>
                <div data-test="board-card-drawer-timeline">
                  <div
                    v-for="step in timelineSteps"
                    :key="step.key"
                    class="d-flex align-center ga-2 py-1"
                    :data-test="`board-card-drawer-step-${step.key}`"
                  >
                    <v-icon
                      :icon="step.at ? 'mdi-check-circle' : 'mdi-circle-outline'"
                      size="16"
                      :color="step.at ? 'primary' : 'medium-emphasis'"
                    />
                    <span class="text-body-2" :class="step.at ? '' : 'text-medium-emphasis'">
                      {{ step.label }}
                    </span>
                    <v-btn
                      v-if="step.key === 'live_verified' && showResolveAction"
                      color="warning"
                      variant="flat"
                      size="x-small"
                      data-test="board-card-drawer-resolve"
                      @click="onResolveClick"
                    >
                      {{ t('app.campaigns.resolution.action') }}
                    </v-btn>
                    <v-spacer />
                    <span class="text-caption text-medium-emphasis">{{ stepDate(step.at) }}</span>
                  </div>
                </div>
              </template>

              <v-list density="compact" class="mt-2">
                <v-list-item>
                  <v-list-item-title>{{
                    t('app.campaigns.board.drawer.detail.postingDue')
                  }}</v-list-item-title>
                  <v-list-item-subtitle data-test="board-card-drawer-due">
                    {{ postingDueLabel ?? t('app.campaigns.board.drawer.detail.none') }}
                  </v-list-item-subtitle>
                </v-list-item>
                <v-list-item v-if="latestDraft">
                  <v-list-item-title>{{
                    t('app.campaigns.board.drawer.detail.latestDraft')
                  }}</v-list-item-title>
                  <v-list-item-subtitle
                    class="d-flex align-center ga-2"
                    data-test="board-card-drawer-draft"
                  >
                    <v-chip size="x-small" variant="tonal">
                      {{
                        t('app.campaigns.review.draftVersion', {
                          n: latestDraft.attributes.version,
                        })
                      }}
                      ·
                      {{
                        t(
                          `app.campaigns.review.draftStatus.${latestDraft.attributes.review_status}`,
                        )
                      }}
                    </v-chip>
                    <span class="text-truncate">
                      {{
                        latestDraft.attributes.caption ??
                        t('app.campaigns.board.drawer.detail.none')
                      }}
                    </span>
                  </v-list-item-subtitle>
                </v-list-item>
                <v-list-item v-if="postedContent">
                  <v-list-item-title>{{
                    t('app.campaigns.board.drawer.detail.postedContent')
                  }}</v-list-item-title>
                  <v-list-item-subtitle>
                    <a
                      :href="postedContent.attributes.post_url"
                      target="_blank"
                      rel="noopener"
                      data-test="board-card-drawer-posted"
                    >
                      {{ postedContent.attributes.post_url }}
                    </a>
                  </v-list-item-subtitle>
                </v-list-item>
              </v-list>
            </div>
          </v-window-item>

          <v-window-item value="history" eager>
            <v-skeleton-loader v-if="loading" type="paragraph" />
            <v-alert
              v-else-if="loadError"
              type="error"
              variant="tonal"
              density="compact"
              data-test="board-card-drawer-history-error"
            >
              {{ t('app.campaigns.board.drawer.loadError') }}
            </v-alert>
            <div v-else data-test="board-card-drawer-history">
              <p
                v-if="movements.length === 0"
                class="text-medium-emphasis text-body-2"
                data-test="board-card-drawer-history-empty"
              >
                {{ t('app.campaigns.board.drawer.history.empty') }}
              </p>
              <v-list v-else lines="two" density="compact">
                <v-list-item
                  v-for="movement in movements"
                  :key="movement.id"
                  :data-test="`board-card-movement-${movement.id}`"
                >
                  <v-list-item-title>
                    {{ columnName(movement.attributes.from_column_id) }}
                    →
                    {{ columnName(movement.attributes.to_column_id) }}
                  </v-list-item-title>
                  <v-list-item-subtitle>
                    {{ triggerLabel(movement) }} ·
                    {{ movementDate(movement.attributes.created_at) }}
                  </v-list-item-subtitle>
                </v-list-item>
              </v-list>
            </div>
          </v-window-item>
        </v-window>
      </v-card-text>
    </v-card>
  </v-dialog>
</template>

<style scoped>
.drawer-detail__avatar {
  background: rgba(var(--v-theme-on-surface), 0.08);
}
.drawer-detail__description {
  white-space: pre-wrap;
  word-break: break-word;
}
.min-width-0 {
  min-width: 0;
}
</style>
