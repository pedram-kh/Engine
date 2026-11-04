<script setup lang="ts">
/**
 * Admin "all creators" surface (Sprint 13, D-4; filters: AH-079).
 *
 * The unfiltered roster — every creator regardless of application or KYC
 * status (the approvals queue and the KYC queue are the filtered triage
 * views). Same backend endpoint (GET /admin/creators); paginated;
 * click-through to the detail drill-in.
 *
 * Three independent, AND-composing chip filters, cloned from the
 * review-queue (CreatorListPage) / KYC-queue (KycQueuePage) pattern:
 * application status and KYC state reuse those pages' existing i18n keys
 * verbatim (same words, already translated) — only "Connected" is new
 * copy.
 *
 * AH-117 supersedes this page's earlier "no URL-state persistence" decision
 * (AH-079): page, page size and the three chips now live in the URL query
 * (`useListQueryState`), like its two siblings, because opening a creator
 * and pressing back otherwise lost the operator's place. One watcher over the
 * five refs is the only load trigger (the table's mount echo writes equal
 * values and changes nothing).
 */

import { onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import { ApiError } from '@catalyst/api-client'
import type { CreatorApplicationStatus, CreatorKycStatus } from '@catalyst/api-client'

import { creatorEmail, creatorLabel } from '@/core/creators/creatorLabel'
import DeletionRequestedChip from '@/core/creators/DeletionRequestedChip.vue'
import {
  exceedsLastPage,
  oneOfParamWithFallback,
  pageParam,
  perPageParam,
  useListQueryState,
} from '@/composables/useListQueryState'

import { adminCreatorsApi, type AdminCreatorListItem } from '../api/creators.api'

type StatusFilter = CreatorApplicationStatus | 'all'
type KycFilter = CreatorKycStatus | 'all'
type ConnectedFilter = 'yes' | 'no' | 'all'

const { t } = useI18n()
const router = useRouter()

const STATUS_FILTERS = [
  'all',
  'pending',
  'approved',
  'rejected',
  'incomplete',
] as const satisfies readonly StatusFilter[]
const KYC_FILTERS = [
  'all',
  'pending',
  'verified',
  'rejected',
  'none',
] as const satisfies readonly KycFilter[]
const CONNECTED_FILTERS = ['all', 'yes', 'no'] as const satisfies readonly ConnectedFilter[]
const PER_PAGE_OPTIONS = [10, 25, 50, 100]

const {
  page,
  per_page: perPage,
  status: statusFilter,
  kyc_status: kycFilter,
  connected: connectedFilter,
} = useListQueryState({
  page: pageParam,
  per_page: perPageParam(PER_PAGE_OPTIONS, 25),
  status: oneOfParamWithFallback<StatusFilter>(STATUS_FILTERS, 'all'),
  kyc_status: oneOfParamWithFallback<KycFilter>(KYC_FILTERS, 'all'),
  connected: oneOfParamWithFallback<ConnectedFilter>(CONNECTED_FILTERS, 'all'),
})

const items = ref<AdminCreatorListItem[]>([])
const totalItems = ref(0)
const loading = ref(false)
const errorKey = ref<string | null>(null)

const headers = [
  { title: t('admin.creators.list.fields.name'), key: 'attributes.display_name', sortable: false },
  { title: t('admin.creators.list.fields.email'), key: 'attributes.email', sortable: false },
  {
    title: t('admin.creators.list.fields.status'),
    key: 'attributes.application_status',
    sortable: false,
    width: 140,
  },
  {
    title: t('admin.creators.list.fields.kyc'),
    key: 'attributes.kyc_status',
    sortable: false,
    width: 120,
  },
  { title: '', key: 'actions', sortable: false, width: 80, align: 'end' as const },
]

// Status/KYC labels reuse the review-queue and KYC-queue keys verbatim —
// same words, already translated in all 24 locales.
const statusFilterItems: { label: string; value: StatusFilter }[] = [
  { label: t('admin.creators.list.filters.all'), value: 'all' },
  { label: t('admin.creators.list.filters.pending'), value: 'pending' },
  { label: t('admin.creators.list.filters.approved'), value: 'approved' },
  { label: t('admin.creators.list.filters.rejected'), value: 'rejected' },
  { label: t('admin.creators.list.filters.incomplete'), value: 'incomplete' },
]

const kycFilterItems: { label: string; value: KycFilter }[] = [
  { label: t('admin.creators.kyc.filters.all'), value: 'all' },
  { label: t('admin.creators.kyc.filters.pending'), value: 'pending' },
  { label: t('admin.creators.kyc.filters.verified'), value: 'verified' },
  { label: t('admin.creators.kyc.filters.rejected'), value: 'rejected' },
  { label: t('admin.creators.kyc.filters.none'), value: 'none' },
]

const connectedFilterItems: { label: string; value: ConnectedFilter }[] = [
  { label: t('admin.creators.all.filters.connected.all'), value: 'all' },
  { label: t('admin.creators.all.filters.connected.yes'), value: 'yes' },
  { label: t('admin.creators.all.filters.connected.no'), value: 'no' },
]

// A response that is no longer the newest request is dropped (a slow page 1
// must not overwrite a fast page 2).
let requestSeq = 0

async function load(): Promise<void> {
  const seq = ++requestSeq
  loading.value = true
  errorKey.value = null
  try {
    const res = await adminCreatorsApi.list({
      status: statusFilter.value === 'all' ? undefined : statusFilter.value,
      kyc_status: kycFilter.value === 'all' ? undefined : kycFilter.value,
      connected: connectedFilter.value === 'all' ? undefined : connectedFilter.value === 'yes',
      page: page.value,
      per_page: perPage.value,
    })
    if (seq !== requestSeq) return
    // The table must know the real row count BEFORE the page moves: with
    // `items-length` still 0 it would clamp the new page to 1 on its own.
    totalItems.value = res.meta.total
    // A stale link (`?page=9` after the roster shrank): go to the last page the
    // server reports. The watcher below refetches once; the empty response is
    // never rendered.
    if (exceedsLastPage(page.value, res.meta.last_page)) {
      page.value = res.meta.last_page
      return
    }
    items.value = res.data
  } catch (error) {
    if (seq !== requestSeq) return
    errorKey.value = error instanceof ApiError ? error.code : 'admin.creators.all.load_failed'
  } finally {
    if (seq === requestSeq) loading.value = false
  }
}

onMounted(() => {
  void load()
})

watch([page, perPage, statusFilter, kycFilter, connectedFilter], () => {
  void load()
})

function onTableUpdate(opts: { page: number; itemsPerPage: number }): void {
  // No echo guard and no direct `load()` (see the header): the watcher above
  // is the only load trigger.
  page.value = opts.page
  perPage.value = opts.itemsPerPage
}

function goToDetail(ulid: string): void {
  void router.push({ name: 'app.creators.detail', params: { ulid } })
}
</script>

<template>
  <section data-testid="admin-all-creators">
    <header class="d-flex align-center justify-space-between mb-4">
      <h1 class="text-h5 ma-0">{{ t('admin.creators.all.title') }}</h1>
    </header>

    <div class="mb-4">
      <v-chip-group
        v-model="statusFilter"
        mandatory
        class="admin-all-creators__filter-row"
        data-testid="admin-all-creators-filter-status"
      >
        <v-chip
          v-for="item in statusFilterItems"
          :key="item.value"
          :value="item.value"
          filter
          variant="outlined"
          :data-testid="`admin-all-creators-filter-status-${item.value}`"
        >
          {{ item.label }}
        </v-chip>
      </v-chip-group>

      <v-chip-group
        v-model="kycFilter"
        mandatory
        class="admin-all-creators__filter-row"
        data-testid="admin-all-creators-filter-kyc"
      >
        <v-chip
          v-for="item in kycFilterItems"
          :key="item.value"
          :value="item.value"
          filter
          variant="outlined"
          :data-testid="`admin-all-creators-filter-kyc-${item.value}`"
        >
          {{ item.label }}
        </v-chip>
      </v-chip-group>

      <v-chip-group
        v-model="connectedFilter"
        mandatory
        class="admin-all-creators__filter-row"
        data-testid="admin-all-creators-filter-connected"
      >
        <v-chip
          v-for="item in connectedFilterItems"
          :key="item.value"
          :value="item.value"
          filter
          variant="outlined"
          :data-testid="`admin-all-creators-filter-connected-${item.value}`"
        >
          {{ item.label }}
        </v-chip>
      </v-chip-group>
    </div>

    <v-alert
      v-if="errorKey"
      type="error"
      variant="tonal"
      class="mb-4"
      data-testid="admin-all-creators-error"
    >
      {{ t(errorKey) }}
    </v-alert>

    <v-data-table-server
      :headers="headers"
      :items="items"
      :items-length="totalItems"
      :loading="loading"
      :items-per-page="perPage"
      :items-per-page-options="PER_PAGE_OPTIONS"
      :page="page"
      item-value="id"
      :no-data-text="t('admin.creators.all.empty')"
      data-testid="admin-all-creators-table"
      @update:options="onTableUpdate"
    >
      <template #item.attributes.display_name="{ item }">
        <button
          type="button"
          class="admin-all-creators__name-link"
          :data-testid="`admin-all-creators-name-${item.id}`"
          @click="goToDetail(item.id)"
        >
          {{ creatorLabel(item.attributes, t, t('admin.creators.list.unnamed')) }}
        </button>
        <DeletionRequestedChip :account="item.attributes" />
      </template>

      <template #item.attributes.email="{ item }">
        {{ creatorEmail(item.attributes) ?? '—' }}
      </template>

      <template #item.attributes.application_status="{ item }">
        <v-chip size="small" variant="tonal">
          {{ t(`admin.creators.list.status_labels.${item.attributes.application_status}`) }}
        </v-chip>
      </template>

      <template #item.attributes.kyc_status="{ item }">
        {{ t(`creator.ui.wizard.steps.kyc.status_labels.${item.attributes.kyc_status}`) }}
      </template>

      <template #item.actions="{ item }">
        <v-btn
          icon="mdi-eye-outline"
          size="small"
          variant="text"
          :aria-label="t('admin.creators.list.view')"
          :data-testid="`admin-all-creators-view-${item.id}`"
          @click="goToDetail(item.id)"
        />
      </template>
    </v-data-table-server>
  </section>
</template>

<style scoped>
.admin-all-creators__filter-row {
  display: block;
}

.admin-all-creators__name-link {
  background: none;
  border: none;
  padding: 0;
  color: rgb(var(--v-theme-primary));
  cursor: pointer;
  font: inherit;
  text-align: left;
}

.admin-all-creators__name-link:hover {
  text-decoration: underline;
}
</style>
