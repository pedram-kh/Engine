<script setup lang="ts">
/**
 * Campaign list page (Sprint 8 Chunk 1) — server-side paginated table with
 * brand / status / date filters. Mirrors BrandListPage. Any agency member may
 * view; the Create button is shown to all but the backend gates create to
 * admin/manager (a staff member's POST 403s).
 */

import { formatCurrency } from '@catalyst/api-client'
import type { BrandResource, CampaignListParams, CampaignResource } from '@catalyst/api-client'
import { onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { CEmptyState } from '@catalyst/ui'

import { useAgencyStore } from '@/core/stores/useAgencyStore'
import { brandsApi } from '@/modules/brands/api/brands.api'
import { campaignsApi } from '../api/campaigns.api'

const { t, locale } = useI18n()
const agencyStore = useAgencyStore()

type StatusFilter = 'all' | 'draft' | 'active' | 'paused' | 'completed' | 'cancelled'

const statusFilter = ref<StatusFilter>('all')
const brandFilter = ref<string | null>(null)
const startsFrom = ref<string>('')
const startsTo = ref<string>('')

const items = ref<CampaignResource[]>([])
const totalItems = ref(0)
const loading = ref(false)
const error = ref<string | null>(null)
const brandOptions = ref<{ title: string; value: string }[]>([])

const tableOptions = ref({ page: 1, itemsPerPage: 25 })

const headers = [
  { title: t('app.campaigns.fields.name'), key: 'attributes.name', sortable: false },
  { title: t('app.campaigns.fields.brand'), key: 'relationships.brand.data.name', sortable: false },
  {
    title: t('app.campaigns.fields.status'),
    key: 'attributes.status',
    sortable: false,
    width: 120,
  },
  {
    title: t('app.campaigns.fields.budget'),
    key: 'attributes.budget_minor_units',
    sortable: false,
    width: 140,
  },
  { title: '', key: 'actions', sortable: false, width: 80, align: 'end' as const },
]

const statusFilterItems: { label: string; value: StatusFilter }[] = [
  { label: t('app.campaigns.status.all'), value: 'all' },
  { label: t('app.campaigns.status.draft'), value: 'draft' },
  { label: t('app.campaigns.status.active'), value: 'active' },
  { label: t('app.campaigns.status.paused'), value: 'paused' },
  { label: t('app.campaigns.status.completed'), value: 'completed' },
  { label: t('app.campaigns.status.cancelled'), value: 'cancelled' },
]

const statusColor: Record<string, string> = {
  draft: 'default',
  active: 'success',
  paused: 'warning',
  completed: 'info',
  cancelled: 'error',
}

async function loadBrandOptions(): Promise<void> {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null) return
  try {
    const res = await brandsApi.list(agencyId, { per_page: 100, status: 'active' })
    brandOptions.value = res.data.map((b: BrandResource) => ({
      title: b.attributes.name,
      value: b.id,
    }))
  } catch {
    // The brand filter is a convenience; failing to populate it is non-fatal.
    brandOptions.value = []
  }
}

async function loadCampaigns(): Promise<void> {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null) return

  loading.value = true
  error.value = null

  const params: CampaignListParams = {
    page: tableOptions.value.page,
    per_page: tableOptions.value.itemsPerPage,
  }
  if (statusFilter.value !== 'all') params.status = statusFilter.value
  if (brandFilter.value) params.brand = brandFilter.value
  if (startsFrom.value) params.starts_from = startsFrom.value
  if (startsTo.value) params.starts_to = startsTo.value

  try {
    const res = await campaignsApi.list(agencyId, params)
    items.value = res.data
    totalItems.value = res.meta.total
  } catch {
    error.value = t('app.campaigns.errors.loadFailed')
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  void loadBrandOptions()
  void loadCampaigns()
})

watch(
  () => agencyStore.currentAgencyId,
  (id) => {
    if (id !== null) {
      void loadBrandOptions()
      void loadCampaigns()
    }
  },
)

watch([statusFilter, brandFilter, startsFrom, startsTo], () => {
  tableOptions.value.page = 1
  void loadCampaigns()
})

function onTableUpdate(opts: { page: number; itemsPerPage: number }): void {
  tableOptions.value = opts
  void loadCampaigns()
}

function formatMoney(minor: number | null, currency: string | null): string {
  return formatCurrency(minor, currency, locale.value)
}
</script>

<template>
  <div data-test="campaign-list-page">
    <div class="d-flex align-center justify-space-between mb-4">
      <h1 class="text-h5 ma-0" data-test="campaign-list-heading">{{ t('app.campaigns.title') }}</h1>
      <v-btn
        color="primary"
        prepend-icon="mdi-plus"
        :to="{ name: 'campaigns.create' }"
        data-test="campaign-create-btn"
      >
        {{ t('app.campaigns.actions.create') }}
      </v-btn>
    </div>

    <!-- Filters -->
    <div class="d-flex flex-wrap align-center ga-3 mb-4" data-test="campaign-filters">
      <v-select
        v-model="brandFilter"
        :items="brandOptions"
        :label="t('app.campaigns.filters.brand')"
        item-title="title"
        item-value="value"
        density="compact"
        variant="outlined"
        hide-details
        clearable
        style="max-width: 220px"
        data-test="campaign-filter-brand"
      />
      <v-text-field
        v-model="startsFrom"
        :label="t('app.campaigns.filters.startsFrom')"
        type="date"
        density="compact"
        variant="outlined"
        hide-details
        style="max-width: 190px"
        data-test="campaign-filter-starts-from"
      />
      <v-text-field
        v-model="startsTo"
        :label="t('app.campaigns.filters.startsTo')"
        type="date"
        density="compact"
        variant="outlined"
        hide-details
        style="max-width: 190px"
        data-test="campaign-filter-starts-to"
      />
    </div>

    <v-chip-group v-model="statusFilter" mandatory class="mb-4" data-test="campaign-status-filter">
      <v-chip
        v-for="item in statusFilterItems"
        :key="item.value"
        :value="item.value"
        filter
        variant="outlined"
        :data-test="`campaign-filter-${item.value}`"
      >
        {{ item.label }}
      </v-chip>
    </v-chip-group>

    <v-alert v-if="error" type="error" variant="tonal" class="mb-4" data-test="campaign-list-error">
      {{ error }}
    </v-alert>

    <template v-if="loading && items.length === 0">
      <v-skeleton-loader type="table" data-test="campaign-list-skeleton" />
    </template>

    <template v-else-if="!loading && items.length === 0 && !error">
      <CEmptyState
        data-test="campaign-empty-state"
        title-tag="h2"
        :title="t('app.campaigns.empty.heading')"
        :body="t('app.campaigns.empty.body')"
      >
        <template #icon>
          <v-icon icon="mdi-bullhorn-outline" size="64" color="medium-emphasis" />
        </template>
        <template #action>
          <v-btn color="primary" :to="{ name: 'campaigns.create' }" data-test="campaign-empty-cta">
            {{ t('app.campaigns.empty.cta') }}
          </v-btn>
        </template>
      </CEmptyState>
    </template>

    <v-data-table-server
      v-else
      :headers="headers"
      :items="items"
      :items-length="totalItems"
      :loading="loading"
      :items-per-page="tableOptions.itemsPerPage"
      :page="tableOptions.page"
      item-value="id"
      data-test="campaign-table"
      @update:options="onTableUpdate"
    >
      <template #item.relationships.brand.data.name="{ item }">
        {{ item.relationships.brand.data.name }}
      </template>

      <template #item.attributes.status="{ item }">
        <v-chip
          :color="statusColor[item.attributes.status] ?? 'default'"
          size="small"
          variant="tonal"
          :data-test="`campaign-status-${item.id}`"
        >
          {{ t(`app.campaigns.status.${item.attributes.status}`) }}
        </v-chip>
      </template>

      <template #item.attributes.budget_minor_units="{ item }">
        {{ formatMoney(item.attributes.budget_minor_units, item.attributes.budget_currency) }}
      </template>

      <template #item.actions="{ item }">
        <v-btn
          icon="mdi-eye-outline"
          size="small"
          variant="text"
          :to="{ name: 'campaigns.detail', params: { ulid: item.id } }"
          :aria-label="t('app.campaigns.detail.title')"
          :data-test="`campaign-view-${item.id}`"
        />
      </template>
    </v-data-table-server>
  </div>
</template>
