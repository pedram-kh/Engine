<script setup lang="ts">
/**
 * Admin KYC review queue (Sprint 13, D-4).
 *
 * DISTINCT from the application-approval queue (CreatorListPage): this
 * surface filters on the orthogonal `?kyc_status=` axis (default
 * `pending`), serving identity-clearance triage. Same backend endpoint
 * (GET /admin/creators) with the KYC filter param. The pending count
 * feeds the sidebar `kycQueue` nav badge.
 */

import { onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import { ApiError } from '@catalyst/api-client'
import type { CreatorKycStatus } from '@catalyst/api-client'

import { useNavBadges } from '@/core/stores/useNavBadges'

import { adminCreatorsApi, type AdminCreatorListItem } from '../api/creators.api'

type KycFilter = CreatorKycStatus | 'all'

const { t } = useI18n()
const router = useRouter()
const navBadges = useNavBadges()

const kycFilter = ref<KycFilter>('pending')
const items = ref<AdminCreatorListItem[]>([])
const totalItems = ref(0)
const loading = ref(false)
const errorKey = ref<string | null>(null)

const tableOptions = ref({ page: 1, itemsPerPage: 25 })

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

async function load(): Promise<void> {
  loading.value = true
  errorKey.value = null
  try {
    const res = await adminCreatorsApi.list({
      kyc_status: kycFilter.value === 'all' ? undefined : kycFilter.value,
      page: tableOptions.value.page,
      per_page: tableOptions.value.itemsPerPage,
    })
    items.value = res.data
    totalItems.value = res.meta.total
    if (kycFilter.value === 'pending') {
      navBadges.setCounts({ kycQueue: res.meta.total })
    }
  } catch (error) {
    errorKey.value = error instanceof ApiError ? error.code : 'admin.creators.kyc.load_failed'
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  void load()
})

watch(kycFilter, () => {
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

function formatDate(iso: string | null): string {
  return iso === null ? '—' : new Date(iso).toLocaleDateString()
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
      :items-per-page="tableOptions.itemsPerPage"
      :page="tableOptions.page"
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
          {{ item.attributes.display_name ?? t('admin.creators.list.unnamed') }}
        </button>
      </template>

      <template #item.attributes.email="{ item }">
        {{ item.attributes.email ?? '—' }}
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
