<script setup lang="ts">
/**
 * Admin creator review queue — Sprint 4 Chunk 3 (Cluster 3).
 *
 * Server-side paginated table consuming GET /api/v1/admin/creators with
 * an application_status filter. Creators are a global entity — the admin
 * sees all (no agency tenancy on this list). Mirrors the main SPA's
 * BrandListPage v-data-table-server pattern (onMounted + @update:options
 * + page/per_page). Click-through navigates to the existing creator
 * detail drill-in.
 *
 * AH-117: the browse context (page, page size, status chip) lives in the URL
 * query (`useListQueryState`), so the detail drill-in's browser back lands on
 * the same page of the same filter. One watcher over the three refs is the
 * only load trigger; the table's own `update:options` is ignored when it just
 * echoes what the refs already hold.
 *
 * Gated by `requireAuth` + `requireMfaEnrolled` (the backend enforces
 * platform_admin via CreatorPolicy::viewAny).
 */

import { onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import { formatDateTime, ApiError } from '@catalyst/api-client'
import type { CreatorApplicationStatus } from '@catalyst/api-client'

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

const { t, locale } = useI18n()
const router = useRouter()

const STATUS_FILTERS = [
  'pending',
  'approved',
  'rejected',
  'incomplete',
  'all',
] as const satisfies readonly StatusFilter[]
const PER_PAGE_OPTIONS = [10, 25, 50, 100]

const {
  page,
  per_page: perPage,
  status: statusFilter,
} = useListQueryState({
  page: pageParam,
  per_page: perPageParam(PER_PAGE_OPTIONS, 25),
  status: oneOfParamWithFallback<StatusFilter>(STATUS_FILTERS, 'pending'),
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
  {
    title: t('admin.creators.list.fields.completeness'),
    key: 'attributes.profile_completeness_score',
    sortable: false,
    width: 130,
  },
  {
    title: t('admin.creators.list.fields.submitted_at'),
    key: 'attributes.submitted_at',
    sortable: false,
    width: 160,
  },
  { title: '', key: 'actions', sortable: false, width: 80, align: 'end' as const },
]

const statusFilterItems: { label: string; value: StatusFilter }[] = [
  { label: t('admin.creators.list.filters.pending'), value: 'pending' },
  { label: t('admin.creators.list.filters.approved'), value: 'approved' },
  { label: t('admin.creators.list.filters.rejected'), value: 'rejected' },
  { label: t('admin.creators.list.filters.incomplete'), value: 'incomplete' },
  { label: t('admin.creators.list.filters.all'), value: 'all' },
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
      page: page.value,
      per_page: perPage.value,
    })
    if (seq !== requestSeq) return
    // The table must know the real row count BEFORE the page moves: with
    // `items-length` still 0 it would clamp the new page to 1 on its own.
    totalItems.value = res.meta.total
    // A stale link (`?page=9` after the queue shrank): go to the last page the
    // server reports. The watcher below refetches once; the empty response is
    // never rendered.
    if (exceedsLastPage(page.value, res.meta.last_page)) {
      page.value = res.meta.last_page
      return
    }
    items.value = res.data
  } catch (error) {
    if (seq !== requestSeq) return
    errorKey.value = error instanceof ApiError ? error.code : 'admin.creators.list.load_failed'
  } finally {
    if (seq === requestSeq) loading.value = false
  }
}

onMounted(() => {
  void load()
})

watch([page, perPage, statusFilter], () => {
  void load()
})

function onTableUpdate(opts: { page: number; itemsPerPage: number }): void {
  // No echo guard and no direct `load()`: the table echoes the controlled page
  // and size back on mount, and writing equal values into the refs changes
  // nothing, so the watcher above (the only load trigger) stays quiet. Calling
  // `load()` from here is what would make a second request on mount.
  page.value = opts.page
  perPage.value = opts.itemsPerPage
}

function goToDetail(ulid: string): void {
  void router.push({ name: 'app.creators.detail', params: { ulid } })
}

function formatDate(iso: string | null): string {
  return formatDateTime(iso, locale.value, { dateStyle: 'medium' })
}
</script>

<template>
  <section data-testid="admin-creator-list">
    <header class="d-flex align-center justify-space-between mb-4">
      <h1 class="text-h5 ma-0">{{ t('admin.creators.list.title') }}</h1>
    </header>

    <v-chip-group
      v-model="statusFilter"
      mandatory
      class="mb-4"
      data-testid="admin-creator-list-filter"
    >
      <v-chip
        v-for="item in statusFilterItems"
        :key="item.value"
        :value="item.value"
        filter
        variant="outlined"
        :data-testid="`admin-creator-list-filter-${item.value}`"
      >
        {{ item.label }}
      </v-chip>
    </v-chip-group>

    <v-alert
      v-if="errorKey"
      type="error"
      variant="tonal"
      class="mb-4"
      data-testid="admin-creator-list-error"
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
      data-testid="admin-creator-list-table"
      @update:options="onTableUpdate"
    >
      <template #item.attributes.display_name="{ item }">
        <button
          type="button"
          class="admin-creator-list__name-link"
          :data-testid="`admin-creator-list-name-${item.id}`"
          @click="goToDetail(item.id)"
        >
          {{ creatorLabel(item.attributes, t, t('admin.creators.list.unnamed')) }}
        </button>
        <DeletionRequestedChip :account="item.attributes" />
      </template>

      <template #item.attributes.email="{ item }">
        <span :data-testid="`admin-creator-list-email-${item.id}`">
          {{ creatorEmail(item.attributes) ?? '—' }}
        </span>
      </template>

      <template #item.attributes.application_status="{ item }">
        <v-chip size="small" variant="tonal" :data-testid="`admin-creator-list-status-${item.id}`">
          {{ t(`admin.creators.list.status_labels.${item.attributes.application_status}`) }}
        </v-chip>
      </template>

      <template #item.attributes.kyc_status="{ item }">
        {{ t(`creator.ui.wizard.steps.kyc.status_labels.${item.attributes.kyc_status}`) }}
      </template>

      <template #item.attributes.profile_completeness_score="{ item }">
        {{
          t('admin.creators.detail.completeness', {
            percent: item.attributes.profile_completeness_score,
          })
        }}
      </template>

      <template #item.attributes.submitted_at="{ item }">
        {{ formatDate(item.attributes.submitted_at) }}
      </template>

      <template #item.actions="{ item }">
        <v-btn
          icon="mdi-eye-outline"
          size="small"
          variant="text"
          :aria-label="t('admin.creators.list.view')"
          :data-testid="`admin-creator-list-view-${item.id}`"
          @click="goToDetail(item.id)"
        />
      </template>
    </v-data-table-server>
  </section>
</template>

<style scoped>
.admin-creator-list__name-link {
  background: none;
  border: none;
  padding: 0;
  color: rgb(var(--v-theme-primary));
  cursor: pointer;
  font: inherit;
  text-align: left;
}

.admin-creator-list__name-link:hover {
  text-decoration: underline;
}
</style>
