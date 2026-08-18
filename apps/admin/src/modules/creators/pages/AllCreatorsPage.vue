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
 * copy. No URL-state persistence: AH-076 (browse-state) never reached
 * admin, and this page — like its two siblings — has never round-tripped
 * page/filter state through the URL, so this doesn't start now.
 */

import { onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import { ApiError } from '@catalyst/api-client'
import type { CreatorApplicationStatus, CreatorKycStatus } from '@catalyst/api-client'

import { adminCreatorsApi, type AdminCreatorListItem } from '../api/creators.api'

type StatusFilter = CreatorApplicationStatus | 'all'
type KycFilter = CreatorKycStatus | 'all'
type ConnectedFilter = 'yes' | 'no' | 'all'

const { t } = useI18n()
const router = useRouter()

const statusFilter = ref<StatusFilter>('all')
const kycFilter = ref<KycFilter>('all')
const connectedFilter = ref<ConnectedFilter>('all')

const items = ref<AdminCreatorListItem[]>([])
const totalItems = ref(0)
const loading = ref(false)
const errorKey = ref<string | null>(null)

const tableOptions = ref({ page: 1, itemsPerPage: 25 })

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

async function load(): Promise<void> {
  loading.value = true
  errorKey.value = null
  try {
    const res = await adminCreatorsApi.list({
      status: statusFilter.value === 'all' ? undefined : statusFilter.value,
      kyc_status: kycFilter.value === 'all' ? undefined : kycFilter.value,
      connected: connectedFilter.value === 'all' ? undefined : connectedFilter.value === 'yes',
      page: tableOptions.value.page,
      per_page: tableOptions.value.itemsPerPage,
    })
    items.value = res.data
    totalItems.value = res.meta.total
  } catch (error) {
    errorKey.value = error instanceof ApiError ? error.code : 'admin.creators.all.load_failed'
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  void load()
})

watch([statusFilter, kycFilter, connectedFilter], () => {
  tableOptions.value.page = 1
  void load()
})

function onTableUpdate(opts: { page: number; itemsPerPage: number }): void {
  tableOptions.value = opts
  void load()
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
      :items-per-page="tableOptions.itemsPerPage"
      :page="tableOptions.page"
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
          {{ item.attributes.display_name ?? t('admin.creators.list.unnamed') }}
        </button>
      </template>

      <template #item.attributes.email="{ item }">
        {{ item.attributes.email ?? '—' }}
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
