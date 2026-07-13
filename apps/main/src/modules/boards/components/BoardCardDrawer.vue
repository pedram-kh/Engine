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
 *   - Detail: the assignment summary, fetched via `campaignsApi.showAssignment`
 *     (the same agency-side detail the review drawer consumes) — status, creator,
 *     posting due, deliverables, latest draft caption, posted link. Null-safe.
 *   - Movement history: `boardApi.movements` (newest-first). Column ids resolve
 *     to names via the store; a since-deleted column renders "(removed)" rather
 *     than a dangling id (§14.3, null-safe).
 *
 * This is a READ surface for Detail + History — no manual-move reason control
 * here (Q2 tech-debt note); Messages is the one interactive tab.
 */

import { ApiError } from '@catalyst/api-client'
import type {
  AgencyAssignmentDetailResource,
  BoardCardMovementResource,
  BoardCardResource,
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
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
}>()

const { t } = useI18n()
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
              <v-list density="compact">
                <v-list-item>
                  <v-list-item-title>{{
                    t('app.campaigns.board.drawer.detail.creator')
                  }}</v-list-item-title>
                  <v-list-item-subtitle>
                    {{
                      assignmentData?.creator?.display_name ?? t('app.campaigns.board.card.unnamed')
                    }}
                  </v-list-item-subtitle>
                </v-list-item>
                <v-list-item>
                  <v-list-item-title>{{
                    t('app.campaigns.board.drawer.detail.status')
                  }}</v-list-item-title>
                  <v-list-item-subtitle class="d-flex align-center ga-2">
                    <span>{{
                      assignmentData
                        ? t(`app.campaigns.assignmentStatus.${assignmentData.status}`)
                        : t('app.campaigns.board.drawer.detail.none')
                    }}</span>
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
                  </v-list-item-subtitle>
                </v-list-item>
                <v-list-item>
                  <v-list-item-title>{{
                    t('app.campaigns.board.drawer.detail.postingDue')
                  }}</v-list-item-title>
                  <v-list-item-subtitle>
                    {{
                      assignmentData?.posting_due_at ?? t('app.campaigns.board.drawer.detail.none')
                    }}
                  </v-list-item-subtitle>
                </v-list-item>
                <v-list-item v-if="latestDraft">
                  <v-list-item-title>{{
                    t('app.campaigns.board.drawer.detail.latestDraft')
                  }}</v-list-item-title>
                  <v-list-item-subtitle data-test="board-card-drawer-draft">
                    {{
                      latestDraft.attributes.caption ?? t('app.campaigns.board.drawer.detail.none')
                    }}
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
                    {{ triggerLabel(movement) }} · {{ movement.attributes.created_at }}
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
