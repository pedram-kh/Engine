<script setup lang="ts">
/**
 * The campaign-detail Applications tab (Jobs Board chunk 4, D1) — the agency
 * half of the jobs board: who applied, and the two answers.
 *
 * ── Why a tab and not a board column ────────────────────────────────────────
 *
 * Chunk 3 wrote "agency-side board column"; the board cannot host this. A board
 * card IS an assignment (`board_cards.assignment_id` is NOT NULL, UNIQUE and
 * CASCADE) and an application has no assignment yet, by definition — and the
 * board's own invariant is that a drag is consequence-free, which cannot express
 * accept or reject. The recorded reinterpretation is this tab, on the DraftsTab
 * shape. The board then handles post-accept for free: accepting creates an
 * `invited` assignment, which lands as a card in the Invited column through
 * machinery that already ships.
 *
 * ── The badge counts PENDING only ───────────────────────────────────────────
 *
 * `meta.pending_total`, never the creator-facing `applicant_count`: that number
 * is INTEREST semantics ("how many creators applied", what a creator weighing
 * their odds needs), and using it here would put a permanent unclearable badge on
 * every campaign that ever had an application.
 *
 * Rows show roster-level creator identity only (name, avatar). An applicant is
 * rostered by definition — applying requires the relation — so this creates NO
 * new exposure of a creator's details to the agency.
 */

import { ApiError, formatDateTime } from '@catalyst/api-client'
import type {
  CampaignApplicationListItemResource,
  CampaignApplicationListParams,
  CampaignApplicationStatus,
} from '@catalyst/api-client'
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { CEmptyState } from '@catalyst/ui'

import { campaignsApi } from '../api/campaigns.api'
import AcceptApplicationDialog from './AcceptApplicationDialog.vue'

type FilterValue = 'all' | CampaignApplicationStatus

const props = defineProps<{
  agencyId: string
  campaignId: string
  /** The execute tier (invite): accept and reject sit under one ability (Q4). */
  canAct: boolean
  campaignCurrency: string | null
}>()

const emit = defineEmits<{
  /** The tab badge's number, hoisted so the tab strip can render it. */
  'pending-total': [value: number]
  /** An application was answered — the page reloads the Creators tab / board. */
  answered: [message: string]
}>()

const { t, locale } = useI18n()

const rows = ref<CampaignApplicationListItemResource[]>([])
const loading = ref(false)
const loadError = ref(false)
const page = ref(1)
const lastPage = ref(1)
const perPage = 25
const filter = ref<FilterValue>('all')

const acceptDialog = ref(false)
const acceptTarget = ref<CampaignApplicationListItemResource | null>(null)

const rejectPrompt = ref(false)
const rejectTarget = ref<CampaignApplicationListItemResource | null>(null)
const rejecting = ref(false)
const actionError = ref<string | null>(null)

const filterOptions = computed((): { title: string; value: FilterValue }[] => [
  { title: t('app.campaigns.applications.filters.all'), value: 'all' },
  { title: t('app.campaigns.applications.status.pending'), value: 'pending' },
  { title: t('app.campaigns.applications.status.accepted'), value: 'accepted' },
  { title: t('app.campaigns.applications.status.rejected'), value: 'rejected' },
])

const hasRows = computed(() => rows.value.length > 0)

function isPending(row: CampaignApplicationListItemResource): boolean {
  return row.attributes.status === 'pending'
}

async function load(initial = false): Promise<void> {
  loading.value = initial && rows.value.length === 0
  try {
    const params: CampaignApplicationListParams = { page: page.value, per_page: perPage }
    if (filter.value !== 'all') {
      params.status = filter.value
    }
    const res = await campaignsApi.listApplications(props.agencyId, props.campaignId, params)
    rows.value = res.data
    lastPage.value = res.meta.last_page
    loadError.value = false
    emit('pending-total', res.meta.pending_total)
  } catch {
    if (rows.value.length === 0) {
      loadError.value = true
    }
  } finally {
    loading.value = false
  }
}

function openAccept(row: CampaignApplicationListItemResource): void {
  acceptTarget.value = row
  acceptDialog.value = true
}

function openReject(row: CampaignApplicationListItemResource): void {
  rejectTarget.value = row
  rejectPrompt.value = true
}

function onAccepted(message: string): void {
  emit('answered', message)
  void load()
}

/**
 * The confirmed reject (D4). No reason is collected — the creator-facing copy is
 * a kind generic "not selected" whatever an agency might type, and the audit row
 * plus its actor is the internal record.
 */
async function confirmReject(): Promise<void> {
  const target = rejectTarget.value
  if (target === null) return

  rejecting.value = true
  actionError.value = null
  try {
    await campaignsApi.rejectApplication(props.agencyId, props.campaignId, target.id)
    rejectPrompt.value = false
    emit(
      'answered',
      t('app.campaigns.applications.rejectedToast', {
        name: target.attributes.creator?.display_name ?? t('app.campaigns.invite.unnamed'),
      }),
    )
    void load()
  } catch (err) {
    // Surfaced, never swallowed: the common refusal is someone else having
    // answered this application already (§5.6), and the operator needs to know
    // that rather than watch a button do nothing.
    const code =
      err instanceof ApiError ? (err.raw as { meta?: { code?: string } } | null)?.meta?.code : null
    actionError.value =
      code === 'application.not_pending'
        ? t('app.campaigns.applications.refusal.application.not_pending')
        : t('app.campaigns.applications.refusal.generic')
  } finally {
    rejecting.value = false
  }
}

function formatStamp(iso: string | null): string {
  return formatDateTime(iso, locale.value)
}

watch(filter, () => {
  page.value = 1
  void load(true)
})

watch(page, () => {
  void load()
})

onMounted(() => {
  void load(true)
})

defineExpose({
  reload: (): Promise<void> => load(false),
})
</script>

<template>
  <div class="applications-tab" data-test="applications-tab">
    <v-select
      v-model="filter"
      :items="filterOptions"
      item-title="title"
      item-value="value"
      density="compact"
      variant="outlined"
      max-width="280"
      class="mb-4"
      hide-details
      data-test="applications-filter"
    />

    <v-alert
      v-if="actionError"
      type="error"
      variant="tonal"
      density="compact"
      class="mb-3"
      data-test="applications-action-error"
    >
      {{ actionError }}
    </v-alert>

    <v-skeleton-loader
      v-if="loading"
      type="list-item-two-line@3"
      data-test="applications-skeleton"
    />

    <v-alert
      v-else-if="loadError"
      type="error"
      variant="tonal"
      density="compact"
      data-test="applications-load-error"
    >
      {{ t('app.campaigns.applications.loadFailed') }}
    </v-alert>

    <CEmptyState
      v-else-if="!hasRows"
      data-test="applications-empty-state"
      title-tag="h2"
      :title="t('app.campaigns.applications.empty.heading')"
      :body="t('app.campaigns.applications.empty.body')"
    >
      <template #icon>
        <v-icon icon="mdi-account-arrow-right-outline" size="56" color="medium-emphasis" />
      </template>
    </CEmptyState>

    <template v-else>
      <v-list lines="two" data-test="applications-list">
        <v-list-item v-for="row in rows" :key="row.id" :data-test="`applications-row-${row.id}`">
          <template #prepend>
            <v-avatar size="40" color="surface-variant">
              <v-img
                v-if="row.attributes.creator?.avatar_url"
                :src="row.attributes.creator.avatar_url"
                :alt="row.attributes.creator.display_name ?? ''"
                cover
              />
              <span v-else class="text-caption">
                {{ (row.attributes.creator?.display_name ?? '?')[0]?.toUpperCase() }}
              </span>
            </v-avatar>
          </template>

          <v-list-item-title class="d-flex align-center ga-2 flex-wrap">
            {{ row.attributes.creator?.display_name ?? t('app.campaigns.invite.unnamed') }}
            <v-chip
              size="x-small"
              variant="tonal"
              :color="isPending(row) ? 'primary' : undefined"
              :data-test="`applications-status-${row.id}`"
            >
              {{ t(`app.campaigns.applications.status.${row.attributes.status}`) }}
            </v-chip>
          </v-list-item-title>

          <v-list-item-subtitle>
            {{
              t('app.campaigns.applications.appliedAt', {
                date: formatStamp(row.attributes.applied_at),
              })
            }}
            <span
              v-if="row.attributes.note"
              class="d-block mt-1"
              :data-test="`applications-note-${row.id}`"
            >
              {{ row.attributes.note }}
            </span>
          </v-list-item-subtitle>

          <template v-if="canAct && isPending(row)" #append>
            <div class="d-flex align-center ga-2">
              <v-btn
                variant="outlined"
                size="small"
                :data-test="`applications-reject-${row.id}`"
                @click="openReject(row)"
              >
                {{ t('app.campaigns.applications.reject.action') }}
              </v-btn>
              <v-btn
                color="primary"
                variant="flat"
                size="small"
                :data-test="`applications-accept-${row.id}`"
                @click="openAccept(row)"
              >
                {{ t('app.campaigns.applications.accept.action') }}
              </v-btn>
            </div>
          </template>
        </v-list-item>
      </v-list>

      <div v-if="lastPage > 1" class="d-flex justify-center mt-4">
        <v-pagination
          v-model="page"
          :length="lastPage"
          :total-visible="7"
          density="comfortable"
          data-test="applications-pagination"
        />
      </div>
    </template>

    <AcceptApplicationDialog
      v-if="acceptDialog"
      v-model="acceptDialog"
      :agency-id="agencyId"
      :campaign-id="campaignId"
      :application="acceptTarget"
      :campaign-currency="campaignCurrency"
      @accepted="onAccepted"
    />

    <!-- The terminal-action confirmation (ReviewDraftDrawer's pattern): mounted
         with v-if so it carries no state between rows. -->
    <v-dialog
      v-if="rejectPrompt"
      v-model="rejectPrompt"
      max-width="420"
      data-test="reject-application-dialog"
    >
      <v-card>
        <v-card-title class="text-h6">
          {{ t('app.campaigns.applications.reject.title') }}
        </v-card-title>
        <v-card-text data-test="reject-application-body">
          {{
            t('app.campaigns.applications.reject.body', {
              name:
                rejectTarget?.attributes.creator?.display_name ?? t('app.campaigns.invite.unnamed'),
            })
          }}
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" data-test="reject-application-cancel" @click="rejectPrompt = false">
            {{ t('app.campaigns.applications.reject.cancel') }}
          </v-btn>
          <v-btn
            color="error"
            variant="flat"
            :loading="rejecting"
            data-test="reject-application-confirm"
            @click="confirmReject"
          >
            {{ t('app.campaigns.applications.reject.confirm') }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>
