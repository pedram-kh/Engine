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
 *
 * ── AH-059 (D4): this tab STAYS, and its list logic moved out ───────────────
 *
 * The board gained a pending-only Applications column, which is a working surface
 * — "what still needs an answer today". This tab is the HISTORY: every status,
 * filterable, including the rejected rows a column has no business showing. Both
 * were kept deliberately rather than one replacing the other; the revisit note is
 * in the chunk's review.
 *
 * What that produced is a second consumer, so the list state moved into
 * {@link useCampaignApplications} and the reject confirmation into
 * {@link RejectApplicationDialog}. No behaviour changed here: the extraction was
 * required to move a component without moving a single assertion.
 */

import { formatDateTime } from '@catalyst/api-client'
import type { CampaignApplicationListItemResource } from '@catalyst/api-client'
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { CEmptyState } from '@catalyst/ui'

import CreatorProfileDialog from '@/components/CreatorProfileDialog.vue'

import {
  type ApplicationsFilter,
  useCampaignApplications,
} from '../composables/useCampaignApplications'
import AcceptApplicationDialog from './AcceptApplicationDialog.vue'
import RejectApplicationDialog from './RejectApplicationDialog.vue'

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

/**
 * The list, from the composable the board's Applications column also uses
 * (AH-059, S7a). This tab keeps the filter settable: it is the full history.
 */
const applications = useCampaignApplications(
  () => props.agencyId,
  () => props.campaignId,
  // The badge number is hoisted per FETCH, not per value change: a campaign with
  // genuinely zero pending applications still has to say so, and a watcher would
  // stay silent because 0 is the initial value.
  { onLoaded: (total) => emit('pending-total', total) },
)

const { rows, loading, loadError, page, lastPage, filter, actionError, hasRows, load, isPending } =
  applications

const acceptDialog = ref(false)
const acceptTarget = ref<CampaignApplicationListItemResource | null>(null)

const rejectPrompt = ref(false)
const rejectTarget = ref<CampaignApplicationListItemResource | null>(null)

const filterOptions = computed((): { title: string; value: ApplicationsFilter }[] => [
  { title: t('app.campaigns.applications.filters.all'), value: 'all' },
  { title: t('app.campaigns.applications.status.pending'), value: 'pending' },
  { title: t('app.campaigns.applications.status.accepted'), value: 'accepted' },
  { title: t('app.campaigns.applications.status.rejected'), value: 'rejected' },
])

function openAccept(row: CampaignApplicationListItemResource): void {
  acceptTarget.value = row
  acceptDialog.value = true
}

function openReject(row: CampaignApplicationListItemResource): void {
  rejectTarget.value = row
  rejectPrompt.value = true
}

// AH-080 (D2c) — the identity block (avatar + name) opens the profile
// dialog, same as the board's Applications pseudo-column. An applicant is
// rostered by definition, so `assumeFull: true` — one fetch, no 404
// fallback dance. Accept/Reject stay their own click targets (D5).
const profileDialog = ref(false)
const profileTarget = ref<CampaignApplicationListItemResource | null>(null)

function openProfile(row: CampaignApplicationListItemResource): void {
  if (row.attributes.creator === null) return
  profileTarget.value = row
  profileDialog.value = true
}

/** Both answers land here: toast upward, then refetch. */
function onAnswered(message: string): void {
  actionError.value = null
  emit('answered', message)
  void load()
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
            <!-- AH-080 (D2c) — half of the identity block; the other half is
                 the name below. Nothing wraps the whole row (D5). -->
            <div
              role="button"
              tabindex="0"
              :aria-label="t('app.roster.detail.sections.profile')"
              :data-test="`applications-profile-${row.id}`"
              @click="openProfile(row)"
              @keydown.enter.prevent="openProfile(row)"
              @keydown.space.prevent="openProfile(row)"
            >
              <v-avatar size="40" color="surface-variant" class="applications-identity">
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
            </div>
          </template>

          <v-list-item-title class="d-flex align-center ga-2 flex-wrap">
            <span
              class="applications-identity"
              role="button"
              tabindex="0"
              :aria-label="t('app.roster.detail.sections.profile')"
              @click="openProfile(row)"
              @keydown.enter.prevent="openProfile(row)"
              @keydown.space.prevent="openProfile(row)"
            >
              {{ row.attributes.creator?.display_name ?? t('app.campaigns.invite.unnamed') }}
            </span>
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
      @accepted="onAnswered"
    />

    <RejectApplicationDialog
      v-if="rejectPrompt"
      v-model="rejectPrompt"
      :agency-id="agencyId"
      :campaign-id="campaignId"
      :application="rejectTarget"
      @rejected="onAnswered"
      @refused="(message) => (actionError = message)"
    />

    <!-- AH-080 (D2c) — an applicant is rostered by definition, assumeFull
         skips the 404-fallback dance. -->
    <CreatorProfileDialog
      v-if="profileTarget?.attributes.creator"
      v-model="profileDialog"
      :agency-id="agencyId"
      :creator-ulid="profileTarget.attributes.creator.id"
      :assume-full="true"
    />
  </div>
</template>

<style scoped>
.applications-identity {
  cursor: pointer;
  border-radius: 6px;
}
.applications-identity:focus-visible {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: 2px;
}
</style>
