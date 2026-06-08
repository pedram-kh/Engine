<script setup lang="ts">
/**
 * Campaign-wide Drafts tab — lists every draft version for the campaign
 * (filterable by review_status, paginated). Row action opens the page-level
 * ReviewDraftDrawer via an assignment stub; the drawer self-loads via
 * showAssignment (signed media URLs load lazily there, not in this list).
 */

import type {
  CampaignAssignmentResource,
  CampaignDraftListItemResource,
  DraftReviewStatus,
} from '@catalyst/api-client'
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { CEmptyState } from '@catalyst/ui'

import { campaignsApi } from '../api/campaigns.api'

type FilterValue = 'all' | DraftReviewStatus

const props = defineProps<{
  agencyId: string
  campaignId: string
  canReview: boolean
}>()

const emit = defineEmits<{
  'open-review': [assignment: CampaignAssignmentResource]
}>()

const { t } = useI18n()

const rows = ref<CampaignDraftListItemResource[]>([])
const loading = ref(false)
const loadError = ref(false)
const page = ref(1)
const lastPage = ref(1)
const perPage = 25
const filter = ref<FilterValue>('all')

const filterOptions = computed((): { title: string; value: FilterValue }[] => [
  { title: t('app.campaigns.drafts.filters.all'), value: 'all' },
  { title: t('app.campaigns.review.draftStatus.pending'), value: 'pending' },
  { title: t('app.campaigns.review.draftStatus.approved'), value: 'approved' },
  { title: t('app.campaigns.review.draftStatus.revision_requested'), value: 'revision_requested' },
  { title: t('app.campaigns.review.draftStatus.rejected'), value: 'rejected' },
])

const hasRows = computed(() => rows.value.length > 0)

function toAssignmentStub(row: CampaignDraftListItemResource): CampaignAssignmentResource | null {
  const assignment = row.attributes.assignment
  if (assignment === null) return null

  return {
    id: assignment.id,
    type: 'campaign_assignments',
    attributes: {
      status: assignment.status,
      agreed_fee_minor_units: null,
      agreed_fee_currency: null,
      countered_fee_minor_units: null,
      countered_fee_currency: null,
      invited_at: null,
      responded_at: null,
      posting_due_at: null,
      verification_status: null,
      has_pending_contract: null,
      creator: assignment.creator,
    },
  }
}

async function load(initial = false): Promise<void> {
  loading.value = initial && rows.value.length === 0
  try {
    const params: { page: number; per_page: number; review_status?: DraftReviewStatus } = {
      page: page.value,
      per_page: perPage,
    }
    if (filter.value !== 'all') {
      params.review_status = filter.value
    }
    const res = await campaignsApi.listDrafts(props.agencyId, props.campaignId, params)
    rows.value = res.data
    lastPage.value = res.meta.last_page
    loadError.value = false
  } catch {
    if (rows.value.length === 0) {
      loadError.value = true
    }
  } finally {
    loading.value = false
  }
}

function openReview(row: CampaignDraftListItemResource): void {
  const stub = toAssignmentStub(row)
  if (stub !== null) {
    emit('open-review', stub)
  }
}

function formatSubmittedAt(iso: string | null): string {
  if (iso === null) return '—'
  return new Date(iso).toLocaleString()
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
  <div class="drafts-tab" data-test="drafts-tab">
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
      data-test="drafts-filter"
    />

    <v-skeleton-loader v-if="loading" type="list-item-two-line@3" data-test="drafts-skeleton" />

    <v-alert
      v-else-if="loadError"
      type="error"
      variant="tonal"
      density="compact"
      data-test="drafts-load-error"
    >
      {{ t('app.campaigns.drafts.loadFailed') }}
    </v-alert>

    <CEmptyState
      v-else-if="!hasRows"
      data-test="drafts-empty-state"
      title-tag="h2"
      :title="t('app.campaigns.drafts.empty.heading')"
      :body="t('app.campaigns.drafts.empty.body')"
    >
      <template #icon>
        <v-icon icon="mdi-file-document-outline" size="56" color="medium-emphasis" />
      </template>
    </CEmptyState>

    <template v-else>
      <v-list lines="two" data-test="drafts-list">
        <v-list-item v-for="row in rows" :key="row.id" :data-test="`drafts-row-${row.id}`">
          <v-list-item-title class="d-flex align-center ga-2 flex-wrap">
            {{ row.attributes.assignment?.creator?.display_name ?? '—' }}
            <v-chip size="x-small" variant="tonal">
              {{ t('app.campaigns.review.draftVersion', { n: row.attributes.version }) }}
            </v-chip>
            <v-chip size="x-small" variant="tonal" color="primary">
              {{ t(`app.campaigns.review.draftStatus.${row.attributes.review_status}`) }}
            </v-chip>
            <v-chip v-if="row.attributes.assignment" size="x-small" variant="outlined">
              {{ t(`app.campaigns.assignmentStatus.${row.attributes.assignment.status}`) }}
            </v-chip>
          </v-list-item-title>
          <v-list-item-subtitle>
            {{
              t('app.campaigns.drafts.submittedAt', {
                date: formatSubmittedAt(row.attributes.submitted_at),
              })
            }}
            <span v-if="row.attributes.review_feedback" class="d-block mt-1">
              {{ row.attributes.review_feedback }}
            </span>
          </v-list-item-subtitle>
          <template v-if="canReview" #append>
            <v-btn
              color="primary"
              variant="flat"
              size="small"
              :data-test="`drafts-review-${row.id}`"
              @click="openReview(row)"
            >
              {{ t('app.campaigns.review.action') }}
            </v-btn>
          </template>
        </v-list-item>
      </v-list>

      <div v-if="lastPage > 1" class="d-flex justify-center mt-4">
        <v-pagination
          v-model="page"
          :length="lastPage"
          :total-visible="7"
          density="comfortable"
          data-test="drafts-pagination"
        />
      </div>
    </template>
  </div>
</template>
