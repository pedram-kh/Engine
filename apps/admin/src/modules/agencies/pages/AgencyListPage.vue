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
 *
 * AH-117: page, page size, the status chip and the APPLIED search term live in
 * the URL query (`useListQueryState`), so the detail drill-in's browser back
 * lands on the same page of the same search. The text field holds a DRAFT
 * (`searchInput`); the term is applied to `q` (and so to the URL and the
 * request) 300 ms after the last keystroke. One watcher over the applied
 * values is the only load trigger.
 */

import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import { formatDateTime, ApiError } from '@catalyst/api-client'

import {
  exceedsLastPage,
  oneOfParamWithFallback,
  pageParam,
  perPageParam,
  textParam,
  useListQueryState,
} from '@/composables/useListQueryState'

import { adminAgenciesApi, type AdminAgency, type AgencyStatusFilter } from '../api/agencies.api'

const { t, locale } = useI18n()
const router = useRouter()

const STATUS_FILTERS = [
  'all',
  'active',
  'suspended',
] as const satisfies readonly AgencyStatusFilter[]
const PER_PAGE_OPTIONS = [10, 25, 50, 100]

const {
  page,
  per_page: perPage,
  status: statusFilter,
  q,
} = useListQueryState({
  page: pageParam,
  per_page: perPageParam(PER_PAGE_OPTIONS, 25),
  status: oneOfParamWithFallback<AgencyStatusFilter>(STATUS_FILTERS, 'all'),
  q: textParam,
})

// The text field's draft; `q` is the applied term.
const searchInput = ref<string | null>(q.value)
// Vuetify's `clearable` text field sets the model to `null` (not '') when the
// clear button is pressed, so everything compares through this coercion.
const appliedSearch = computed(() => (q.value ?? '').trim())
const items = ref<AdminAgency[]>([])
const totalItems = ref(0)
const loading = ref(false)
const errorKey = ref<string | null>(null)

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

// A response that is no longer the newest request is dropped (a slow page 1
// must not overwrite a fast page 2).
let requestSeq = 0

async function load(): Promise<void> {
  const seq = ++requestSeq
  loading.value = true
  errorKey.value = null
  try {
    const res = await adminAgenciesApi.list({
      status: statusFilter.value === 'all' ? undefined : statusFilter.value,
      search: appliedSearch.value === '' ? undefined : appliedSearch.value,
      page: page.value,
      per_page: perPage.value,
    })
    if (seq !== requestSeq) return
    // The table must know the real row count BEFORE the page moves: with
    // `items-length` still 0 it would clamp the new page to 1 on its own.
    totalItems.value = res.meta.total
    // A stale link (`?page=9` after the list shrank): go to the last page the
    // server reports. The watcher below refetches once; the empty response is
    // never rendered.
    if (exceedsLastPage(page.value, res.meta.last_page)) {
      page.value = res.meta.last_page
      return
    }
    items.value = res.data
  } catch (error) {
    if (seq !== requestSeq) return
    errorKey.value = error instanceof ApiError ? error.code : 'admin.agencies.list.load_failed'
  } finally {
    if (seq === requestSeq) loading.value = false
  }
}

onMounted(() => {
  void load()
})

watch([page, perPage, statusFilter, appliedSearch], () => {
  void load()
})

// Draft → applied: the term reaches `q` (the URL and the request) 300 ms after
// the last keystroke. `q` is read through `appliedSearch` (trimmed), so a
// trailing space, or clearing an already empty field, changes nothing that
// the URL or the request can see.
let searchDebounce: ReturnType<typeof setTimeout> | null = null
watch(searchInput, () => {
  if (searchDebounce !== null) clearTimeout(searchDebounce)
  searchDebounce = setTimeout(() => {
    q.value = searchInput.value
  }, 300)
})

// Applied → draft: the route re-seeded `q` (a bare sidebar URL, a pasted
// link), so the text field follows. Keystrokes still waiting on the pause are
// superseded with no extra code: the pending timer reads the field when it
// fires, and the field now holds the re-seeded term.
watch(appliedSearch, (applied) => {
  if ((searchInput.value ?? '').trim() === applied) return
  searchInput.value = q.value ?? ''
})

function onTableUpdate(opts: { page: number; itemsPerPage: number }): void {
  // No echo guard and no direct `load()` (see the header): the watcher above
  // is the only load trigger.
  page.value = opts.page
  perPage.value = opts.itemsPerPage
}

function goToDetail(ulid: string): void {
  void router.push({ name: 'app.agencies.detail', params: { ulid } })
}

function formatDate(iso: string): string {
  return formatDateTime(iso, locale.value, { dateStyle: 'medium' })
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
        v-model="searchInput"
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
      :items-per-page="perPage"
      :items-per-page-options="PER_PAGE_OPTIONS"
      :page="page"
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
