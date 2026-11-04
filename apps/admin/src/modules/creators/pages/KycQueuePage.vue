<script setup lang="ts">
/**
 * Admin KYC review queue (Sprint 13, D-4).
 *
 * DISTINCT from the application-approval queue (CreatorListPage): this
 * surface filters on the orthogonal `?kyc_status=` axis (default
 * `pending`), serving identity-clearance triage. Same backend endpoint
 * (GET /admin/creators) with the KYC filter param. The pending count
 * feeds the sidebar `kycQueue` nav badge.
 *
 * AH-117: page, page size and the KYC chip live in the URL query
 * (`useListQueryState`), so the detail drill-in's browser back lands on the
 * same page of the same filter. One watcher over the three refs is the only
 * load trigger (the table's mount echo writes equal values and changes nothing).
 */

import { onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import { formatDateTime, ApiError } from '@catalyst/api-client'
import type { CreatorKycStatus } from '@catalyst/api-client'

import { useNavBadges } from '@/core/stores/useNavBadges'

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

type KycFilter = CreatorKycStatus | 'all'

const { t, locale } = useI18n()
const router = useRouter()
const navBadges = useNavBadges()

const KYC_FILTERS = [
  'pending',
  'verified',
  'rejected',
  'none',
  'all',
] as const satisfies readonly KycFilter[]
const PER_PAGE_OPTIONS = [10, 25, 50, 100]

const {
  page,
  per_page: perPage,
  kyc_status: kycFilter,
} = useListQueryState({
  page: pageParam,
  per_page: perPageParam(PER_PAGE_OPTIONS, 25),
  kyc_status: oneOfParamWithFallback<KycFilter>(KYC_FILTERS, 'pending'),
})

const items = ref<AdminCreatorListItem[]>([])
const totalItems = ref(0)
const loading = ref(false)
const errorKey = ref<string | null>(null)

const headers = [
  { title: t('admin.creators.list.fields.name'), key: 'attributes.display_name', sortable: false },
  { title: t('admin.creators.list.fields.email'), key: 'attributes.email', sortable: false },
  {
    title: t('admin.creators.list.fields.kyc'),
    key: 'attributes.kyc_status',
    sortable: false,
    width: 140,
  },
  {
    title: t('admin.creators.list.fields.submitted_at'),
    key: 'attributes.submitted_at',
    sortable: false,
    width: 160,
  },
  { title: '', key: 'actions', sortable: false, width: 80, align: 'end' as const },
]

const kycFilterItems: { label: string; value: KycFilter }[] = [
  { label: t('admin.creators.kyc.filters.pending'), value: 'pending' },
  { label: t('admin.creators.kyc.filters.verified'), value: 'verified' },
  { label: t('admin.creators.kyc.filters.rejected'), value: 'rejected' },
  { label: t('admin.creators.kyc.filters.none'), value: 'none' },
  { label: t('admin.creators.kyc.filters.all'), value: 'all' },
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
      kyc_status: kycFilter.value === 'all' ? undefined : kycFilter.value,
      page: page.value,
      per_page: perPage.value,
    })
    if (seq !== requestSeq) return
    if (kycFilter.value === 'pending') {
      navBadges.setCounts({ kycQueue: res.meta.total })
    }
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
    errorKey.value = error instanceof ApiError ? error.code : 'admin.creators.kyc.load_failed'
  } finally {
    if (seq === requestSeq) loading.value = false
  }
}

onMounted(() => {
  void load()
})

watch([page, perPage, kycFilter], () => {
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

function formatDate(iso: string | null): string {
  return formatDateTime(iso, locale.value, { dateStyle: 'medium' })
}
</script>

<template>
  <section data-testid="admin-kyc-queue">
    <header class="d-flex align-center justify-space-between mb-4">
      <h1 class="text-h5 ma-0">{{ t('admin.creators.kyc.title') }}</h1>
    </header>

    <v-chip-group v-model="kycFilter" mandatory class="mb-4" data-testid="admin-kyc-queue-filter">
      <v-chip
        v-for="item in kycFilterItems"
        :key="item.value"
        :value="item.value"
        filter
        variant="outlined"
        :data-testid="`admin-kyc-queue-filter-${item.value}`"
      >
        {{ item.label }}
      </v-chip>
    </v-chip-group>

    <v-alert
      v-if="errorKey"
      type="error"
      variant="tonal"
      class="mb-4"
      data-testid="admin-kyc-queue-error"
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
      :no-data-text="t('admin.creators.kyc.empty')"
      data-testid="admin-kyc-queue-table"
      @update:options="onTableUpdate"
    >
      <template #item.attributes.display_name="{ item }">
        <button
          type="button"
          class="admin-kyc-queue__name-link"
          :data-testid="`admin-kyc-queue-name-${item.id}`"
          @click="goToDetail(item.id)"
        >
          {{ creatorLabel(item.attributes, t, t('admin.creators.list.unnamed')) }}
        </button>
        <DeletionRequestedChip :account="item.attributes" />
      </template>

      <template #item.attributes.email="{ item }">
        {{ creatorEmail(item.attributes) ?? '—' }}
      </template>

      <template #item.attributes.kyc_status="{ item }">
        <v-chip size="small" variant="tonal" :data-testid="`admin-kyc-queue-status-${item.id}`">
          {{ t(`creator.ui.wizard.steps.kyc.status_labels.${item.attributes.kyc_status}`) }}
        </v-chip>
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
          :data-testid="`admin-kyc-queue-view-${item.id}`"
          @click="goToDetail(item.id)"
        />
      </template>
    </v-data-table-server>
  </section>
</template>

<style scoped>
.admin-kyc-queue__name-link {
  background: none;
  border: none;
  padding: 0;
  color: rgb(var(--v-theme-primary));
  cursor: pointer;
  font: inherit;
  text-align: left;
}

.admin-kyc-queue__name-link:hover {
  text-decoration: underline;
}
</style>
