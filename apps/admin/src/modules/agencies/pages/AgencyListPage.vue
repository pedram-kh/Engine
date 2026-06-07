<script setup lang="ts">
/**
 * Admin agency list (Sprint 13, D-3).
 *
 * Server-side paginated table consuming GET /api/v1/admin/agencies with a
 * status filter (all / active / suspended) and a name search. Mirrors
 * CreatorListPage's v-data-table-server pattern. Click-through opens the
 * agency detail drill-in where suspend / reactivate live.
 *
 * Cross-agency by design — the backend enforces the platform_admin
 * bounded bypass; this page just renders what it returns.
 */

import { onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import { ApiError } from '@catalyst/api-client'

import { adminAgenciesApi, type AdminAgency, type AgencyStatusFilter } from '../api/agencies.api'

const { t } = useI18n()
const router = useRouter()

const statusFilter = ref<AgencyStatusFilter>('all')
const search = ref('')
const items = ref<AdminAgency[]>([])
const totalItems = ref(0)
const loading = ref(false)
const errorKey = ref<string | null>(null)

const tableOptions = ref({ page: 1, itemsPerPage: 25 })

const headers = [
  { title: t('admin.agencies.list.fields.name'), key: 'attributes.name', sortable: false },
  {
    title: t('admin.agencies.list.fields.status'),
    key: 'attributes.is_suspended',
    sortable: false,
    width: 140,
  },
  {
    title: t('admin.agencies.list.fields.members'),
    key: 'attributes.member_count',
    sortable: false,
    width: 110,
  },
  {
    title: t('admin.agencies.list.fields.tier'),
    key: 'attributes.subscription_tier',
    sortable: false,
    width: 130,
  },
  {
    title: t('admin.agencies.list.fields.created_at'),
    key: 'attributes.created_at',
    sortable: false,
    width: 150,
  },
  { title: '', key: 'actions', sortable: false, width: 70, align: 'end' as const },
]

const statusFilterItems: { label: string; value: AgencyStatusFilter }[] = [
  { label: t('admin.agencies.list.filters.all'), value: 'all' },
  { label: t('admin.agencies.list.filters.active'), value: 'active' },
  { label: t('admin.agencies.list.filters.suspended'), value: 'suspended' },
]

async function load(): Promise<void> {
  loading.value = true
  errorKey.value = null
  try {
    const res = await adminAgenciesApi.list({
      status: statusFilter.value === 'all' ? undefined : statusFilter.value,
      search: search.value.trim() === '' ? undefined : search.value.trim(),
      page: tableOptions.value.page,
      per_page: tableOptions.value.itemsPerPage,
    })
    items.value = res.data
    totalItems.value = res.meta.total
  } catch (error) {
    errorKey.value = error instanceof ApiError ? error.code : 'admin.agencies.list.load_failed'
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  void load()
})

watch(statusFilter, () => {
  tableOptions.value.page = 1
  void load()
})

let searchDebounce: ReturnType<typeof setTimeout> | null = null
watch(search, () => {
  if (searchDebounce !== null) clearTimeout(searchDebounce)
  searchDebounce = setTimeout(() => {
    tableOptions.value.page = 1
    void load()
  }, 300)
})

function onTableUpdate(opts: { page: number; itemsPerPage: number }): void {
  tableOptions.value = opts
  void load()
}

function goToDetail(ulid: string): void {
  void router.push({ name: 'app.agencies.detail', params: { ulid } })
}

function formatDate(iso: string): string {
  return new Date(iso).toLocaleDateString()
}
</script>

<template>
  <section data-testid="admin-agency-list">
    <header class="d-flex align-center justify-space-between mb-4">
      <h1 class="text-h5 ma-0">{{ t('admin.agencies.list.title') }}</h1>
    </header>

    <div class="d-flex align-center ga-4 mb-4 flex-wrap">
      <v-chip-group v-model="statusFilter" mandatory data-testid="admin-agency-list-filter">
        <v-chip
          v-for="item in statusFilterItems"
          :key="item.value"
          :value="item.value"
          filter
          variant="outlined"
          :data-testid="`admin-agency-list-filter-${item.value}`"
        >
          {{ item.label }}
        </v-chip>
      </v-chip-group>

      <v-text-field
        v-model="search"
        :label="t('admin.agencies.list.search_label')"
        density="compact"
        variant="outlined"
        hide-details
        clearable
        prepend-inner-icon="mdi-magnify"
        style="max-width: 280px"
        data-testid="admin-agency-list-search"
      />
    </div>

    <v-alert
      v-if="errorKey"
      type="error"
      variant="tonal"
      class="mb-4"
      data-testid="admin-agency-list-error"
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
      :no-data-text="t('admin.agencies.list.empty')"
      data-testid="admin-agency-list-table"
      @update:options="onTableUpdate"
    >
      <template #item.attributes.name="{ item }">
        <button
          type="button"
          class="admin-agency-list__name-link"
          :data-testid="`admin-agency-list-name-${item.id}`"
          @click="goToDetail(item.id)"
        >
          {{ item.attributes.name }}
        </button>
      </template>

      <template #item.attributes.is_suspended="{ item }">
        <v-chip
          size="small"
          variant="tonal"
          :color="item.attributes.is_suspended ? 'error' : 'success'"
          :data-testid="`admin-agency-list-status-${item.id}`"
        >
          {{
            item.attributes.is_suspended
              ? t('admin.agencies.list.status_labels.suspended')
              : t('admin.agencies.list.status_labels.active')
          }}
        </v-chip>
      </template>

      <template #item.attributes.created_at="{ item }">
        {{ formatDate(item.attributes.created_at) }}
      </template>

      <template #item.actions="{ item }">
        <v-btn
          icon="mdi-eye-outline"
          size="small"
          variant="text"
          :aria-label="t('admin.agencies.list.view')"
          :data-testid="`admin-agency-list-view-${item.id}`"
          @click="goToDetail(item.id)"
        />
      </template>
    </v-data-table-server>
  </section>
</template>

<style scoped>
.admin-agency-list__name-link {
  background: none;
  border: none;
  padding: 0;
  color: rgb(var(--v-theme-primary));
  cursor: pointer;
  font: inherit;
  text-align: left;
}

.admin-agency-list__name-link:hover {
  text-decoration: underline;
}
</style>
